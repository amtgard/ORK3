<?php

/***
 * ConfigRegistry
 *
 * Source of truth for the kingdom configuration settings an officer is allowed to
 * see and change. ork_configuration is a free-form key/value bag: anything any part
 * of the app has ever written for a kingdom lands in it, including internal pointers
 * that must never be exposed as a text box. Rendering that table raw is what produced
 * the current Configuration modal, where officers are shown database keys
 * ('QualTestReeveEnabled') and raw values ('1').
 *
 * This registry is the allow-list AND the presentation contract:
 *   - a key absent from here is NOT rendered and NOT accepted on save;
 *   - every key present carries an officer-facing label, help text, a control type,
 *     the values that control may produce, and a group for sectioning the modal.
 *
 * DELIBERATE EXCLUSION -- Treasury 'AccountPointers'.
 * Treasury::create_accounts() writes an 'AccountPointers' config row (var_type
 * 'mixed') holding the primary keys of the kingdom's ledger accounts -- Imbalance,
 * Equity, Asset, Cash, Checking, Income, Liability and the rest. Those ids are
 * machine plumbing. Editing one silently re-points every future dues transaction at
 * the wrong ledger account, and the damage is invisible until the books are
 * reconciled. It is excluded on purpose and must stay excluded: it is not a setting,
 * it is an internal foreign-key map. The same reasoning applies to any future
 * pointer-style key -- if a human would never knowingly type the value, it does not
 * belong in this registry.
 *
 * WHY CONTROL TYPE LIVES HERE AND NOT IN THE DATABASE.
 * Common::add_config() writes `var_type = $type` -- the scope ('Kingdom'), not the
 * declared value type. Every kingdom row therefore reports var_type 'Kingdom', so the
 * column cannot be trusted to tell a colour picker from a checkbox. The control type
 * below is authoritative.
 *
 * Auto-loaded by startup.php as Ork3::$Lib->configregistry; all accessors are static.
 ***/

class ConfigRegistry extends Ork3
{
    /** Control types. The modal maps these to widgets; Validate() enforces them. */
    public const CONTROL_BOOLEAN = 'boolean';   // stored '0' / '1'
    public const CONTROL_SELECT  = 'select';    // stored value must be a key of 'options'
    public const CONTROL_NUMBER  = 'number';    // numeric, optionally blank
    public const CONTROL_COLOR   = 'color';     // 6 hex digits, stored WITHOUT a leading '#'
    public const CONTROL_TEXT    = 'text';      // free text, length-capped
    public const CONTROL_PERIOD  = 'period';    // composite {Period: number, Type: select}

    /**
     * Groups, in the order the modal should render them.
     * Format: 'group_key' => 'Section heading'
     */
    private static $groups = [
        'attendance' => 'Attendance & Activity',
        'dues'       => 'Dues',
        'awards'     => 'Awards & Recommendations',
        'qualtest'   => 'Qualification Tests',
        'statistics' => 'Statistics & Reporting',
        'appearance' => 'Appearance',
    ];

