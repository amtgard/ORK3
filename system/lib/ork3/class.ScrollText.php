<?php

declare(strict_types=1);

/**
 * The issuer text model: stored issuer strings in, display parts out.
 *
 * Pure — no database, no DOM. The browser has a twin of this file,
 * orkui/template/revised-frontend/script/scrollery-textmodel.js, so the composer
 * splits an issuer exactly the way print will. Both are tested against one fixture,
 * tests/fixtures/scroll-text-model.json; change a rule here and that file and the
 * twin change with it. Rules are spec section 5.2.
 *
 * NOTE: startup.php scans system/lib/ork3 and instantiates every class it finds with
 * `new ClassName()`. This class therefore takes no constructor arguments and does not
 * extend Ork3, which would drag its bootstrap into a pure string function. Everything
 * useful is static.
 */
class ScrollText
{
    /**
     * Rank words a kingdom-level name can carry. "Freeholds" is plural on purpose:
     * "The Freeholds of Amtgard" is the stored name, not a typo.
     */
    private const KINGDOM_RANKS = 'Kingdom|Empire|Principality|Grand Duchy|Duchy|Freeholds|Confederacy';

    /**
     * Turn one issuer into the pieces a certificate sets in separate sizes.
     *
     * "lead" is the connective after "Amtgard" ("and the Barony of", or just "and"),
     * "article" is "the" or '' (set at connective size, so it is split from the place
     * rather than left in it), and "place" is the proper name.
     *
     * The next four keys echo the effective form after any split: the title, flags and
     * name that, fed back in, print the same lines. The composer prefills its fields
     * from them, so "name" keeps its article ("the Wetlands") — the place alone would
     * lose it on the way back.
     *
     * "phrase" is the issuer as a sentence wants it after "of" — the zodiac flavour
     * text reads "a shining star of <<ISSUER>>". It is PHP-only (the browser never
     * writes flavour), so it is not in the shared fixture. A kingdom reads as
     * "the Kingdom of the Wetlands", the same whether it arrived whole or already
     * split by the composer; with no rank word it is the name as it stands ("The
     * Celestial Kingdom"). A park, or an issuer of no known scope, is the name exactly
     * as stored, which is what the flavour always printed for them. $scopeType
     * ('park', 'kingdom' or '') decides only that; the lines above do not depend on it.
     *
     * @return array{lead: string, article: string, place: string, title: string, useThe: bool, useOf: bool, name: string, phrase: string}
     */
    public static function IssuerLines(
        string $title,
        string $name,
        bool $useThe,
        bool $useOf,
        string $scopeType = ''
    ): array {
        $stored = $name;
        $title  = self::Tidy($title);
        $name   = self::Tidy($name);

        // "Inactive" is a park status that leaked into ork_parktitle.title on 5 parks.
        // As a rank word it prints "and the Inactive of".
        if (strtolower($title) === 'inactive') {
            $title = '';
        }

        // A stored kingdom is one whole phrase with no separate title or flags, so
        // "and the" + title + "of" would print the rank word twice. Take the phrase
        // apart instead. It only applies to that stored shape — an officer who has
        // typed a title or ticked either box has already said what they want. A name
        // that does not read "<rank> of <place>" ("The Celestial Kingdom") is left
        // whole. Þe is the archaic article one retired kingdom used.
        if ($title === '' && !$useThe && !$useOf) {
            $split = '/^(?:(?:The|Þe)\s+)?(' . self::KINGDOM_RANKS . ')\s+of\s+(.+)$/iu';
            if (preg_match($split, $name, $m) === 1) {
                $title  = $m[1];
                $useThe = true;
                $useOf  = true;
                $name   = $m[2];
            }
        }

        // The name can already carry the rank word the title names ("The Principality
        // of the Crimson Sands" under title Principality), which printed it twice. Keep
        // the title and let the name be the place. Only the same rank word followed by
        // " of" counts: a different one ("Principality of X" under Barony) is as typed.
        if ($title !== ''
            && preg_match('/^(?:(?:The|Þe)\s+)?' . preg_quote($title, '/') . '\s+of\s+(.+)$/iu', $name, $m) === 1) {
            $name = $m[1];
        }

        // "the" and "of" cannot stand without a rank word to hang on: with an empty
        // title they printed "and the  of".
        $lead = 'and';
        if ($title !== '') {
            $lead .= ($useThe ? ' the' : '') . ' ' . $title . ($useOf ? ' of' : '');
        }

        // Lifted out of the place only when the lead ends in "of", so it reads as
        // "...Kingdom of the Wetlands" with a small "the". Where the lead does not,
        // the article is part of an unsplit name ("The Celestial Kingdom") and stays.
        $article = '';
        $place   = $name;
        if (str_ends_with($lead, ' of') && preg_match('/^the\s+(.+)$/iu', $name, $m) === 1) {
            $article = 'the';
            $place   = $m[1];
        }

        // The rank word is always introduced with "the" here, whatever the flags say:
        // flavour is a sentence ("star of the Kingdom of the Wetlands"), not the
        // certificate's own line, so there is no "and" for the article to follow.
        $phrase = $stored;
        if ($scopeType === 'kingdom') {
            $phrase = $title !== ''
                ? 'the ' . $title . ' of ' . ($article !== '' ? 'the ' : '') . $place
                : $place;
        }

        return [
            'lead'    => $lead,
            'article' => $article,
            'place'   => $place,
            'title'   => $title,
            'useThe'  => $useThe,
            'useOf'   => $useOf,
            'name'    => $name,
            'phrase'  => $phrase,
        ];
    }

