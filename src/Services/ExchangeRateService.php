<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Server-side helper for the frankfurter.app free FX API.
 *
 * The purchase form fetches the rate client-side (JS button "Taux du jour"),
 * but this service is available as a fallback / for server use. The rate that
 * is ultimately *stored* is always the one submitted with the form — the
 * server only recomputes the unit cost from it.
 */
final class ExchangeRateService
{
    private const ENDPOINT = 'https://api.frankfurter.app/latest';

    /**
     * Latest conversion rate from $from to $to (e.g. USD -> EUR).
     * Returns null on any failure so callers can fall back gracefully.
     */
    public function latest(string $from, string $to = 'EUR'): ?float
    {
        $from = strtoupper($from);
        $to = strtoupper($to);
        if ($from === $to) {
            return 1.0;
        }

        $url = self::ENDPOINT . '?from=' . urlencode($from) . '&to=' . urlencode($to);

        $ctx = stream_context_create(['http' => ['timeout' => 5, 'method' => 'GET']]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) {
            return null;
        }

        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['rates'][$to])) {
            return null;
        }

        return (float) $data['rates'][$to];
    }
}