    /**
     * Master list of editable kingdom configuration keys.
     *
     * Keys match exactly what Kingdom::create_kingdom() seeds per kingdom (see
     * class.Kingdom.php) plus the two keys backfilled by db-migrations
     * (AwardRecsPublic, IncludePrincipalityInStatistics). Nothing else is editable.
     *
     * Common fields:
     *   'label'   string  Officer-facing name. NEVER show the raw key.
     *   'help'    string  One line, officer vocabulary, no database words.
     *   'control' string  One of the CONTROL_* constants.
     *   'group'   string  A key of self::$groups.
     *
     * Per-control fields:
     *   select  'options'      ['stored value' => 'Option label'] -- the allowed set.
     *   number  'unit'         Suffix shown beside the input, or null.
     *           'min' / 'max'  Inclusive bounds.
     *           'integer'      true = whole numbers only.
     *           'allow_blank'  true = empty means "no requirement".
     *   text    'max_length'   Character cap.
     *   period  'sub'          Sub-control definitions keyed by the stored sub-key.
     */
    private static $configs = [

        // ========================================
        // Attendance & Activity (5)
        // ========================================
        'AveragePeriod' => [
            'label'   => 'Activity Window',
            'help'    => 'How far back the ORK looks when deciding whether a player counts as active.',
            'control' => self::CONTROL_PERIOD,
            'group'   => 'attendance',
            'sub'     => [
                'Period' => [
                    'label'   => 'Length',
                    'control' => self::CONTROL_NUMBER,
                    'min'     => 1,
                    'max'     => 60,
                    'integer' => true,
                ],
                'Type' => [
                    'label'   => 'Unit',
                    'control' => self::CONTROL_SELECT,
                    'options' => ['month' => 'Months', 'week' => 'Weeks'],
                ],
            ],
        ],
        'AttendanceWeeklyMinimum' => [
            'label'       => 'Minimum Weeks Attended',
            'help'        => 'How many separate weeks a player must sign in within the activity window to stay active. Leave blank to not require this.',
            'control'     => self::CONTROL_NUMBER,
            'group'       => 'attendance',
            'unit'        => 'weeks',
            'min'         => 0,
            'max'         => 260,
            'integer'     => true,
            'allow_blank' => true,
        ],
        'AttendanceDailyMinimum' => [
            'label'       => 'Minimum Days Attended',
            'help'        => 'How many separate days a player must sign in within the activity window to stay active. Leave blank to not require this.',
            'control'     => self::CONTROL_NUMBER,
            'group'       => 'attendance',
            'unit'        => 'days',
            'min'         => 0,
            'max'         => 1000,
            'integer'     => true,
            'allow_blank' => true,
        ],
        'AttendanceCreditMinimum' => [
            'label'       => 'Minimum Credits Earned',
            'help'        => 'How many total credits a player must earn within the activity window to stay active. Leave blank to not require this.',
            'control'     => self::CONTROL_NUMBER,
            'group'       => 'attendance',
            'unit'        => 'credits',
            'min'         => 0,
            'max'         => 10000,
            'integer'     => true,
            'allow_blank' => true,
        ],
        'MonthlyCreditMaximum' => [
            'label'       => 'Monthly Credit Cap',
            'help'        => 'The most credits a single player can be counted for in one calendar month; anything beyond this is not counted. Leave blank for no cap.',
            'control'     => self::CONTROL_NUMBER,
            'group'       => 'attendance',
            'unit'        => 'credits per month',
            'min'         => 0,
            'max'         => 1000,
            'integer'     => true,
            'allow_blank' => true,
        ],

        // ========================================
        // Dues (3)
        // ========================================
        'DuesPeriod' => [
            'label'   => 'Dues Period',
            'help'    => 'How long one payment of dues covers a player.',
            'control' => self::CONTROL_PERIOD,
            'group'   => 'dues',
            'sub'     => [
                'Period' => [
                    'label'   => 'Length',
                    'control' => self::CONTROL_NUMBER,
                    'min'     => 1,
                    'max'     => 60,
                    'integer' => true,
                ],
                'Type' => [
                    'label'   => 'Unit',
                    'control' => self::CONTROL_SELECT,
                    'options' => ['month' => 'Months', 'week' => 'Weeks'],
                ],
            ],
        ],
        'DuesAmount' => [
            'label'       => 'Dues Amount',
            'help'        => 'What a player pays for one dues period.',
            'control'     => self::CONTROL_NUMBER,
            'group'       => 'dues',
            'unit'        => 'per period',
            'min'         => 0,
            'max'         => 10000,
            'integer'     => false,
            'allow_blank' => false,
        ],
        // Treasury::pay_dues() books KingdomDuesTake against the kingdom's ledger and
        // the remainder to the park, so a take larger than the dues amount produces a
        // negative park share. That is a relationship between two settings, not a
        // property of either one; the modal should warn on it. Range-checking here
        // deliberately stops at "is this a sane amount of money".
        'KingdomDuesTake' => [
            'label'       => "Kingdom's Share of Dues",
            'help'        => 'How much of each dues payment goes to the kingdom rather than the park.',
            'control'     => self::CONTROL_NUMBER,
            'group'       => 'dues',
            'unit'        => 'per period',
            'min'         => 0,
            'max'         => 10000,
            'integer'     => false,
            'allow_blank' => false,
        ],

        // ========================================
        // Awards & Recommendations (1)
        // ========================================
        'AwardRecsPublic' => [
            'label'   => 'Who Can See Award Recommendations',
            'help'    => 'Whether the Award Recommendations tab is open to everyone or limited to officers.',
            'control' => self::CONTROL_SELECT,
            'group'   => 'awards',
            'options' => [
                '1' => 'Anyone can see them',
                '0' => 'Officers only',
            ],
        ],

        // ========================================
        // Qualification Tests (2)
        // ========================================
        'QualTestReeveEnabled' => [
            'label'   => "Reeve's Test",
            'help'    => "Offer the kingdom's Reeve qualification test to players.",
            'control' => self::CONTROL_BOOLEAN,
            'group'   => 'qualtest',
        ],
        'QualTestCorporaEnabled' => [
            'label'   => 'Corpora Test',
            'help'    => "Offer the kingdom's Corpora qualification test to players.",
            'control' => self::CONTROL_BOOLEAN,
            'group'   => 'qualtest',
        ],

        // ========================================
        // Statistics & Reporting (1)
        // ========================================
        'IncludePrincipalityInStatistics' => [
            'label'   => 'Count Principalities in Kingdom Statistics',
            'help'    => 'Fold attendance, player counts and other figures from your principalities into kingdom statistics, graphs and reports.',
            'control' => self::CONTROL_BOOLEAN,
            'group'   => 'statistics',
        ],

        // ========================================
        // Appearance (1)
        // ========================================
        'AtlasColor' => [
            'label'   => 'Atlas Map Colour',
            'help'    => 'The colour used for this kingdom on the Atlas map.',
            'control' => self::CONTROL_COLOR,
            'group'   => 'appearance',
        ],
    ];

    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Get every editable config definition, keyed by config key.
     *
     * @return array
     */
    public static function GetAll()
    {
        return self::$configs;
    }

