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

    /** Short code => label for chart axes and legends (full labels stay in tooltips and tables). */
    public const SHORT = [
        'friend_family'   => 'Friend/family',
        'cross_larp'      => 'Other LARP',
        'search_website'  => 'Search/website',
        'social_media'    => 'Social media',
        'walk_up'         => 'Walk-up',
        'convention_demo' => 'Convention/fair',
        'flyer'           => 'Flyer',
        'other'           => 'Other',
    ];

    public const NOT_ANSWERED_LABEL = 'Not answered';

    /** How an answer was recorded (ork_mundane.recruitment_source_via). */
    public const VIA_REGISTRATION = 'registration';
    public const VIA_BACKFILL     = 'backfill';

    /** Shared copy for every "How did you find us?" field. */
    public const FIELD_LABEL = 'How did you find us?';

    /** Officer create-player modals: the officer asks the player in front of them. */
    public const OFFICER_PLACEHOLDER = '— Not asked —';
    public const OFFICER_HINT = "Ask the new player. This powers the Player Recruitment report. Leave blank if they weren't asked.";

    /** Player-facing forms (self-registration, the Account modal). */
    public const SELF_PLACEHOLDER = '— Select —';
    public const SELF_HINT = 'Optional. Visible to your park and kingdom officers to help with recruiting.';

    /**
     * Everything a form needs to render the field: options, label, placeholder
     * and hint. $audience is 'officer' (create-player modals) or 'self'.
     */
    public static function field($audience)
    {
        $officer = $audience === 'officer';
        return [
            'Options'     => self::CODES,
            'Label'       => self::FIELD_LABEL,
            'Placeholder' => $officer ? self::OFFICER_PLACEHOLDER : self::SELF_PLACEHOLDER,
            'Hint'        => $officer ? self::OFFICER_HINT : self::SELF_HINT,
        ];
    }

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