    /**
     * The rank word BuildScroll hands to IssuerLines(): the title as given, with the two
     * corrections the PRINT applies before the model runs.
     *
     * "Inactive" is a park status that leaked into ork_parktitle.title on 5 parks, not a
     * rank word, so it reads as empty. And an empty title on a PARK takes the Barony
     * floor ("and the Barony of / Wetlands"), because park names are bare and need a rank
     * word to read at all; a kingdom supplies its own whole phrase and has no floor.
     * ScrolleryText.issuerLines() applies the same two rules for scope 'park', so the
     * composer's preview says what the print says; tests/fixtures/scroll-text-model.json
     * pins both.
     */
    public static function IssuerTitle(string $title, string $scopeType): string
    {
        $title = trim($title);
        if (strtolower($title) === 'inactive') {
            $title = '';
        }
        if ($title === '' && $scopeType === 'park') {
            $title = 'Barony';
        }

        return $title;
    }

    /**
     * The characters Windows-1252 puts in the 0x80-0x9F byte range, by Unicode code
     * point, mapped back to that byte. The five bytes it leaves undefined (0x81, 0x8D,
     * 0x8F, 0x90, 0x9D) are absent: a decoder that kept them arrives as the C1 control
     * U+0081 etc., which is already inside the U+0080-U+00BF range and maps to itself.
     */
    private const WIN1252 = [
        0x20AC => 0x80, 0x201A => 0x82, 0x0192 => 0x83, 0x201E => 0x84, 0x2026 => 0x85,
        0x2020 => 0x86, 0x2021 => 0x87, 0x02C6 => 0x88, 0x2030 => 0x89, 0x0160 => 0x8A,
        0x2039 => 0x8B, 0x0152 => 0x8C, 0x017D => 0x8E, 0x2018 => 0x91, 0x2019 => 0x92,
        0x201C => 0x93, 0x201D => 0x94, 0x2022 => 0x95, 0x2013 => 0x96, 0x2014 => 0x97,
        0x02DC => 0x98, 0x2122 => 0x99, 0x0161 => 0x9A, 0x203A => 0x9B, 0x0153 => 0x9C,
        0x017E => 0x9E, 0x0178 => 0x9F,
    ];

    /**
     * One mojibake run: a UTF-8 lead byte as Windows-1252 shows it (U+00C2-U+00F4),
     * followed by as many continuation bytes as that lead takes (the ~ below stands for
     * one), each shown as a U+0080-U+00BF character or one of the Windows-1252 glyphs
     * in WIN1252. "Ã©" is
     * the two bytes of "é"; "â€œ" is the three bytes of the left double quote.
     *
     * The same pattern is in the browser twin (ScrolleryText.repairMojibake); keep
     * them in step.
     */
    private const MOJIBAKE_RUN = '/[\x{C2}-\x{DF}]~|[\x{E0}-\x{EF}]~{2}|[\x{F0}-\x{F4}]~{3}/u';
    private const MOJIBAKE_CONT = '[\x{80}-\x{BF}\x{20AC}\x{201A}\x{0192}\x{201E}\x{2026}\x{2020}\x{2021}'
        . '\x{02C6}\x{2030}\x{0160}\x{2039}\x{0152}\x{017D}\x{2018}\x{2019}\x{201C}\x{201D}\x{2022}'
        . '\x{2013}\x{2014}\x{02DC}\x{2122}\x{0161}\x{203A}\x{0153}\x{017E}\x{0178}]';

    /**
     * Repair text that was UTF-8, was decoded as Windows-1252 somewhere upstream and
     * was encoded to UTF-8 again, so a left quote reads "â€œ" and "é" reads "Ã©".
     * The stored data is left alone; this runs where text becomes a certificate.
     *
     * Each run of the shape MOJIBAKE_RUN is mapped back to its Windows-1252 bytes and
     * kept only if those bytes are valid UTF-8 and the result is not itself a mojibake
     * run. Anything else, and every character outside a run, is returned exactly as it
     * came in, so clean text ("Främling i Landet Vårdare", "Şadhibek", "Café", "naïve",
     * emoji) passes through untouched, as does a lone "Ã" that is only a letter
     * ("Ãngel"). Twin of ScrolleryText.repairMojibake().
     */
    public static function RepairMojibake(string $s): string
    {
        // Nothing to do for ASCII, and invalid UTF-8 is not ours to guess at.
        if ($s === '' || !preg_match('/[^\x00-\x7F]/', $s) || preg_match('//u', $s) !== 1) {
            return $s;
        }

        $run = str_replace('~', self::MOJIBAKE_CONT, self::MOJIBAKE_RUN);

        $out = preg_replace_callback($run, static function (array $m) use ($run): string {
            $bytes = '';
            foreach (preg_split('//u', $m[0], -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
                $cp = mb_ord($ch, 'UTF-8');
                if (isset(self::WIN1252[$cp])) {
                    $bytes .= chr(self::WIN1252[$cp]);
                } elseif ($cp <= 0xFF) {
                    $bytes .= chr($cp);
                } else {
                    return $m[0];
                }
            }
            if (preg_match('//u', $bytes) !== 1 || preg_match($run, $bytes) === 1) {
                return $m[0];
            }

            return $bytes;
        }, $s);

        return $out ?? $s;
    }

    /**
     * Trim and collapse whitespace. The unicode flag makes \s cover the no-break space
     * the way the browser's \s already does; a string that is not valid UTF-8 falls
     * back to ASCII whitespace rather than being blanked.
     */
    private static function Tidy(string $s): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', $s) ?? preg_replace('/\s+/', ' ', $s) ?? $s;

        return trim($collapsed);
    }
}
