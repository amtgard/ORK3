<?php

/***
 * Model_ConfigRegistry
 *
 * Thin passthrough to the kingdom-configuration registry
 * (system/lib/ork3/class.ConfigRegistry.php).
 *
 * ConfigRegistry's accessors are all static, so the Model::__call() /
 * APIModel forwarding the other models rely on does not read naturally here.
 * These wrappers exist so orkui/controller never names the domain class
 * directly -- orkui/model is the only membrane allowed to reach system/lib/ork3.
 * No logic lives here: the allow-list, the labels and the validation rules all
 * stay in the registry.
 ***/

class Model_ConfigRegistry extends Model
{
    /** Control types, mirrored so controllers can compare without naming the domain class. */
    public const CONTROL_BOOLEAN = ConfigRegistry::CONTROL_BOOLEAN;
    public const CONTROL_SELECT  = ConfigRegistry::CONTROL_SELECT;
    public const CONTROL_NUMBER  = ConfigRegistry::CONTROL_NUMBER;
    public const CONTROL_COLOR   = ConfigRegistry::CONTROL_COLOR;
    public const CONTROL_TEXT    = ConfigRegistry::CONTROL_TEXT;
    public const CONTROL_PERIOD  = ConfigRegistry::CONTROL_PERIOD;

    public function exists($key)
    {
        return ConfigRegistry::Exists($key);
    }

    public function get($key)
    {
        return ConfigRegistry::Get($key);
    }

    public function label($key)
    {
        return ConfigRegistry::Label($key);
    }

    public function validate($key, $value)
    {
        return ConfigRegistry::Validate($key, $value);
    }

    public function filter_known(array $configs)
    {
        return ConfigRegistry::FilterKnown($configs);
    }
}