    /**
     * Is this a config key officers are allowed to see and edit?
     * Anything false here must be neither rendered nor saved.
     *
     * @param string $key  Raw ork_configuration key
     * @return bool
     */
    public static function Exists($key)
    {
        return isset(self::$configs[$key]);
    }

    /**
     * Get one config definition.
     *
     * @param string $key
     * @return array|null  The definition, or null if the key is not editable
     */
    public static function Get($key)
    {
        return isset(self::$configs[$key]) ? self::$configs[$key] : null;
    }

    /**
     * Officer-facing label for a key. Falls back to the raw key only so a caller
     * that skipped Exists() still renders something -- callers should filter first.
     *
     * @param string $key
     * @return string
     */
    public static function Label($key)
    {
        return isset(self::$configs[$key]['label']) ? self::$configs[$key]['label'] : (string) $key;
    }

    /**
     * Group keys and their section headings, in render order.
     *
     * @return array  ['group_key' => 'Section heading']
     */
    public static function Groups()
    {
        return self::$groups;
    }

    /**
     * Definitions belonging to one group, in declaration order.
     *
     * @param string $group  A key of self::$groups
     * @return array  ['config_key' => definition]
     */
    public static function GetByGroup($group)
    {
        $result = [];
        foreach (self::$configs as $key => $def) {
            if ($def['group'] === $group) {
                $result[$key] = $def;
            }
        }
        return $result;
    }

    /**
     * Every definition, bucketed by group in render order. This is what the
     * Configuration modal iterates to draw its sections.
     *
     * Groups with no keys are omitted, so adding a group here costs nothing until
     * a key uses it.
     *
     * @return array  ['group_key' => ['label' => string, 'configs' => ['key' => def]]]
     */
    public static function GetGrouped()
    {
        $result = [];
        foreach (self::$groups as $group => $label) {
            $configs = self::GetByGroup($group);
            if (empty($configs)) {
                continue;
            }
            $result[$group] = ['label' => $label, 'configs' => $configs];
        }
        return $result;
    }

