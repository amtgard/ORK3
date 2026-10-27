import { join } from 'node:path';
import { parseTag } from './combo-rerun';

/** The original 150-combination sweep: matrix.json, images/, results.json. Never written by a rerun. */
export const COMBO_BASE = process.env.ORK3_COMBO_OUT ?? join(__dirname, '..', 'out', 'combo150');

/** Set (with ORK3_COMBO_IDS) for a targeted rerun: output goes to out/combo150-rerun/<tag>/. */
export const COMBO_TAG = process.env.ORK3_COMBO_TAG ? parseTag(process.env.ORK3_COMBO_TAG) : '';
export const COMBO_IDS = process.env.ORK3_COMBO_IDS ?? '';
export const COMBO_RERUN = !!COMBO_TAG;

/** Where the sweep writes. Under out/, which is not committed. A rerun never writes COMBO_BASE. */
export const COMBO_OUT = COMBO_RERUN ? join(COMBO_BASE, '..', 'combo150-rerun', COMBO_TAG) : COMBO_BASE;
