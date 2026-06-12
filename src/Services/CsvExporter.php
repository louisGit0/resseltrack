<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Builds CSV files compatible with Excel FR:
 *   - field separator ";"
 *   - UTF-8 with BOM
 */
final class CsvExporter
{
    private const SEP = ';';
    private const BOM = "\xEF\xBB\xBF";

    /**
     * @param array<int, string>            $header
     * @param array<int, array<int, mixed>> $rows
     */
    public function build(array $header, array $rows): string
    {
        $out = self::BOM;
        $out .= $this->line($header);
        foreach ($rows as $row) {
            $out .= $this->line($row);
        }
        return $out;
    }

    /** Stream a CSV file as a download and end the request. */
    public function download(string $filename, string $content): void
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($content));
        echo $content;
        exit;
    }

    /** @param array<int, mixed> $fields */
    private function line(array $fields): string
    {
        $escaped = array_map([$this, 'escape'], $fields);
        return implode(self::SEP, $escaped) . "\r\n";
    }

    private function escape(mixed $value): string
    {
        $value = (string) ($value ?? '');
        // Quote if it contains the separator, a quote, or a newline.
        if (preg_match('/["' . preg_quote(self::SEP, '/') . '\r\n]/', $value)) {
            $value = '"' . str_replace('"', '""', $value) . '"';
        }
        return $value;
    }
}
