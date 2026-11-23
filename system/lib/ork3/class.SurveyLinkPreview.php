<?php

/**
 * The logged-out face of a survey link (Survey/s/{slug}, Survey/take/{id}):
 * the OpenGraph card a Discord or Facebook crawler turns into an embed, and
 * the lines the LoginRequired page shows a person who clicked it. Pure: the
 * controller resolves the row, scope label and credit flag, this shapes them.
 * See Controller::require_login() for the array it returns.
 */
class SurveyLinkPreview
{
    /** Longest description snippet carried into the card. */
    public const SNIPPET_MAX = 140;

    /**
     * @param array|null $row          ork_survey row, or null when the link resolves to nothing
     * @param string     $scopeLabel   kingdom or park name; ignored for 'ork'
     * @param bool       $offersCredit any org's bonus-credit switch is on
     * @param string     $url          canonical URL for og:url (the share link)
     * @param string     $today        Y-m-d, for the close-date comparison (tests pin it)
     */
    public static function build(?array $row, string $scopeLabel, bool $offersCredit, string $url, string $today = ''): array
    {
        $today = $today !== '' ? $today : date('Y-m-d');
        if ($row === null) {
            return [
                'og' => [
                    'title'       => 'Survey not found',
                    'url'         => $url,
                    'description' => 'This survey link is invalid or the survey has been removed.',
                ],
                'kicker'  => 'Amtgard survey',
                'heading' => 'Survey not found',
                'lines'   => [
                    'This survey link is invalid or the survey has been removed.',
                    'Ask whoever posted it for a fresh link.',
                ],
                'button'  => 'Log in to the ORK',
            ];
        }

        $scopeType = (string) ($row['scope_type'] ?? 'ork');
        $title     = trim((string) ($row['title'] ?? '')) ?: 'Amtgard survey';
        $kickers   = ['ork' => 'Amtgard survey', 'kingdom' => 'Kingdom survey', 'park' => 'Park survey'];
        $kicker    = $kickers[$scopeType] ?? 'Amtgard survey';

        $lines = [];
        $snippet = self::snippet((string) ($row['description'] ?? ''));
        if ($snippet !== '') {
            $lines[] = $snippet;
        }
        $lines[] = 'For ' . self::audience($scopeType, $scopeLabel) . '.';

        $state = self::state($row, $today);
        if ($state === 'open') {
            if ($offersCredit) {
                $lines[] = 'Earns a bonus credit toward class levels.';
            }
            $closeAt = (string) ($row['close_at'] ?? '');
            if ($closeAt !== '' && $closeAt !== '0000-00-00 00:00:00') {
                $lines[] = 'Closes ' . date('M j', strtotime($closeAt)) . '.';
            }
            $login  = 'Log in to the ORK to take it.';
            $button = 'Log in to take the survey';
        } elseif ($state === 'not_yet') {
            $lines[] = 'This survey is not open yet.';
            $login  = 'Log in to the ORK to see your surveys.';
            $button = 'Log in to the ORK';
        } else {
            $lines[] = 'This survey is closed.';
            $login  = 'Log in to the ORK to see your surveys.';
            $button = 'Log in to the ORK';
        }

        return [
            'og' => [
                'title'       => $title,
                'url'         => $url,
                'description' => implode(' ', $lines),
            ],
            'kicker'  => $kicker,
            'heading' => $title,
            'lines'   => array_merge($lines, [$login]),
            'button'  => $button,
        ];
    }

    /** "every Amtgard player" / "players of X" / "members of X" (matches the runner's refusal wording). */
    public static function audience(string $scopeType, string $scopeLabel): string
    {
        $scopeLabel = trim($scopeLabel);
        if ($scopeType === 'kingdom' && $scopeLabel !== '') {
            return 'players of ' . $scopeLabel;
        }
        if ($scopeType === 'park' && $scopeLabel !== '') {
            return 'members of ' . $scopeLabel;
        }
        return 'every Amtgard player';
    }

    /** open | not_yet | closed, from status and the schedule as the runner reads them. */
    public static function state(array $row, string $today): string
    {
        $status = (string) ($row['status'] ?? 'draft');
        if ($status !== 'open') {
            return 'closed';
        }
        $openAt  = (string) ($row['open_at'] ?? '');
        $closeAt = (string) ($row['close_at'] ?? '');
        if ($openAt !== '' && $openAt !== '0000-00-00 00:00:00' && substr($openAt, 0, 10) > $today) {
            return 'not_yet';
        }
        if ($closeAt !== '' && $closeAt !== '0000-00-00 00:00:00' && substr($closeAt, 0, 10) < $today) {
            return 'closed';
        }
        return 'open';
    }

    /** First SNIPPET_MAX chars of the description, markdown markers and newlines stripped. */
    public static function snippet(string $description): string
    {
        $s = preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $description); // [text](url) -> text
        $s = preg_replace('/[\[\]#*_>`]/', '', (string) $s);
        $s = trim(preg_replace('/\s+/', ' ', (string) $s));
        if ($s === '') {
            return '';
        }
        if (function_exists('mb_strlen') && mb_strlen($s) > self::SNIPPET_MAX) {
            return rtrim(mb_substr($s, 0, self::SNIPPET_MAX - 1)) . '…';
        }
        if (strlen($s) > self::SNIPPET_MAX) {
            return rtrim(substr($s, 0, self::SNIPPET_MAX - 1)) . '…';
        }
        return $s;
    }
}