    /**
     * Filter rows as returned by Common::get_configs() down to the editable keys,
     * in registry group order, attaching each row's definition.
     *
     * This is the single place the stale/internal keys get dropped -- callers should
     * not re-implement the filter.
     *
     * @param array $configs  Common::get_configs() output, keyed by config key
     * @return array  ['config_key' => row + ['Group' => string, 'Definition' => array]]
     */
    public static function FilterKnown(array $configs)
    {
        $result = [];
        foreach (self::$groups as $group => $group_label) {
            foreach (self::GetByGroup($group) as $key => $def) {
                if (!isset($configs[$key])) {
                    continue;
                }
                $row = $configs[$key];
                $row['Group']      = $group;
                $row['GroupLabel'] = $group_label;
                $row['Definition'] = $def;
                $result[$key] = $row;
            }
        }
        return $result;
    }

    /**
     * Total count of editable keys.
     *
     * @return int
     */
    public static function Count()
    {
        return count(self::$configs);
    }

    /**
     * Validate a submitted value against its definition.
     *
     * This is the gate that stops "yes" being stored in a boolean and stops a key
     * that is not in this registry being written at all. Callers should store the
     * returned normalized value, not the raw submission.
     *
     * Note on normalization: a blank, optional number normalizes to '' and never to
     * null, because yapo drops nulls from UPDATE/INSERT -- assigning null would leave
     * the previous value in place instead of clearing it.
     *
     * @param string $key    Config key
     * @param mixed  $value  Raw submitted value (string, array or stdClass)
     * @return array  ['valid' => bool, 'error' => string|null, 'value' => mixed]
     */
    public static function Validate($key, $value)
    {
        $def = self::Get($key);
        if ($def === null) {
            return self::invalid('That is not a setting this kingdom can change.');
        }

        switch ($def['control']) {
            case self::CONTROL_BOOLEAN:
                return self::validateBoolean($def, $value);
            case self::CONTROL_SELECT:
                return self::validateSelect($def, $value);
            case self::CONTROL_NUMBER:
                return self::validateNumber($def, $value);
            case self::CONTROL_COLOR:
                return self::validateColor($def, $value);
            case self::CONTROL_TEXT:
                return self::validateText($def, $value);
            case self::CONTROL_PERIOD:
                return self::validatePeriod($def, $value);
        }

        return self::invalid('That setting cannot be edited here.');
    }

    // ----------------------------------------------------------------
    // Per-control validators
    // ----------------------------------------------------------------

    /**
     * Booleans store the strings '0' and '1'. Accepts real booleans and the two
     * numeric forms; rejects everything else, including 'yes', 'true' and ''.
     */
    private static function validateBoolean(array $def, $value)
    {
        if (is_bool($value)) {
            return self::valid($value ? '1' : '0');
        }
        if (!is_scalar($value)) {
            return self::invalid(self::name($def) . ' must be on or off.');
        }
        $v = trim((string) $value);
        if ($v === '0' || $v === '1') {
            return self::valid($v);
        }
        return self::invalid(self::name($def) . ' must be on or off.');
    }

    /**
     * Selects must submit one of the declared option keys, compared as strings.
     */
    private static function validateSelect(array $def, $value)
    {
        $options = isset($def['options']) && is_array($def['options']) ? $def['options'] : [];
        if (!is_scalar($value)) {
            return self::invalid('Choose a valid option for ' . self::name($def) . '.');
        }
        $v = trim((string) $value);
        foreach (array_keys($options) as $allowed) {
            if ((string) $allowed === $v) {
                return self::valid((string) $allowed);
            }
        }
        return self::invalid('Choose a valid option for ' . self::name($def) . '.');
    }

