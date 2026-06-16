<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Best-effort server-side product-page importer for the "Remplir depuis URL" affordance.
 *
 * A user pastes a public product URL; the server fetches it and extracts a title,
 * price, currency and image (Open Graph / JSON-LD / <title>), converting the price
 * to EUR when a currency is detected. AliExpress is the initial target, but the
 * service degrades gracefully to a generic Open Graph fallback for any public
 * http(s) page — a blocked, JS-only or unparseable page is the EXPECTED common
 * outcome, not an error.
 *
 * SECURITY (headline control of this phase): fetching a user-supplied URL is an
 * SSRF vector. The guard mirrors the canonical fin1te/safecurl design — resolve
 * the host, validate EVERY resolved IP, pin curl to the validated IP (no
 * re-resolution → closes the DNS-rebinding TOCTOU window), disable FOLLOWLOCATION
 * and follow redirects manually re-running the full guard per hop, and allowlist
 * the http/https protocols only. The optional og:image fetch runs through the
 * SAME guard because the image URL is attacker-influenceable (D-05a).
 *
 * CONTRACT: this service NEVER throws into the request path. Every failure mode
 * (bad scheme, SSRF reject, non-200, empty body, unparseable HTML, FX failure)
 * is logged with error_log() and returned as a typed array with ok:false and a
 * French manual-entry message.
 */
final class ProductImportService
{
    /** Page fetch read timeout (seconds) — mirrors ExchangeRateService. */
    private const TIMEOUT = 5;
    /** Connection timeout (seconds). */
    private const CONNECT_TIMEOUT = 3;
    /** Hard cap on the fetched HTML body (bytes). */
    private const MAX_BODY_BYTES = 2000000;
    /** Hard cap on the optional preview image (bytes) — ~1.5 MB (D-05a). 1.5 * 1024 * 1024. */
    private const MAX_IMAGE_BYTES = 1572864;
    /** Maximum number of redirect hops to follow manually. */
    private const MAX_HOPS = 3;
    /**
     * Cloud-metadata / explicitly-denied IPs. 169.254.169.254 (AWS/GCP metadata)
     * is also caught by FILTER_FLAG_NO_RES_RANGE (link-local 169.254/16); listed
     * here as belt-and-braces defence-in-depth.
     */
    private const BLOCKED_IPS = ['169.254.169.254'];
    /** Realistic browser UA — improves the odds a page serves OG meta for unfurling. */
    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';
    /** French message returned whenever auto-fill cannot produce data. */
    private const MSG_UNAVAILABLE = 'Remplissage automatique indisponible — veuillez saisir manuellement.';

