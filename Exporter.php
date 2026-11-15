<?php
declare(strict_types=0);

namespace estuaries_modules\WorkLog;

use DateTimeImmutable;
use DateTimeZone;

/**
 * My log's export, pure: the date range, the CSV, and the Word body that
 * estuaries\Export::to_docx() turns into a file. Rows are
 * {worklog_entries_id, logged_at (UTC), body}, oldest first.
 */
final class Exporter
{
    public const CSV_MIME = 'text/csv; charset=UTF-8';

    private const BAD_DATE = 'Pick the dates as year-month-day, like 2026-11-09.';

    /**
     * This week in the site zone, Monday to Sunday, as local midnights.
     *
     * @return array{0:DateTimeImmutable,1:DateTimeImmutable}
     */
    public static function this_week(DateTimeImmutable $now, DateTimeZone $zone): array
    {
        $today  = $now->setTimezone($zone)->setTime(0, 0);
        $monday = $today->modify('-' . ((int) $today->format('N') - 1) . ' days');

        return [$monday, $monday->modify('+6 days')];
    }

    /**
     * The first and last day asked for (Y-m-d, in the site zone), as local
     * midnights; either left empty is this week's. Or why not.
     *
     * @return array{0:DateTimeImmutable,1:DateTimeImmutable}|string
     */
    public static function range(string $from, string $to, DateTimeImmutable $now, DateTimeZone $zone): array|string
    {
        [$week_from, $week_to] = self::this_week($now, $zone);

        $first = trim($from) === '' ? $week_from : self::day(trim($from), $zone);
        $last  = trim($to) === '' ? $week_to : self::day(trim($to), $zone);

        if ($first === null || $last === null) {
            return self::BAD_DATE;
        }
        if ($last < $first) {
            return 'The end date is before the start date.';
        }

        return [$first, $last];
    }

    /** "Nov 9 – Nov 15, 2026", with both years when they differ. */
    public static function range_label(DateTimeImmutable $from, DateTimeImmutable $to): string
    {
        if ($from->format('Y') !== $to->format('Y')) {
            return $from->format('M j, Y') . ' – ' . $to->format('M j, Y');
        }

        return $from->format('M j') . ' – ' . $to->format('M j, Y');
    }

    /** Date, Time, Entry in the site zone, with a BOM so Excel reads it as UTF-8. */
    public static function csv(array $rows, DateTimeZone $zone): string
    {
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");

        // escape: '' on every call: the default "\\" escape corrupts a field
        // that ends in a backslash, and PHP 8.4 deprecates leaving it out.
        fputcsv($out, ['Date', 'Time', 'Entry'], escape: '');
        foreach ($rows as $row) {
            $at = self::local($row, $zone);
            fputcsv($out, [$at->format('Y-m-d'), $at->format('g:i A'), self::csv_cell((string) $row['body'])], escape: '');
        }

        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    /** The Word file's body: a heading per day, a list of its entries. Every value escaped. */
    public static function docx_html(array $rows, DateTimeZone $zone): string
    {
        if ($rows === []) {
            return '<p>Nothing logged in these dates.</p>';
        }

        $e    = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $html = '';
        $day  = null;

        foreach ($rows as $row) {
            $at = self::local($row, $zone);
            if ($at->format('Y-m-d') !== $day) {
                $html .= ($day === null ? '' : '</ul>') . '<h2>' . $e($at->format('l, F j, Y')) . '</h2><ul>';
                $day   = $at->format('Y-m-d');
            }
            $html .= '<li><strong>' . $e($at->format('g:i A')) . '</strong> ' . $e((string) $row['body']) . '</li>';
        }

        return $html . '</ul>';
    }

    /**
     * A leading ' on a cell Excel (and other spreadsheet readers) would
     * otherwise read as a formula — a jotted "- call Dillon" opens as text,
     * not #NAME?. OWASP's CSV-injection guard, here for Excel's sake, never
     * as a security measure.
     */
    private static function csv_cell(string $body): string
    {
        return preg_match('/^[=+\-@\t\r]/', $body) === 1 ? "'" . $body : $body;
    }

    private static function day(string $value, DateTimeZone $zone): ?DateTimeImmutable
    {
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $zone);

        return $day !== false && $day->format('Y-m-d') === $value ? $day : null;
    }

    private static function local(array $row, DateTimeZone $zone): DateTimeImmutable
    {
        return (new DateTimeImmutable((string) $row['logged_at'], new DateTimeZone('UTC')))->setTimezone($zone);
    }
}
