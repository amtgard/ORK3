<?php

/**
 * Season period rules for officer history ("Spring-Fall 23").
 *
 * Pure logic: no SQL, no DB. A period is a season ordinal, year*4 + i, with
 * spring=1, summer=2, fall=3 and winter=0. A winter's ordinal is the year of
 * its January-February: Dec YY and Jan-Feb YY+1 are one winter, (YY+1)*4, and
 * it is named by both years ("Winter 2022–23").
 *
 * A saved term's meaning is its stored ordinals (ork_officer_history
 * start_ordinal / end_ordinal), written once when a side is set and never
 * re-derived. The season columns are the text as entered, for editing. A
 * typed "Winter YY" depends on the other end ("Winter 23 – Summer 24" starts
 * in Dec 2023, a lone "Winter 23" is Jan-Feb 2023); parse() settles that
 * once, and closing the term later cannot move it.
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

    /** Month words, mapped to seasons as ordinal() maps an exact date's month. */
    private const MONTHS = [
        'january' => 1, 'jan' => 1, 'february' => 2, 'feb' => 2, 'march' => 3, 'mar' => 3,
        'april' => 4, 'apr' => 4, 'may' => 5, 'june' => 6, 'jun' => 6, 'july' => 7, 'jul' => 7,
        'august' => 8, 'aug' => 8, 'september' => 9, 'sept' => 9, 'sep' => 9,
        'october' => 10, 'oct' => 10, 'november' => 11, 'nov' => 11, 'december' => 12, 'dec' => 12,
    ];

    /** A winter named by both its years: "Winter 2022–23", "W 22/23". */
    private const TWO_YEAR_WINTER = '/\b(?:winter|win|wi|w)\s*(\d{4}|\d{2})\s*[-\/]\s*(\d{4}|\d{2})\b/i';

    private const ERR_CURRENT = 'Current terms are set from Manage Officers.';

    /**
     * Read a typed period. Accepts season words and abbreviations ("Spr. 23"),
     * apostrophe years ("Fall '95"), month names with a year ("Mar 03"), which
     * are the season their month falls in, and a winter named by both its years
     * ("Winter 2022–23").
     *
     * Each side carries its 'ordinal', the term's meaning, and the season and
     * year to store as the entered text. A month or a two-year winter is exact;
     * a typed "Winter YY" is resolved against the other side here, once.
     *
     * 'single' marks a single-season entry ("Spring 23"): a one-season term,
     * so its end is its start.
     *
     * @return array|string error message, or ['start' => array, 'end' => ?array,
     *                      'endUnknown' => bool, 'single' => bool]; a side is
     *                      ['season' => string, 'year' => int, 'ordinal' => int]
     */
    public static function parse(string $text, ?int $todayYear = null): array|string
    {
        $todayYear ??= (int)date('Y');
        $t = trim($text);
        if ($t === '') {
            return 'Enter a period such as "Spring–Fall 23".';
        }
        if (preg_match('/\b(present|now|current)\b/i', $t)) {
            return self::ERR_CURRENT;
        }
        $t = self::normalize($t);
        $joinError = null;
        $t = self::joinTwoYearWinters($t, $todayYear, $joinError);
        if ($joinError !== null) {
            return $joinError;
        }
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

        $single = !$endUnknown && $second === null;
        $a = self::side($first, $todayYear);
        $b = $endUnknown ? null : ($single ? $a : self::side($second, $todayYear));

        // Two typed winters resolve forward from the start, so a reversed pair would
        // otherwise read as the winter after it.
        if ($b !== null && $a['exact'] === null && $b['exact'] === null
            && $a['season'] === 'winter' && $b['season'] === 'winter' && $b['year'] < $a['year']) {
            return 'The end comes before the start.';
        }
        // An exact side (a month, a two-year winter) goes in as a date, so the
        // typed winter on the other side resolves against exactly that season.
        $ord = self::resolve(self::sidesTerm($a, $b));
        if ($ord['end'] !== null && $ord['end'] < $ord['start']) {
            return 'The end comes before the start.';
        }

        [$startYear, $endYear] = self::storedYears($a, $b, $ord, $single);
        $start = ['season' => $a['season'], 'year' => $startYear, 'ordinal' => $ord['start']];
        $end = $b === null ? null : ['season' => $b['season'], 'year' => $endYear, 'ordinal' => $ord['end']];
        return ['start' => $start, 'end' => $end, 'endUnknown' => $endUnknown, 'single' => $single];
    }

    /**
     * Whether $text names a span (a start and an end, or a start and "–") rather
     * than one season. The dash inside a two-year winter ("Winter 2022–23") does
     * not count, so a current term's start may be written that way.
     */
    public static function isSpan(string $text): bool
    {
        $t = preg_replace('/\s+to\s+/i', ' - ', str_replace(['–', '—'], '-', $text));
        $t = preg_replace(self::TWO_YEAR_WINTER, 'w', $t);
        return strpos($t, '-') !== false;
    }

    /** Dashes, "to", apostrophe years and dotted abbreviations, made plain. */
    private static function normalize(string $t): string
    {
        $t = str_replace(['–', '—'], '-', $t);
        $t = preg_replace('/\s+to\s+/i', '-', $t);
        // Amtwiki style: an apostrophe before a year, a period after an abbreviation.
        $t = preg_replace("/['\x{2018}\x{2019}](?=\d)/u", '', $t);
        return preg_replace('/(?<=[a-z])\.(?=\s|\d|-|$)/i', '', $t);
    }

    /**
     * Rewrite each two-year winter as one token, "winter@YYYY" (YYYY its
     * January-February year), so the dash between its years is not read as
     * the span's dash. $error is set when the years are not consecutive.
     */
    private static function joinTwoYearWinters(string $t, int $todayYear, ?string &$error): string
    {
        return preg_replace_callback(self::TWO_YEAR_WINTER, function ($m) use ($todayYear, &$error) {
            $y1 = self::fullYear($m[1], $todayYear);
            $y2 = strlen($m[2]) === 4 ? (int)$m[2] : intdiv($y1, 100) * 100 + (int)$m[2];
            if (strlen($m[2]) === 2 && $y2 < $y1) {
                $y2 += 100;
            }
            if ($y2 !== $y1 + 1) {
                $error = 'A winter spans two years in a row, e.g. "Winter 2022–23".';
                return $m[0];
            }
            return 'winter@' . $y1 . '@' . $y2;
        }, $t);
    }

    /**
     * One side of a period, as typed. Errors quote the text the user typed.
     *
     * @return array|string ['season' => ?string, 'month' => ?int, 'year' => ?string, 'winterOf' => ?int] or an error
     */
    private static function parsePart(string $p): array|string
    {
        if (preg_match('/^winter@(\d{4})@(\d{4})$/', $p, $m)) {
            return ['season' => 'winter', 'month' => null, 'year' => $m[1], 'winterOf' => (int)$m[2]];
        }
        if (!preg_match('/^([a-z]+)\s*(\d+)?$/i', $p, $m)) {
            return 'Could not read "' . $p . '". Use a season and year, e.g. "Spring 23".';
        }
        $word = strtolower($m[1]);
        $year = $m[2] ?? null;
        if ($year !== null && strlen($year) !== 2 && strlen($year) !== 4) {
            return 'Years must be 2 or 4 digits.';
        }
        if (isset(self::SEASON_WORDS[$word])) {
            return ['season' => self::SEASON_WORDS[$word], 'month' => null, 'year' => $year, 'winterOf' => null];
        }
        if (isset(self::MONTHS[$word])) {
            return ['season' => null, 'month' => self::MONTHS[$word], 'year' => $year, 'winterOf' => null];
        }
        return 'Unknown season "' . $m[1] . '". Use Spring, Summer, Fall or Winter.';
    }

    /**
     * A parsed side with its year resolved. 'exact' is the ordinal when the side
     * names one season outright (a month, a two-year winter); null when it is a
     * typed season whose winter year still depends on the other side.
     */
    private static function side(array $part, int $todayYear): array
    {
        if ($part['winterOf'] !== null) {
            return ['season' => 'winter', 'year' => $part['winterOf'], 'exact' => $part['winterOf'] * 4];
        }
        $year = self::fullYear($part['year'], $todayYear);
        if ($part['month'] === null) {
            return ['season' => $part['season'], 'year' => $year, 'exact' => null];
        }
        $ord = self::ordinal(null, null, sprintf('%04d-%02d-01', $year, $part['month']), 'start', null);
        return ['season' => self::SEASONS[$ord % 4], 'year' => intdiv($ord, 4), 'exact' => $ord];
    }

    /** resolve()'s input for two parsed sides: an exact side as a date inside its season. */
    private static function sidesTerm(array $a, ?array $b): array
    {
        $t = [];
        foreach (['Start' => $a, 'End' => $b] as $prefix => $s) {
            $t[$prefix . 'Season'] = ($s !== null && $s['exact'] === null) ? $s['season'] : null;
            $t[$prefix . 'Year'] = ($s !== null && $s['exact'] === null) ? $s['year'] : null;
            $t[$prefix . 'Date'] = ($s !== null && $s['exact'] !== null) ? self::ordinalDate($s['exact']) : null;
        }
        return $t;
    }

    /** A date inside the season $ordinal (mid-January for a winter). */
    private static function ordinalDate(int $ordinal): string
    {
        $i = (($ordinal % 4) + 4) % 4;
        return sprintf('%04d-%02d-15', intdiv($ordinal - $i, 4), [1, 4, 7, 10][$i]);
    }

    /**
     * The years to store in the season columns: the typed year for a typed
     * season, and for an exact winter whichever of its two years makes the
     * columns resolve to the parsed ordinals (so the entered text, shown again
     * for editing, means what was saved).
     */
    private static function storedYears(array $a, ?array $b, array $ord, bool $single): array
    {
        $years = function (array $s): array {
            if ($s['exact'] === null || $s['season'] !== 'winter') {
                return [$s['year']];
            }
            return [$s['year'], $s['year'] - 1];
        };
        $as = $years($a);
        $bs = $b === null ? [null] : ($single ? null : $years($b));
        foreach ($as as $ya) {
            foreach ($bs ?? [$ya] as $yb) {
                $r = self::resolve([
                    'StartSeason' => $a['season'], 'StartYear' => $ya, 'StartDate' => null,
                    'EndSeason' => $b['season'] ?? null, 'EndYear' => $yb, 'EndDate' => null,
                ]);
                if ($r === $ord) {
                    return [$ya, $yb];
                }
            }
        }
        return [$as[0], $b === null ? null : ($single ? $as[0] : $b['year'])];
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

    /**
     * Resolve season columns and dates into ordinals, as parse() reads typed text.
     * For a saved term use ordinals(), which reads the stored ordinals instead.
     *
     * @return array ['start' => ?int, 'end' => ?int]
     */
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
            // The same winter on both ends is a single season ("Winter 24").
            if ($sy !== null && $sy === $ey) {
                return ['start' => $start, 'end' => $start];
            }
            $end = self::ordinal($es, $ey, $ed, 'end', $start === null ? null : $start + 1);
            return ['start' => $start, 'end' => $end];
        }
        $start = self::ordinal($ss, $sy, $sd, 'start', null);
        $end = self::ordinal($es, $ey, $ed, 'end', $start);
        return ['start' => $start, 'end' => $end];
    }

    /**
     * A term's ordinals: its stored StartOrdinal / EndOrdinal, fixed when each
     * side was saved (a stored null is an unknown side). A side with no stored
     * ordinal but an exact date reads that date: one date is one ordinal, so
     * nothing is re-resolved (a row written before the migration stamped it, or
     * by hand). A side whose key is absent -- a term that was never saved -- is
     * resolved from its columns.
     *
     * @return array ['start' => ?int, 'end' => ?int]
     */
    public static function ordinals(array $term): array
    {
        $out = [];
        $resolved = null;
        foreach (['start' => 'StartOrdinal', 'end' => 'EndOrdinal'] as $side => $key) {
            if (array_key_exists($key, $term)) {
                $v = $term[$key];
                if ($v !== null && $v !== '') {
                    $out[$side] = (int)$v;
                } else {
                    $date = $term[($side === 'start' ? 'Start' : 'End') . 'Date'] ?? null;
                    $out[$side] = self::ordinal(null, null, $date, $side, null);
                }
            } else {
                $resolved ??= self::resolve($term);
                $out[$side] = $resolved[$side];
            }
        }
        return $out;
    }

    public static function label(array $term, bool $isCurrent): string
    {
        $start = self::sideLabel($term, 'Start') ?? '?';
        $end = self::sideLabel($term, 'End') ?? ($isCurrent ? 'present' : '?');
        // One season ("Spring 2023 – Spring 2023") reads as that season.
        if ($start === $end && $start !== '?') {
            return $start;
        }
        return $start . ' – ' . $end;
    }

    /**
     * One end of a term ($prefix 'Start' or 'End'): the exact date when there is
     * one ("Mar 4, 2023"), else its season from the ordinal ("Winter 2022–23",
     * whichever year was typed), else null (unknown).
     */
    public static function sideLabel(array $term, string $prefix): ?string
    {
        if (($date = self::dateLabel($term[$prefix . 'Date'] ?? null)) !== null) {
            return $date;
        }
        $ord = self::ordinals($term)[$prefix === 'Start' ? 'start' : 'end'];
        return $ord === null ? null : self::ordinalLabel($ord);
    }

    /** An exact date as the labels show it ("Mar 4, 2023"); null when there is none. */
    private static function dateLabel($d): ?string
    {
        if (empty($d) || $d === '0000-00-00' || ($ts = strtotime((string)$d)) === false) {
            return null;
        }
        return date('M j, Y', $ts);
    }

    /**
     * label() without the "– present" tail of a current term, for surfaces that
     * draw "present" themselves (the rolls' present pill).
     */
    public static function labelBase(array $term, bool $isCurrent): string
    {
        if ($isCurrent && self::sideLabel($term, 'End') === null) {
            return self::sideLabel($term, 'Start') ?? '?';
        }
        return self::label($term, $isCurrent);
    }

    /**
     * The period as the ledger's Season field shows it for editing: text that
     * parse() reads back to the term's stored ordinals, so saving it unchanged
     * keeps the term's seasons (as historyTermColumns() applies a sent Period).
     *
     * Short forms first: the season as entered, then each side's short names
     * (a winter is "Winter YY" or "Winter YY-1", whichever the other end makes
     * mean it), then the two-year winter name, which always reads back. The
     * first text whose parse() gives the stored ordinals wins. A current term
     * is its start only ("Spring 2024"); an unknown end is "Spring 2023 –"; one
     * season is that season. A term with no known start (end only, or nothing)
     * is '': the grammar has no unknown start, and a blank field sends no
     * Period unless typed.
     */
    public static function editText(array $term, bool $isCurrent): string
    {
        $want = self::ordinals($term);
        if ($want['start'] === null) {
            return '';
        }
        $entered = function (string $prefix) use ($term): array {
            $s = $term[$prefix . 'Season'] ?? null;
            $y = $term[$prefix . 'Year'] ?? null;
            return ($s !== null && $s !== '' && $y !== null && $y !== '') ? [ucfirst((string)$s) . ' ' . (int)$y] : [];
        };
        if ($isCurrent) {
            // A current row's Period sets its start only; its end stays as stored.
            $texts = array_unique(array_merge($entered('Start'), self::sideNames($want['start'])));
            foreach ($texts as $text) {
                if (self::readsBackAs($text, true, $want)) {
                    return $text;
                }
            }
            return '';
        }
        return self::spanText($want['start'], $want['end'], $entered('Start'), $entered('End'));
    }

    /**
     * Period text to type back in for the span $from..$to ($to null: end
     * unknown): the shortest names that parse() reads back to exactly those
     * ordinals. spanLabel() is the display form.
     */
    public static function inputText(int $from, ?int $to): string
    {
        return self::spanText($from, $to, [], []);
    }

    /** Candidate-and-verify: the first text, preferred names first, that reads back as $from..$to. */
    private static function spanText(int $from, ?int $to, array $startFirst, array $endFirst): string
    {
        $want = ['start' => $from, 'end' => $to];
        $starts = array_values(array_unique(array_merge($startFirst, self::sideNames($from))));
        $texts = [];
        if ($to === null) {
            foreach ($starts as $a) {
                $texts[] = $a . ' –';
            }
        } else {
            if ($to === $from) {
                $texts = $starts;
            }
            $ends = array_values(array_unique(array_merge($endFirst, self::sideNames($to))));
            foreach ($starts as $a) {
                foreach ($ends as $b) {
                    $texts[] = $a . ' – ' . $b;
                }
            }
        }
        foreach ($texts as $text) {
            if (self::readsBackAs($text, false, $want)) {
                return $text;
            }
        }
        // Unreachable: the two-year winter name is unambiguous.
        return $to === null ? self::ordinalLabel($from) . ' –' : self::spanLabel($from, $to);
    }

    /** The names that may mean the season $ordinal, short forms first. */
    private static function sideNames(int $ordinal): array
    {
        $i = (($ordinal % 4) + 4) % 4;
        $y = intdiv($ordinal - $i, 4);
        if ($i !== 0) {
            return [ucfirst(self::SEASONS[$i]) . ' ' . $y];
        }
        return ['Winter ' . $y, 'Winter ' . ($y - 1), self::ordinalLabel($ordinal)];
    }

    /**
     * Whether $text, saved as a Period, gives $want. $startOnly: a current row's
     * Period, which sets the start only (its end stays as stored).
     */
    private static function readsBackAs(string $text, bool $startOnly, array $want): bool
    {
        $p = self::parse($text);
        if (is_string($p)) {
            return false;
        }
        if ($startOnly) {
            return $p['single'] && $p['start']['ordinal'] === $want['start'];
        }
        return ['start' => $p['start']['ordinal'], 'end' => $p['end']['ordinal'] ?? null] === $want;
    }

    /** "Spring 2023"; a winter is named by both its years, "Winter 2022–23" (or "Winter 1999–2000"). */
    public static function ordinalLabel(int $ordinal): string
    {
        $i = (($ordinal % 4) + 4) % 4;
        $y = intdiv($ordinal - $i, 4);
        if ($i !== 0) {
            return ucfirst(self::SEASONS[$i]) . ' ' . $y;
        }
        $tail = intdiv($y - 1, 100) === intdiv($y, 100) ? sprintf('%02d', $y % 100) : (string)$y;
        return 'Winter ' . ($y - 1) . '–' . $tail;
    }

    /** "Winter 2022–23", or "Fall 2022 – Spring 2023" for a span of seasons. */
    public static function spanLabel(int $from, int $to): string
    {
        return $from === $to
            ? self::ordinalLabel($from)
            : self::ordinalLabel($from) . ' – ' . self::ordinalLabel($to);
    }

    /**
     * Period text to pre-fill for the term before: same length, ending at
     * $start. The term before a single season is the single season before it.
     * Typed back in, it reads back as that span (inputText()).
     */
    public static function previousSpan(int $start, ?int $end): string
    {
        if ($end !== null && $end <= $start) {
            return self::inputText($start - 1, $start - 1);
        }
        $len = $end === null ? 2 : $end - $start;
        return self::inputText($start - $len, $start);
    }

    /**
     * Read a typed period back for the ledger before it is saved: the label the
     * report will show, its ordinals and the span to pre-fill for the term before.
     * Applies the save rules the reader can know about (no "present", no future
     * start or end); the server still validates on save.
     *
     * $isCurrent: the period is a current term's start, so it must be a single
     * season ("Spring 24"); the end belongs to Manage Officers.
     *
     * @return array|string error message, or [Label, StartOrdinal, EndOrdinal, PreviousSpan]
     */
    public static function readBack(string $text, bool $isCurrent = false, ?int $nowOrdinal = null): array|string
    {
        if ($isCurrent && self::isSpan($text)) {
            return 'Enter only the start of a current term, e.g. "Spring 24". Its end is set from Manage Officers.';
        }
        $p = self::parse($text);
        if (is_string($p)) {
            return $p;
        }
        $ord = [
            'start' => $p['start']['ordinal'],
            'end' => (!$isCurrent && $p['end'] !== null) ? $p['end']['ordinal'] : null,
        ];
        $nowOrdinal ??= self::currentOrdinal();
        if ($ord['start'] > $nowOrdinal) {
            return 'A term cannot start in the future.';
        }
        if ($ord['end'] !== null && $ord['end'] > $nowOrdinal) {
            return 'A term cannot end in the future.';
        }
        return [
            'Label' => self::label(['StartOrdinal' => $ord['start'], 'EndOrdinal' => $ord['end']], $isCurrent),
            'StartOrdinal' => $ord['start'],
            'EndOrdinal' => $ord['end'],
            'PreviousSpan' => self::previousSpan($ord['start'], $ord['end']),
        ];
    }

    public static function currentOrdinal(?string $today = null): int
    {
        $ts = strtotime($today ?? 'today');
        return self::ordinal(null, null, date('Y-m-d', $ts), 'start', null);
    }
}
