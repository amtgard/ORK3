<?php

/***
 *
 * EraPhoenice
 *
 * Converts Gregorian dates to Era Phoenice (E.P.), Amtgard's in-world
 * fantasy reckoning. Not a calendar feed — there is no API, just a pure
 * date mapping. Mirrors electricsamurai.com/ad, which players already
 * use, so its day-count is the source of truth (the wiki ranges drift
 * ±1 against the math).
 *
 * Algorithm:
 *   - E.P. year boundary = Feb 12 ("Attila the Hun's Birthday").
 *   - E.P. year 1 day 1 = Feb 12, 1983 → epYear = boundary.year - 1982.
 *     (The original brief had this off by one. Verified against
 *     electricsamurai.com/ad: Feb 12 2026 boundary → E.P. 44.)
 *   - Day-count from boundary: 4 × 90-day months
 *     (Marching, Sowing, Harvest, Winter), then a 5–6-day Festivus
 *     remainder before the next boundary.
 *
 * Holiday lookup is keyed by Gregorian MM-DD, NOT by E.P. day — the
 * canonical holidays are anchored to civil dates, so they only drift
 * ±1 in E.P. across leap years (expected, harmless).
 *
 ***/

class EraPhoenice
{
    public const MONTHS = ['Marching', 'Sowing', 'Harvest', 'Winter'];

    // Canonical list — supersedes the wiki. Keyed by Gregorian MM-DD.
    public const HOLIDAYS = [
        '01-04' => 'Garbmas',
        '01-23' => 'The Feast of Glares',
        '02-12' => "Attila the Hun's Birthday",
        '03-01' => 'Mustering',
        '03-02' => 'The Feast of the Milnermen',
        '03-04' => 'Voluntarius',
        '03-16' => 'The Festival of Great Julesmas',
        '03-23' => 'The Wetsodus',
        '03-24' => 'Et Clauserunt',
        '04-01' => 'The Day of the Seventh Annunciation',
        '07-09' => 'Yum Hismorai Haelectroni',
        '07-27' => 'Floodmas',
        '07-31' => 'Dautas Nazgul',
        '08-13' => 'The Assumption of St. Sinister',
        '09-01' => 'The Feast of Food Fight',
        '09-24' => 'The Fall of Barad Duin',
        '10-15' => 'Dies Autem Reflexio Pictoribus',
        '11-05' => 'Guy Kasama Day',
        '11-25' => 'The Conniving',
        '12-01' => 'Name Day',
        '12-07' => 'The Last Dance of the Trents',
        '12-17' => 'The First Day of Gainsmas',
        '12-18' => 'The Second Day of Gainsmas',
        '12-19' => 'The Third Day of Gainsmas',
        '12-20' => 'The Fourth Day of Gainsmas',
        '12-21' => 'The Fifth Day of Gainsmas',
        '12-22' => 'The Sixth Day of Gainsmas',
        '12-23' => 'The Seventh Day of Gainsmas',
        '12-24' => 'The Eighth Day of Gainsmas',
        '12-25' => 'The Ninth Day of Gainsmas',
        '12-26' => 'The Tenth Day of Gainsmas',
        '12-27' => 'The Eleventh Day of Gainsmas',
        '12-28' => 'The Twelfth Day of Gainsmas',
        '12-29' => 'The Feast of All Whacks',
    ];

    /**
     * Convert a Gregorian date to E.P. components.
     * Returns ['year' => int, 'month' => string, 'day' => int].
     * For dates in the year-end remainder, month is 'Festivus' (1-based day).
     */
    public static function fromDate(DateTimeImmutable $d): array
    {
        // Find the most recent Feb 12 on or before $d. Day-precision only —
        // truncate $d to midnight so partial-day times don't shift the offset.
        $dayOnly = new DateTimeImmutable($d->format('Y-m-d'));
        $y       = (int)$dayOnly->format('Y');
        $start   = new DateTimeImmutable("$y-02-12");
        if ($dayOnly < $start) {
            $start = new DateTimeImmutable(($y - 1) . '-02-12');
        }

        $epYear = (int)$start->format('Y') - 1982;
        $offset = (int)$start->diff($dayOnly)->format('%a'); // 0-indexed

        if ($offset >= 360) {
            // Festivus tail — 5 days normal, 6 in leap years.
            return ['year' => $epYear, 'month' => 'Festivus', 'day' => $offset - 359];
        }

        $m = intdiv($offset, 90);
        return ['year' => $epYear, 'month' => self::MONTHS[$m], 'day' => ($offset % 90) + 1];
    }