    /**
     * Numbers must be numeric and inside the declared range. Blank is accepted only
     * where the setting declares allow_blank, and normalizes to '' (see Validate()).
     */
    private static function validateNumber(array $def, $value)
    {
        $allow_blank = !empty($def['allow_blank']);
        if (!is_scalar($value)) {
            return self::invalid(self::name($def) . ' must be a number.');
        }
        $v = trim((string) $value);

        if ($v === '') {
            if ($allow_blank) {
                return self::valid('');
            }
            return self::invalid(self::name($def) . ' is required.');
        }
        if (!is_numeric($v)) {
            return self::invalid(self::name($def) . ' must be a number.');
        }

        $integer_only = !empty($def['integer']);
        $num = (float) $v;
        if ($integer_only && floor($num) != $num) {
            return self::invalid(self::name($def) . ' must be a whole number.');
        }

        if (isset($def['min']) && $num < $def['min']) {
            return self::invalid(self::name($def) . ' cannot be less than ' . self::plain($def['min']) . '.');
        }
        if (isset($def['max']) && $num > $def['max']) {
            return self::invalid(self::name($def) . ' cannot be more than ' . self::plain($def['max']) . '.');
        }

        return self::valid($integer_only ? (string) (int) $num : self::plain($num));
    }

    /**
     * Colours are stored as six hex digits with no leading '#' (matching the seeded
     * 'FE7569'). A submission from an <input type="color"> arrives with the '#'.
     */
    private static function validateColor(array $def, $value)
    {
        if (!is_scalar($value)) {
            return self::invalid(self::name($def) . ' must be a colour.');
        }
        $v = ltrim(trim((string) $value), '#');
        if (!preg_match('/^[0-9A-Fa-f]{6}$/', $v)) {
            return self::invalid(self::name($def) . ' must be a six-digit colour code.');
        }
        return self::valid(strtoupper($v));
    }

    private static function validateText(array $def, $value)
    {
        if (!is_scalar($value)) {
            return self::invalid(self::name($def) . ' must be text.');
        }
        $v = trim((string) $value);
        $max = isset($def['max_length']) ? (int) $def['max_length'] : 255;
        if (mb_strlen($v) > $max) {
            return self::invalid(self::name($def) . ' cannot be longer than ' . $max . ' characters.');
        }
        return self::valid($v);
    }

    /**
     * Composite {Period, Type} settings. Every declared sub-key must be present and
     * must pass its own sub-definition; unknown sub-keys are dropped rather than
     * stored, so a hand-crafted POST cannot smuggle extra fields into the JSON blob.
     */
    private static function validatePeriod(array $def, $value)
    {
        if (is_object($value)) {
            $value = (array) $value;
        }
        if (!is_array($value)) {
            return self::invalid(self::name($def) . ' must have a length and a unit.');
        }

        $out = [];
        foreach ($def['sub'] as $sub_key => $sub_def) {
            if (!array_key_exists($sub_key, $value)) {
                return self::invalid(self::name($def) . ' is missing its ' . strtolower($sub_def['label']) . '.');
            }
            $sub_def['label'] = self::name($def) . ' ' . strtolower($sub_def['label']);
            switch ($sub_def['control']) {
                case self::CONTROL_SELECT:
                    $r = self::validateSelect($sub_def, $value[$sub_key]);
                    break;
                case self::CONTROL_NUMBER:
                    $r = self::validateNumber($sub_def, $value[$sub_key]);
                    break;
                default:
                    return self::invalid(self::name($def) . ' cannot be edited here.');
            }
            if (!$r['valid']) {
                return $r;
            }
            $out[$sub_key] = $r['value'];
        }

        return self::valid($out);
    }

    // ----------------------------------------------------------------
    // Small helpers
    // ----------------------------------------------------------------

    private static function valid($value)
    {
        return ['valid' => true, 'error' => null, 'value' => $value];
    }

    private static function invalid($message)
    {
        return ['valid' => false, 'error' => $message, 'value' => null];
    }

    /** Officer-facing name of a definition, for error messages. */
    private static function name(array $def)
    {
        return isset($def['label']) ? $def['label'] : 'This setting';
    }

    /** Render a number without a trailing '.0' or float noise. */
    private static function plain($number)
    {
        $n = (float) $number;
        if (floor($n) == $n) {
            return (string) (int) $n;
        }
        return rtrim(rtrim(number_format($n, 4, '.', ''), '0'), '.');
    }
}