    /**
     * Orchestrate a best-effort import for a user-supplied URL.
     *
     * @return array{ok: bool, name?: ?string, price?: ?float, currency?: ?string,
     *               converted_eur?: ?float, image_url?: ?string, message?: string}
     */
    public function fromUrl(string $url): array
    {
        $url   = trim($url);
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true) || empty($parts['host'])) {
            return ['ok' => false, 'message' => 'URL invalide — utilisez une adresse http(s) publique.'];
        }

        $html = $this->fetch($url, self::MAX_HOPS);
        if ($html === null || $html === '') {
            return ['ok' => false, 'message' => self::MSG_UNAVAILABLE];
        }

        $data = $this->parse($html);
        if ($data['name'] === null && $data['price'] === null && $data['image_url'] === null) {
            return ['ok' => false, 'message' => self::MSG_UNAVAILABLE];
        }

        // Price → EUR (best-effort, never a silent mislabel — D-04).
        $rawPrice = $data['price'] !== null
            ? (float) str_replace(',', '.', $data['price'])
            : null;
        $currency = $data['currency'];
        $rate = ($rawPrice !== null && $currency !== null)
            ? (new ExchangeRateService())->latest($currency, 'EUR')
            : null;
        $converted = $this->convert($rawPrice, $currency, $rate);

        // Optional preview image through the SAME SSRF guard (D-05a).
        $imageDataUri = $data['image_url'] !== null
            ? $this->fetchImageDataUri($data['image_url'])
            : null;

        $message = $converted['needs_verify'] && $rawPrice !== null
            ? 'Champs pré-remplis — vérifiez la devise et le montant avant d’enregistrer.'
            : 'Champs pré-remplis — vérifiez avant d’enregistrer.';

        return [
            'ok'            => true,
            'name'          => $data['name'],
            'price'         => $converted['price'],
            'currency'      => $currency,
            'converted_eur' => $converted['converted_eur'],
            'image_url'     => $imageDataUri,
            'message'       => $message,
        ];
    }

    /**
     * SSRF allow/deny for a single resolved IP (pure, network-free).
     *
     * Returns true only when the IP is a routable public address. Rejects
     * private (10/8, 172.16/12, 192.168/16, fc00::/7), loopback (127/8, ::1),
     * link-local + reserved (169.254/16, fe80::/10) via the filter flags, plus
     * an explicit denial of the cloud-metadata address and the IPv4-mapped
     * IPv6 bypass (::ffff:127.0.0.1 style) which the filter flags miss.
     */
    public function isPublicIp(string $ip): bool
    {
        $ip = trim($ip);
        if ($ip === '') {
            return false;
        }
        // IPv4-mapped IPv6 (::ffff:a.b.c.d) embeds a v4 address the filter flags
        // do not range-check — deny the whole class outright.
        if (str_starts_with(strtolower($ip), '::ffff:')) {
            return false;
        }
        if (in_array($ip, self::BLOCKED_IPS, true)) {
            return false;
        }
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    /**
     * Extract product fields from an HTML document (pure, network-free).
     *
     * Order (D-01): embedded JSON blob (JSON-LD Product / window.runParams) first,
     * then Open Graph <meta>, then <title> for the name. Malformed HTML is parsed
     * with libxml internal errors enabled so no warning escapes; empty/garbage
     * input yields all-null keys and never throws.
     *
     * @return array{name: ?string, price: ?string, currency: ?string, image_url: ?string}
     */
    public function parse(string $html): array
    {
        $result = ['name' => null, 'price' => null, 'currency' => null, 'image_url' => null];
        if (trim($html) === '') {
            return $result;
        }

        // 1) Best-effort embedded JSON (AliExpress-first per D-01).
        $json = $this->parseEmbeddedJson($html);
        foreach ($result as $key => $_) {
            if ($json[$key] !== null) {
                $result[$key] = $json[$key];
            }
        }

        // 2) Open Graph <meta> + <title> fallback.
        libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        $xp = new \DOMXPath($doc);

        $meta = static function (string $prop) use ($xp): ?string {
            $node = $xp->query("//meta[@property='{$prop}' or @name='{$prop}']/@content")->item(0);
            $val = $node?->nodeValue;
            return ($val !== null && trim($val) !== '') ? trim($val) : null;
        };

        if ($result['name'] === null) {
            $result['name'] = $meta('og:title');
        }
        if ($result['name'] === null) {
            $titleNode = $xp->query('//title')->item(0);
            $title = $titleNode?->nodeValue;
            $result['name'] = ($title !== null && trim($title) !== '') ? trim($title) : null;
        }
        if ($result['price'] === null) {
            $result['price'] = $meta('og:price:amount') ?? $meta('product:price:amount');
        }
        if ($result['currency'] === null) {
            $result['currency'] = $meta('og:price:currency') ?? $meta('product:price:currency');
        }
        if ($result['image_url'] === null) {
            $result['image_url'] = $meta('og:image');
        }

        return $result;
    }

    /**
     * Convert a detected price to EUR (pure, network-free; D-04).
     *
     * With a price, a currency AND a non-null rate present, returns the rounded
     * EUR amount with needs_verify=false. When the currency is unknown or the FX
     * rate is null, keeps the RAW value, sets converted_eur=null and
     * needs_verify=true — never a silent mislabel.
     *
     * @return array{price: ?float, converted_eur: ?float, needs_verify: bool}
     */
    public function convert(?float $price, ?string $currency, ?float $rate): array
    {
        if ($price === null) {
            return ['price' => null, 'converted_eur' => null, 'needs_verify' => false];
        }
        if ($currency !== null && $rate !== null) {
            return [
                'price'         => $price,
                'converted_eur' => round($price * $rate, 2),
                'needs_verify'  => false,
            ];
        }
        // Currency unknown OR FX failed → keep raw, flag for verification.
        return ['price' => $price, 'converted_eur' => null, 'needs_verify' => true];
    }

    /**
     * Best-effort extraction from an embedded JSON-LD Product block (D-01).
     *
     * @return array{name: ?string, price: ?string, currency: ?string, image_url: ?string}
     */
    private function parseEmbeddedJson(string $html): array
    {
        $out = ['name' => null, 'price' => null, 'currency' => null, 'image_url' => null];

        if (!preg_match_all(
            '#<script[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is',
            $html,
            $matches
        )) {
            return $out;
        }

        foreach ($matches[1] as $blob) {
            $decoded = json_decode(trim($blob), true);
            if (!is_array($decoded)) {
                continue;
            }
            // JSON-LD may be a single object or a @graph array of objects.
            $candidates = isset($decoded['@graph']) && is_array($decoded['@graph'])
                ? $decoded['@graph']
                : [$decoded];

            foreach ($candidates as $node) {
                if (!is_array($node)) {
                    continue;
                }
                $type = $node['@type'] ?? null;
                $isProduct = $type === 'Product'
                    || (is_array($type) && in_array('Product', $type, true));
                if (!$isProduct) {
                    continue;
                }
                $out['name']      ??= $this->stringOrNull($node['name'] ?? null);
                $out['image_url'] ??= $this->firstImage($node['image'] ?? null);

                $offers = $node['offers'] ?? null;
                if (is_array($offers)) {
                    // offers can be an array of offers or a single AggregateOffer/Offer.
                    $offer = isset($offers['price']) || isset($offers['priceCurrency'])
                        ? $offers
                        : ($offers[0] ?? null);
                    if (is_array($offer)) {
                        $out['price']    ??= $this->stringOrNull($offer['price'] ?? ($offer['lowPrice'] ?? null));
                        $out['currency'] ??= $this->stringOrNull($offer['priceCurrency'] ?? null);
                    }
                }
            }
        }

        return $out;
    }

    /** Normalise a scalar JSON value to a trimmed non-empty string or null. */
    private function stringOrNull(mixed $value): ?string
    {
        if (is_string($value) || is_int($value) || is_float($value)) {
            $str = trim((string) $value);
            return $str !== '' ? $str : null;
        }
        return null;
    }

    /** Pick the first usable image URL from a JSON-LD image field (string or array). */
    private function firstImage(mixed $image): ?string
    {
        if (is_string($image)) {
            return $this->stringOrNull($image);
        }
        if (is_array($image)) {
            foreach ($image as $candidate) {
                if (is_string($candidate)) {
                    $val = $this->stringOrNull($candidate);
                    if ($val !== null) {
                        return $val;
                    }
                }
                if (is_array($candidate) && isset($candidate['url'])) {
                    $val = $this->stringOrNull($candidate['url']);
                    if ($val !== null) {
                        return $val;
                    }
                }
            }
        }
        return null;
    }

    /**
     * SSRF-guarded fetch: resolve → validate every IP → pin curl to the validated
     * IP → no FOLLOWLOCATION → manual redirect re-guard → protocol allowlist.
     *
     * Thin I/O method (NOT unit-tested — exercised by live smoke tests). Returns
     * the body on a 200, or null on any reject/error/non-200/empty response.
     */
    private function fetch(string $url, int $hops): ?string
    {
        $parts  = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host   = (string) ($parts['host'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return null;
        }

        $ip = $this->resolveAndValidate($host);
        if ($ip === null) {
            return null;
        }

        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        $ch   = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_RESOLVE        => ["{$host}:{$port}:{$ip}"],
            CURLOPT_MAXFILESIZE    => self::MAX_BODY_BYTES,
            CURLOPT_USERAGENT      => self::USER_AGENT,
            CURLOPT_HTTPHEADER     => ['Accept-Language: fr-FR,fr;q=0.9,en;q=0.8'],
        ]);
        $body   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        if (in_array($status, [301, 302, 303, 307, 308], true) && $hops > 0) {
            $location = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
            curl_close($ch);
            if ($location === '') {
                return null;
            }
            return $this->fetch($location, $hops - 1); // re-guards the new host
        }

        if ($body === false) {
            error_log('ProductImportService: curl error for ' . $host . ': ' . curl_error($ch));
            curl_close($ch);
            return null;
        }
        curl_close($ch);

        if ($status !== 200 || $body === '') {
            error_log('ProductImportService: HTTP ' . $status . ' / empty body for ' . $host);
            return null;
        }
        if (strlen((string) $body) > self::MAX_BODY_BYTES) {
            error_log('ProductImportService: body exceeds cap for ' . $host);
            return null;
        }

        return (string) $body;
    }

    /**
     * Fetch an attacker-influenceable image URL through the SAME SSRF guard,
     * size-cap it, and return a base64 data: URI (D-05a). Failure → null (no
     * preview, never an error, no CSP change).
     */
    private function fetchImageDataUri(string $url): ?string
    {
        $parts  = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host   = (string) ($parts['host'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return null;
        }

        $ip = $this->resolveAndValidate($host);
        if ($ip === null) {
            return null;
        }

        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        $ch   = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_RESOLVE        => ["{$host}:{$port}:{$ip}"],
            CURLOPT_MAXFILESIZE    => self::MAX_IMAGE_BYTES,
            CURLOPT_USERAGENT      => self::USER_AGENT,
        ]);
        $body   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $type   = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        if ($body === false || $status !== 200 || $body === '') {
            return null;
        }
        if (strlen((string) $body) > self::MAX_IMAGE_BYTES) {
            return null;
        }
        // Only emit a data: URI for an actual image content type.
        if (!str_starts_with(strtolower($type), 'image/')) {
            return null;
        }
        $mime = strtolower(trim(explode(';', $type)[0]));

        return 'data:' . $mime . ';base64,' . base64_encode((string) $body);
    }

    /**
     * Resolve a host (A + AAAA) and validate EVERY resolved IP. Returns the first
     * validated IP to pin curl to, or null if the host is unresolvable OR any
     * resolved IP fails the public-IP guard (fail-closed).
     */
    private function resolveAndValidate(string $host): ?string
    {
        $ips = gethostbynamel($host) ?: [];
        $aaaa = @dns_get_record($host, DNS_AAAA) ?: [];
        foreach ($aaaa as $record) {
            if (!empty($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }

        if ($ips === []) {
            error_log('ProductImportService: SSRF reject — host unresolvable: ' . $host);
            return null;
        }

        foreach ($ips as $ip) {
            if (!$this->isPublicIp($ip)) {
                error_log('ProductImportService: SSRF reject — non-public IP ' . $ip . ' for ' . $host);
                return null;
            }
        }

        return $ips[0];
    }
}