    /**
     * Render an E.P. date as "E.P. 44, 24th of Sowing".
     */
    public static function format(DateTimeImmutable $d): string
    {
        $ep = self::fromDate($d);
        return sprintf('E.P. %d, %s of %s', $ep['year'], self::ordinal($ep['day']), $ep['month']);
    }

    /**
     * Today's E.P. date in the given timezone (or server-default if null).
     */
    public static function formatToday(?DateTimeZone $tz = null): string
    {
        return self::format(new DateTimeImmutable('now', $tz));
    }

    /**
     * Holiday name for the given date, or null if none.
     */
    public static function holiday(DateTimeImmutable $d): ?string
    {
        return self::HOLIDAYS[$d->format('m-d')] ?? null;
    }

    /**
     * Most recent holiday on or before $d (excluding $d itself).
     * Returns ['name'=>..., 'date'=>DateTimeImmutable] or null if the
     * holiday list is empty.
     */
    public static function lastHoliday(DateTimeImmutable $d): ?array
    {
        return self::neighborHoliday($d, -1);
    }

    /**
     * Soonest holiday strictly after $d.
     */
    public static function nextHoliday(DateTimeImmutable $d): ?array
    {
        return self::neighborHoliday($d, 1);
    }

    /**
     * Era Imperium reckoning — parallel year-counter shown alongside
     * E.P. on electricsamurai. Same Feb 12 boundary, more recent epoch
     * (year 1 = Feb 12, 2016 → epYear = boundary.year - 2015).
     */
    public static function imperiumFromDate(DateTimeImmutable $d): array
    {
        $ep = self::fromDate($d);
        // Recover the boundary year from the E.P. year using our offset.
        $boundaryYear = $ep['year'] + 1982;
        return [
            'year'  => $boundaryYear - 2015,
            'month' => $ep['month'],
            'day'   => $ep['day'],
        ];
    }

    public static function imperiumFormat(DateTimeImmutable $d): string
    {
        $im = self::imperiumFromDate($d);
        return sprintf('Era Imperium %d, %s of %s', $im['year'], self::ordinal($im['day']), $im['month']);
    }

    /**
     * Long civil-date render like "April 1st" / "December 17th" for use
     * in popover prose ("the next Amtgard holiday is X, on April 1st").
     */
    public static function longCivil(DateTimeImmutable $d): string
    {
        return $d->format('F') . ' ' . self::ordinal((int)$d->format('j'));
    }

    // ---- internals ----

    /**
     * Walk one day at a time in $direction (±1) until a holiday hits.
     * Stops after 366 steps to guarantee termination if HOLIDAYS were
     * ever emptied. Anchors the returned date to the same year as $d
     * when within-year, or rolls into adj. year when needed.
     */
    private static function neighborHoliday(DateTimeImmutable $d, int $direction): ?array
    {
        if (empty(self::HOLIDAYS)) {
            return null;
        }
        $cursor = $d;
        for ($i = 0; $i < 366; $i++) {
            $cursor = $cursor->modify(($direction > 0 ? '+1' : '-1') . ' day');
            $key    = $cursor->format('m-d');
            if (isset(self::HOLIDAYS[$key])) {
                return ['name' => self::HOLIDAYS[$key], 'date' => $cursor];
            }
        }
        return null;
    }

    /**
     * 1 → "1st", 22 → "22nd", 113 → "113th". English ordinal suffix.
     */
    private static function ordinal(int $n): string
    {
        $abs = abs($n) % 100;
        if ($abs >= 11 && $abs <= 13) {
            return $n . 'th';
        }
        switch ($n % 10) {
            case 1:  return $n . 'st';
            case 2:  return $n . 'nd';
            case 3:  return $n . 'rd';
            default: return $n . 'th';
        }
    }
}
