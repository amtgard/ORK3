<?php

/**
 * Season period rules for officer history ("Spring-Fall 23").
 *
 * Pure logic: no SQL, no DB. A period is stored as a season ordinal,
 * year*4 + i, with spring=1, summer=2, fall=3 and winter=0. Late winter
 * (Jan-Feb of YY) is YY*4; early winter (Dec YY) is (YY+1)*4, the same
 * ordinal as the Jan-Feb that follows, because they are one winter.
 */
class OfficerTermPeriod
{
    private const SEASONS = [0 => 'winter', 1 => 'spring', 2 => 'summer', 3 => 'fall'];

    private const SEASON_WORDS = [
        'spring' => 'spring', 'spr' => 'spring', 'sp' => 'spring',
        'summer' => 'summer', 'sum' => 'summer', 'su' => 'summer',
        'fall' => 'fall', 'autumn' => 'fall', 'fa' => 'fall', 'f' => 'fall', 'au' => 'fall',
        'winter' => 'winter', 'win' => 'winter', 'wi' => 'winter', 'w' => 'winter',
    ];

    private const ERR_CURRENT = 'Current terms are set from Manage Officers.';

    /**
     * @return array|string error message, or ['start' => ?array, 'end' => ?array, 'endUnknown' => bool]
     */
    public static function parse(string $text, ?int $todayYear = null): array|string
    {
        $todayYear ??= (int)date('Y');
        $t = mb_strtolower(trim($text));
        if ($t === '') {
            return 'Enter a period such as "Spring–Fall 23".';
        }
        if (preg_match('/\b(present|now|current)\b/', $t)) {
            return self::ERR_CURRENT;
        }
        $t = str_replace(['–', '—'], '-', $t);
        $t = preg_replace('/\s+to\s+/', '-', $t);
        $parts = array_map('trim', explode('-', $t));
        if (count($parts) > 2) {
            return 'Use one dash between the start and end, e.g. "Spring 22 – Fall 23".';
        }

        $endUnknown = false;
        $first = self::parsePart($parts[0]);
        if (is_string($first)) {
            return $first;
        }
        $second = null;
        if (count($parts) === 2) {
            if ($parts[1] === '' || $parts[1] === '?') {
                $endUnknown = true;
            } else {
                $second = self::parsePart($parts[1]);
                if (is_string($second)) {
                    return $second;
                }
            }
        }

        // Shared year: "Spring-Fall 23".
        if ($first['year'] === null) {
            if ($second === null || $second['year'] === null) {
                return 'Add a year, e.g. "Spring 23".';
            }
            $first['year'] = $second['year'];
        }
        if ($second !== null && $second['year'] === null) {
            $second['year'] = $first['year'];
        }

        $start = ['season' => $first['season'], 'year' => self::fullYear($first['year'], $todayYear)];
        if ($endUnknown) {
            $end = null;
        } elseif ($second === null) {
            $end = $start;
        } else {
            $end = ['season' => $second['season'], 'year' => self::fullYear($second['year'], $todayYear)];
        }

        if ($end !== null) {
            $r = self::resolve([
                'StartSeason' => $start['season'], 'StartYear' => $start['year'],
                'EndSeason' => $end['season'], 'EndYear' => $end['year'],
                'StartDate' => null, 'EndDate' => null,
            ]);
            if ($r['end'] < $r['start']) {
                return 'The end comes before the start.';
            }
        }

        return ['start' => $start, 'end' => $end, 'endUnknown' => $endUnknown];
    }

    /** @return array|string ['season' => string, 'year' => ?string] or an error */
    private static function parsePart(string $p): array|string
    {
        if (!preg_match('/^([a-z]+)\s*(\d+)?$/', $p, $m)) {
            return 'Could not read "' . $p . '". Use a season and year, e.g. "Spring 23".';
        }
        if (!isset(self::SEASON_WORDS[$m[1]])) {
            return 'Unknown season "' . $m[1] . '". Use Spring, Summer, Fall or Winter.';
        }
        $year = $m[2] ?? null;
        if ($year !== null && strlen($year) !== 2 && strlen($year) !== 4) {
            return 'Years must be 2 or 4 digits.';
        }
        return ['season' => self::SEASON_WORDS[$m[1]], 'year' => $year];
    }

    private static function fullYear(string $y, int $todayYear): int
    {
        if (strlen($y) === 4) {
            return (int)$y;
        }
        $yy = (int)$y;
        return $yy <= ($todayYear % 100) + 1 ? 2000 + $yy : 1900 + $yy;
    }

    /** Ordinal for one side of a term; an exact date wins over a season. */
    public static function ordinal(?string $season, ?int $year, ?string $exactDate, string $side, ?int $otherOrdinal): ?int
    {
        if ($exactDate !== null && $exactDate !== '' && $exactDate !== '0000-00-00') {
            $ts = strtotime($exactDate);
            if ($ts !== false) {
                $y = (int)date('Y', $ts);
                $m = (int)date('n', $ts);
                if ($m === 12) {
                    return ($y + 1) * 4;
                }
                if ($m <= 2) {
                    return $y * 4;
                }
                return $y * 4 + (int)(($m - 3) / 3) + 1;
            }
        }
        if ($season === null || $season === '' || $year === null) {
            return null;
        }
        $i = array_search($season, self::SEASONS, true);
        if ($i === false) {
            return null;
        }
        if ($i !== 0) {
            return $year * 4 + $i;
        }
        $late = $year * 4;
        $early = ($year + 1) * 4;
        if ($otherOrdinal === null) {
            return $late;
        }
        if ($side === 'start') {
            return $early <= $otherOrdinal ? $early : $late;
        }
        return $late >= $otherOrdinal ? $late : $early;
    }

