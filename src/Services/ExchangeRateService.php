<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Server-side helper for the frankfurter.dev free FX API.
 *
 * The purchase form fetches the rate client-side (JS button "Taux du jour"),
 * but this service is available as a fallback / for server use. The rate that
 * is ultimately *stored* is always the one submitted with the form — the
 * server only recomputes the unit cost from it.
 */
final class ExchangeRateService
{
    // frankfurter.app was retired (301 → Cloudflare); the API now lives at api.frankfurter.dev/v1.
    private const ENDPOINT = 'https://api.frankfurter.dev/v1/latest';

    /**
     * Latest conversion rate from $from to $to (e.g. USD -> EUR).
     * Returns null on any failure so callers can fall back gracefully.
     */
    public function latest(string $from, string $to = 'EUR'): ?float
    {
        $from = strtoupper($from);
        $to   = strtoupper($to);
        if ($from === $to) {
            return 1.0;
        }

        $url = self::ENDPOINT . '?from=' . urlencode($from) . '&to=' . urlencode($to);
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $body = curl_exec($ch);

        if ($body === false) {
            error_log('ExchangeRateService: curl error for ' . $from . '->' . $to . ': ' . curl_error($ch));
            curl_close($ch);
            return null;
        }

        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($status !== 200) {
            error_log('ExchangeRateService: HTTP ' . $status . ' for ' . $from . '->' . $to);
            return null;
        }
        if ($body === '') {
            error_log('ExchangeRateService: empty response for ' . $from . '->' . $to);
            return null;
        }

        $data = json_decode($body, true);
        if (!is_array($data) || !isset($data['rates'][$to])) {
            error_log('ExchangeRateService: unexpected JSON for ' . $from . '->' . $to . ': ' . substr((string) $body, 0, 200));
            return null;
        }

        return (float) $data['rates'][$to];
    }
}
