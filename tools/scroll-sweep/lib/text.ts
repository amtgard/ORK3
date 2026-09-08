/**
 * The three text profiles.
 *
 * Every length here is DERIVED from the ORK's own data (see the plan's Global
 * Constraints table), not invented. Inventing them is how the crown collision hid on
 * "9th August 2019" and appeared on "27th September 2026".
 *
 * `empty` is an extreme in its own right and not merely the absence of one: the
 * garber collision was invisible with a blank signer and visible with a real name.
 */
export type TextProfile = 'max' | 'real' | 'empty' | 'caseZero';

export type TextFields = {
  player: string;
  reason: string;
  givenBy: string;
  date: string;
  issuerName: string;
  issuerTitle: string;
  useThe: boolean;
  useOf: boolean;
  customName: string;
};

/*
 * TWO different worst cases, because they break different things.
 *
 * `max` is SYNTHETIC: every field padded to its derived cap by repeating a phrase.
 * The repetition manufactures 16-character unbreakable tokens, and those turn out to
 * be the sharper test — a fixed-width box cannot wrap one, so it overflows across the
 * page instead, and a fitting pass that counts line boxes sees an overflowing token as
 * one perfectly good line. 71 personas in the ORK do contain no space at all, so this
 * is a real shape of input even though the exact string is not a real value.
 *
 * `real` is the LONGEST ACTUAL VALUES in the ORK, verbatim: the 100-character persona,
 * the 399-character citation, the 37-character principality name, the longest
 * space-free persona as a signature. Derived rather than invented, which is what the
 * brief for this work asks for.
 *
 * Both are swept. `max` is also what the frozen baseline was measured against, so it
 * is the profile the regression diff can speak about; `real` is newer coverage and has
 * no "before".
 */
const rep = (n: number) => 'Wilhelmina Featherstonehaugh de la Mancha'.repeat(10).slice(0, n);

export const PROFILES: Record<TextProfile, TextFields> = {
  max: {
    player: rep(100),
    reason: rep(400),
    givenBy: rep(60),
    date: '27th September 2026',
    issuerName: 'The Principality of the Crimson Sands',   // 37 chars = ork_kingdom.name max
    issuerTitle: 'Principality',
    useThe: true,
    useOf: true,
    customName: rep(64),
  },
  real: {
    /* The longest persona in the ORK, 100 characters. */
    player: 'Darth P-Nut,  Kenjitsu Master of the Lost Art of Peepee Bashing,  Lord UltraSluff of the Nuckleduste',
    /* The longest ork_awards.note, truncated to the 400-character maximum. */
    reason: 'Brill was essential for the successful organization and completion of Night Quest this year at Keep, cap stoning a tradition of becoming heavily involved in the successful organization and completion of quests at BFP and Midgard for half a decade, Kingdom for a few years now, and Keep twice. While Lando`s own brilliant performance has been recommended already, he was able to rely on a strong team',
    /* The longest persona containing NO space — the unbreakable case, which is what
       overflows a fixed-width box instead of wrapping in it. */
    givenBy: "Smiles/Hobi/Can'tCallHimSmileyCanWe?",
    /* The longest date this app can print. The crown collision was invisible on
       "9th August 2019" and obvious on this. */
    date: '27th September 2026',
    /* ork_kingdom.name max, 37 characters. */
    issuerName: 'The Principality of the Crimson Sands',
    issuerTitle: 'Principality',
    useThe: true,
    useOf: true,
    /* Scroll::CUSTOM_NAME_MAX_LENGTH is 64; the longest real catalogue name is 35. */
    customName: 'Guildmaster of Reeves, Principality of the Crimson Sands Order',
  },
  /* Nothing optional filled in. Exercises the writing-line blanks, the min-height
     reservations, and the branch where an empty reason computes its size from 200. */
  empty: {
    player: '',
    reason: '',
    givenBy: '',
    date: '',
    issuerName: '',
    issuerTitle: '',
    useThe: false,
    useOf: false,
    customName: '',
  },
  /* The reported reproduction, verbatim. */
  caseZero: {
    player: '',
    reason: '',
    givenBy: '',
    date: '',
    issuerName: 'Crystal Groves',
    issuerTitle: 'Kingdom',
    useThe: true,
    useOf: true,
    customName: '',
  },
};

export const PROFILE_NAMES: TextProfile[] = ['max', 'real', 'empty', 'caseZero'];
