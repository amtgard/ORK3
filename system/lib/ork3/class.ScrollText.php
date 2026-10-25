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
     * The last four keys echo the effective form after any split: the title, flags and
     * name that, fed back in, print the same lines. The composer prefills its fields
     * from them, so "name" keeps its article ("the Wetlands") — the place alone would
     * lose it on the way back.
     *
     * $scopeType ('park', 'kingdom' or '') is part of the signature because spec 5.2
     * lists it, but no rule keys off it today: the unsplit-kingdom test below is
     * decided by the title and flags, which is also how a stored kingdom looks.
     *
     * @return array{lead: string, article: string, place: string, title: string, useThe: bool, useOf: bool, name: string}
     */
    public static function IssuerLines(
        string $title,
        string $name,
        bool $useThe,
        bool $useOf,
        string $scopeType = ''
    ): array {
        $title = self::Tidy($title);
        $name  = self::Tidy($name);

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

        return [
            'lead'    => $lead,
            'article' => $article,
            'place'   => $place,
            'title'   => $title,
            'useThe'  => $useThe,
            'useOf'   => $useOf,
            'name'    => $name,
        ];
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
