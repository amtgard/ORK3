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
export type TextProfile = 'max' | 'empty' | 'caseZero';

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

const rep = (n: number) => 'Wilhelmina Featherstonehaugh de la Mancha'.repeat(10).slice(0, n);

export const PROFILES: Record<TextProfile, TextFields> = {
  /* Every field at its derived maximum. 'W' and 'm'-heavy source text on purpose:
     the widest glyphs are what reach a frame first, and a max-LENGTH string of thin
     letters is not a max-WIDTH line. */
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

export const PROFILE_NAMES: TextProfile[] = ['max', 'empty', 'caseZero'];
