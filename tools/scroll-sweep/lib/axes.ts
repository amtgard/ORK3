import { readFileSync } from 'node:fs';
import { join } from 'node:path';

export type OrderVariant = { order: string; variant: string };

export type Axes = {
  orderVariants: OrderVariant[];
  ranks: number[];
  hands: string[];
  grounds: string[];
  borders: string[];
  groundsWithOwnBorder: string[];
  rankSets: string[];
  caps: { customName: number };
};

/**
 * The harness never restates Scrollery's vocabulary; it reads what
 * bin/dump-axes.php dumped out of the PHP data files. Regenerate with:
 *   php tools/scroll-sweep/bin/dump-axes.php > tools/scroll-sweep/axes.json
 */
export function loadAxes(path = join(__dirname, '..', 'axes.json')): Axes {
  return JSON.parse(readFileSync(path, 'utf8')) as Axes;
}
