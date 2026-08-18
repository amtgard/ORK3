<?php

/**
 * Nav chrome helpers for default.theme — loaded via startup.php DIR_ORK3 sweep
 * so templates can resolve viewer context without $DB.
 */

if (!function_exists('_nav_player_domain')) {
    function _nav_player_domain()
    {
        static $player = null;
        if ($player === null) {
            $player = new Player();
        }

        return $player;
    }
}

if (!function_exists('nav_home_context')) {
    /**
     * @return array<string, mixed>|null
     */
    function nav_home_context(int $mundaneId): ?array
    {
        return _nav_player_domain()->GetHomeNavContext($mundaneId);
    }
}

if (!function_exists('nav_persona')) {
    function nav_persona(int $mundaneId, string $fallback = ''): string
    {
        $persona = _nav_player_domain()->GetPersona($mundaneId);

        return $persona !== '' ? $persona : $fallback;
    }
}

if (!function_exists('nav_needs_email_prompt')) {
    function nav_needs_email_prompt(int $mundaneId): bool
    {
        return _nav_player_domain()->NeedsEmailPrompt($mundaneId);
    }
}
