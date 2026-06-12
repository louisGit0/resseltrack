<?php
declare(strict_types=1);

/**
 * View helpers — kept tiny and global for convenience inside templates.
 */

if (!function_exists('e')) {
    /** htmlspecialchars on every value sent to HTML output. */
    function e(mixed $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('money')) {
    /** Format a EUR amount for display (French style). */
    function money(float|string|null $amount, int $decimals = 2): string
    {
        return number_format((float) $amount, $decimals, ',', ' ') . ' €';
    }
}

if (!function_exists('cur_sym')) {
    /** Symbol for a currency code (EUR/USD/CNY). */
    function cur_sym(string $currency): string
    {
        return match (strtoupper($currency)) {
            'USD' => '$',
            'CNY' => '¥',
            default => '€',
        };
    }
}

if (!function_exists('pct')) {
    function pct(float|string|null $value): string
    {
        return number_format((float) $value, 1, ',', ' ') . ' %';
    }
}

if (!function_exists('dateFr')) {
    /** Format an Y-m-d date as dd/mm/YYYY for display. */
    function dateFr(?string $date): string
    {
        if ($date === null || $date === '') {
            return '—';
        }
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', $date);
        return $d !== false ? $d->format('d/m/Y') : e($date);
    }
}

if (!function_exists('th_sort')) {
    /**
     * Sortable column header. Builds a link on the current path that toggles
     * sort direction and preserves search + extra filters (resets to page 1).
     */
    function th_sort(string $key, string $label, string $sort, string $dir, string $q = '', string $class = '', array $extra = []): string
    {
        $nextDir = ($sort === $key && $dir === 'asc') ? 'desc' : 'asc';
        $params = array_merge($extra, ['sort' => $key, 'dir' => $nextDir]);
        if ($q !== '') {
            $params['q'] = $q;
        }
        $params = array_filter($params, static fn($v) => $v !== null && $v !== '');
        $path = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
        $url = $path . '?' . http_build_query($params);

        $icon = '';
        if ($sort === $key) {
            $icon = $dir === 'asc'
                ? ' <i class="bi bi-caret-up-fill" style="font-size:.55rem"></i>'
                : ' <i class="bi bi-caret-down-fill" style="font-size:.55rem"></i>';
        }
        return '<th class="' . e($class) . '"><a href="' . e($url) . '" class="text-decoration-none" style="color:inherit">'
            . e($label) . $icon . '</a></th>';
    }
}

if (!function_exists('old')) {
    /** Read an old-input value (set after a failed validation). */
    function old(array $old, string $key, mixed $default = ''): string
    {
        return e($old[$key] ?? $default);
    }
}