    /** @return array ['start' => ?int, 'end' => ?int] */
    public static function resolve(array $term): array
    {
        $ss = $term['StartSeason'] ?? null;
        $sy = isset($term['StartYear']) ? (int)$term['StartYear'] : null;
        $sd = $term['StartDate'] ?? null;
        $es = $term['EndSeason'] ?? null;
        $ey = isset($term['EndYear']) ? (int)$term['EndYear'] : null;
        $ed = $term['EndDate'] ?? null;

        $startIsWinter = $ss === 'winter' && empty($sd);
        $endIsWinter = $es === 'winter' && empty($ed);

        if ($startIsWinter && !$endIsWinter) {
            // Direction comes from the end.
            $end = self::ordinal($es, $ey, $ed, 'end', null);
            $start = self::ordinal($ss, $sy, $sd, 'start', $end);
            return ['start' => $start, 'end' => $end];
        }
        if ($startIsWinter && $endIsWinter) {
            $start = self::ordinal($ss, $sy, $sd, 'start', null);
            $end = self::ordinal($es, $ey, $ed, 'end', $start === null ? null : $start + 1);
            return ['start' => $start, 'end' => $end];
        }
        $start = self::ordinal($ss, $sy, $sd, 'start', null);
        $end = self::ordinal($es, $ey, $ed, 'end', $start);
        return ['start' => $start, 'end' => $end];
    }

    public static function label(array $term, bool $isCurrent): string
    {
        $r = self::resolve($term);
        $side = function (string $prefix, ?int $ord) use ($term): ?string {
            $d = $term[$prefix . 'Date'] ?? null;
            if (!empty($d) && $d !== '0000-00-00' && ($ts = strtotime($d)) !== false) {
                return date('M j, Y', $ts);
            }
            return $ord === null ? null : self::ordinalLabel($ord);
        };
        // Show the season the user entered, not the resolved winter year.
        $enteredSide = function (string $prefix, ?int $ord) use ($term, $side): ?string {
            $d = $term[$prefix . 'Date'] ?? null;
            $s = $term[$prefix . 'Season'] ?? null;
            $y = $term[$prefix . 'Year'] ?? null;
            if (empty($d) && $s !== null && $s !== '' && $y !== null) {
                return ucfirst($s) . ' ' . (int)$y;
            }
            return $side($prefix, $ord);
        };
        $start = $enteredSide('Start', $r['start']) ?? '?';
        $end = $enteredSide('End', $r['end']) ?? ($isCurrent ? 'present' : '?');
        return $start . ' – ' . $end;
    }

    public static function ordinalLabel(int $ordinal): string
    {
        $i = (($ordinal % 4) + 4) % 4;
        return ucfirst(self::SEASONS[$i]) . ' ' . intdiv($ordinal - $i, 4);
    }

    /** Period text to pre-fill for the term before: same length, ending at $start. */
    public static function previousSpan(int $start, ?int $end): string
    {
        $len = $end === null ? 2 : max(0, $end - $start);
        return self::ordinalLabel($start - $len) . ' – ' . self::ordinalLabel($start);
    }

    /**
     * Read a typed period back for the ledger before it is saved: the label the
     * report will show, its ordinals and the span to pre-fill for the term before.
     * Applies the save rules the reader can know about (no "present", no future
     * end); the server still validates on save.
     *
     * $isCurrent: the period is a current term's start, so it must be a single
     * season ("Spring 24"); the end belongs to Manage Officers.
     *
     * @return array|string error message, or [Label, StartOrdinal, EndOrdinal, PreviousSpan]
     */
    public static function readBack(string $text, bool $isCurrent = false, ?int $nowOrdinal = null): array|string
    {
        if ($isCurrent && preg_match('/[-\x{2013}\x{2014}]|\sto\s/iu', $text)) {
            return 'Enter only the start of a current term, e.g. "Spring 24". Its end is set from Manage Officers.';
        }
        $p = self::parse($text);
        if (is_string($p)) {
            return $p;
        }
        $term = [
            'StartSeason' => $p['start']['season'], 'StartYear' => $p['start']['year'], 'StartDate' => null,
            'EndSeason' => null, 'EndYear' => null, 'EndDate' => null,
        ];
        if (!$isCurrent && $p['end'] !== null) {
            $term['EndSeason'] = $p['end']['season'];
            $term['EndYear'] = $p['end']['year'];
        }
        $ord = self::resolve($term);
        $nowOrdinal ??= self::currentOrdinal();
        if ($ord['end'] !== null && $ord['end'] > $nowOrdinal) {
            return 'A term cannot end in the future.';
        }
        return [
            'Label' => self::label($term, $isCurrent),
            'StartOrdinal' => $ord['start'],
            'EndOrdinal' => $ord['end'],
            'PreviousSpan' => self::previousSpan((int) $ord['start'], $ord['end']),
        ];
    }

    public static function currentOrdinal(?string $today = null): int
    {
        $ts = strtotime($today ?? 'today');
        return self::ordinal(null, null, date('Y-m-d', $ts), 'start', null);
    }
}
