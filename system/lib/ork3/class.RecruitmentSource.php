<?php

/**
 * "How did you find us?" — the recruitment source recorded on a player
 * (ork_mundane.recruitment_source). Asked at registration and backfillable
 * from the Account modal; read by the Player Recruitment Report.
 *
 * The column stores the code, never the label, so labels can be reworded
 * without touching history. '' means "not answered", which is deliberately
 * distinct from 'other' ("Other / prefer not to say").
 *
 * Not JSON-whitelisted; pure data, no DB access.
 */
class RecruitmentSource
{
    /** Ordered code => label; the order is the dropdown and report order. */
    public const CODES = [
        'friend_family'   => 'Friend or family member invited me',
        'cross_larp'      => 'Cross-gaming from a different LARP',
        'search_website'  => 'Google search / official website',
        'social_media'    => 'Social media (Facebook, Instagram, Reddit, etc.)',
        'walk_up'         => 'Walk-up at a local gathering',
        'convention_demo' => 'Demo or event at a convention, Renn fair, etc.',
        'flyer'           => 'Saw a flyer, brochure, poster, etc.',
        'other'           => 'Other / prefer not to say',
    ];

    public const NOT_ANSWERED_LABEL = 'Not answered';

    /** A valid code, or '' for anything else (including null). */
    public static function normalize($value)
    {
        $value = is_string($value) ? trim($value) : '';
        return array_key_exists($value, self::CODES) ? $value : '';
    }

    /** Label for a stored code; '' and unknown codes read as "Not answered". */
    public static function label($code)
    {
        return self::CODES[$code] ?? self::NOT_ANSWERED_LABEL;
    }
}
