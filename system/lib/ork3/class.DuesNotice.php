<?php

/**
 * Dues expiry notice: the pure classification behind the site-wide banner and
 * the profile alert. No database; the caller supplies the player's furthest
 * non-revoked dues-until date (see Player::GetDuesStanding) and today.
 *
 * A player hears about their dues in a window on either side of the date:
 * WARN_DAYS before it ("expire in N days") and LAPSED_DAYS after it ("lapsed
 * N days ago"). Outside that window, and for lifetime members and players with
 * no dues on file, there is nothing to say.
 */
class DuesNotice
{
    /** Days before dues-until during which the "expiring" notice shows. */
    public const WARN_DAYS = 30;

    /** Days after dues-until during which the "lapsed" notice shows. */
    public const LAPSED_DAYS = 30;

    /**
     * @param string|null $duesUntil Y-m-d, '' or null when the player has no dues row
     * @param bool        $forLife   any non-revoked lifetime row
     * @param string|null $today     Y-m-d, defaults to the PHP (Central) date, never CURDATE()
     * @return array{State: string, Days: int, Until: string}|null
     *   State 'expiring' with Days left (0 = today), or 'lapsed' with Days since (1 = yesterday)
     */
    public static function classify($duesUntil, bool $forLife, $today = null): ?array
    {
        if ($forLife) {
            return null;
        }
        $duesUntil = trim((string)$duesUntil);
        if ($duesUntil === '' || $duesUntil === '0000-00-00' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $duesUntil)) {
            return null;
        }
        $today = $today ?: date('Y-m-d');
        $until = DateTime::createFromFormat('!Y-m-d', $duesUntil);
        $now   = DateTime::createFromFormat('!Y-m-d', $today);
        if (!$until || !$now) {
            return null;
        }
        $delta = (int)$now->diff($until)->format('%r%a');  // positive = still in the future
        if ($delta >= 0 && $delta <= self::WARN_DAYS) {
            return ['State' => 'expiring', 'Days' => $delta, 'Until' => $duesUntil];
        }
        if ($delta < 0 && -$delta <= self::LAPSED_DAYS) {
            return ['State' => 'lapsed', 'Days' => -$delta, 'Until' => $duesUntil];
        }
        return null;
    }

    /**
     * The sentence shown to the player. Plain text, no HTML.
     *
     * @param array{State: string, Days: int, Until: string} $notice
     */
    public static function message(array $notice): string
    {
        $when = date('M j', strtotime($notice['Until']));
        $days = (int)$notice['Days'];
        if ($notice['State'] === 'lapsed') {
            if ($days === 1) {
                return 'Your dues lapsed yesterday (' . $when . ').';
            }
            return 'Your dues lapsed ' . $days . ' days ago (' . $when . ').';
        }
        if ($days === 0) {
            return 'Your dues expire today.';
        }
        if ($days === 1) {
            return 'Your dues expire tomorrow (' . $when . ').';
        }
        return 'Your dues expire in ' . $days . ' days (' . $when . ').';
    }

    /**
     * Stable key for "the player dismissed this notice". Changes when the dues
     * date changes (a renewal) or the notice crosses from expiring to lapsed, so
     * a dismissal never hides the next thing worth saying.
     *
     * @param array{State: string, Days: int, Until: string} $notice
     */
    public static function dismissKey(array $notice): string
    {
        return $notice['State'] . ':' . $notice['Until'];
    }
}
