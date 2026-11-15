<?php
declare(strict_types=0);

namespace estuaries_modules\WorkLog;

use DateTimeImmutable;
use DateTimeZone;

/**
 * One entry's text and time: what may be saved, how a typed "When" is read,
 * and how a time is shown. Pure; the clock and the zone are passed in.
 */
final class Entry
{
    /** Characters, as varchar(500) in utf8mb4 counts them. */
    public const MAX_LENGTH = 500;

    /** core 2.98.0 task hooks' 'done' kind: what a ticked task is logged as. */
    public const TASK_DONE_PREFIX = '[Task Completed] ';

    /** Seconds a time may sit ahead of now, for a clock that runs a little fast. */
    public const FUTURE_GRACE = 300;

    /**
     * The value of the browser's date-and-time picker (input type=datetime-local),
     * in the site zone: how an entry's time goes back into its edit form, and
     * the picker's max. parse_when() reads it.
     */
    public const WHEN_FORMAT = 'Y-m-d\\TH:i';

    /** Nothing earlier is believable as "what I just did". */
    private const EARLIEST = '2000-01-01 00:00:00';

    /** A time alone, meaning today. Tried after the dated forms. */
    private const TIME_FORMATS = ['!g:i A', '!g:iA', '!g A', '!gA', '!H:i'];

    /** A date and a time, in the shapes people type and the edit form writes. */
    private const DATE_TIME_FORMATS = [
        '!Y-m-d g:i A', '!Y-m-d g:iA', '!Y-m-d H:i', '!Y-m-d\TH:i',
        '!n/j/Y g:i A', '!n/j/Y g:iA', '!n/j/Y H:i',
    ];

    /**
     * What may be saved: the trimmed one-line body and the instant in UTC,
     * or the first problem, in words for the person who typed it.
     *
     * @return array{error:?string,body:string,at:?DateTimeImmutable}
     */
    public static function check(string $body, string $when, DateTimeImmutable $now, DateTimeZone $zone): array
    {
        $body = trim(str_replace(["\r\n", "\r", "\n"], ' ', $body));
        $fail = static fn(string $why): array => ['error' => $why, 'body' => $body, 'at' => null];

        if ($body === '') {
            return $fail('Write what you did.');
        }

        $length = mb_strlen($body, 'UTF-8');
        if ($length > self::MAX_LENGTH) {
            return $fail('Keep it to ' . self::MAX_LENGTH . ' characters. That was ' . $length . '.');
        }

        $at = self::parse_when($when, $now, $zone);
        if ($at === null) {
            return $fail("Couldn't read that time. Pick a date and time, or leave it blank for now.");
        }
        if ($at->getTimestamp() > $now->getTimestamp() + self::FUTURE_GRACE) {
            return $fail("That time hasn't happened yet.");
        }
        if ($at < new DateTimeImmutable(self::EARLIEST, $zone)) {
            return $fail('Pick a date in 2000 or later.');
        }

        return ['error' => null, 'body' => $body, 'at' => $at->setTimezone(new DateTimeZone('UTC'))];
    }

    /**
     * "[Task Completed] {title}", for TaskHooks' 'done' kind
     * (WorkLog::task_done()): a task's title may hold HTML or entities
     * (CoreTasks lets one be set with markup from other code paths), so it
     * is stripped and decoded, collapsed to one line, then the WHOLE body —
     * prefix included — is cut to MAX_LENGTH, multibyte-safe.
     */
    public static function task_done_body(string $title): string
    {
        $text = html_entity_decode(strip_tags($title), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        $body = self::TASK_DONE_PREFIX . $text;

        return mb_strlen($body, 'UTF-8') > self::MAX_LENGTH
            ? mb_substr($body, 0, self::MAX_LENGTH, 'UTF-8')
            : $body;
    }

    /**
     * A typed When as an instant, or null when it cannot be read. Empty or
     * "now" is now; a time alone is today, in the site zone; a date and a
     * time is that, in the site zone.
     */
    public static function parse_when(string $when, DateTimeImmutable $now, DateTimeZone $zone): ?DateTimeImmutable
    {
        $when = strtoupper(trim((string) preg_replace('/\s+/', ' ', $when)));

        if ($when === '' || $when === 'NOW') {
            return $now;
        }

        foreach (self::DATE_TIME_FORMATS as $format) {
            $at = self::strict($format, $when, $zone);
            if ($at !== null) {
                return $at;
            }
        }

        foreach (self::TIME_FORMATS as $format) {
            $time = self::strict($format, $when, $zone);
            if ($time !== null) {
                return $now->setTimezone($zone)->setTime((int) $time->format('G'), (int) $time->format('i'));
            }
        }

        return null;
    }

    /** A stored UTC logged_at as its edit form shows it, in the site zone. */
    public static function when_value(string $logged_at, DateTimeZone $zone): string
    {
        return (new DateTimeImmutable($logged_at, new DateTimeZone('UTC')))->setTimezone($zone)->format(self::WHEN_FORMAT);
    }

    /** The picker's latest choice: now, in the site zone, so it greys out what hasn't happened. */
    public static function picker_max(DateTimeImmutable $now, DateTimeZone $zone): string
    {
        return $now->setTimezone($zone)->format(self::WHEN_FORMAT);
    }

    /** A day row's heading: Today, Yesterday, or the date (with the year when it is not this one). */
    public static function day_label(string $day, DateTimeImmutable $now, DateTimeZone $zone): string
    {
        $today = $now->setTimezone($zone);

        if ($day === $today->format('Y-m-d')) {
            return 'Today';
        }
        if ($day === $today->modify('-1 day')->format('Y-m-d')) {
            return 'Yesterday';
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $day, $zone);
        if ($date === false) {
            return $day;
        }

        return $date->format($date->format('Y') === $today->format('Y') ? 'l, F j' : 'l, F j, Y');
    }

    /** createFromFormat() that refuses a rolled-over value (February 30, 25:00) rather than moving it. */
    private static function strict(string $format, string $value, DateTimeZone $zone): ?DateTimeImmutable
    {
        $at     = DateTimeImmutable::createFromFormat($format, $value, $zone);
        $errors = DateTimeImmutable::getLastErrors();

        if ($at === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }

        return $at;
    }
}
