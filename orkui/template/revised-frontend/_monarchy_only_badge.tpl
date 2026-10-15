<?php
// Monarchy Only recommendation badge — one definition for every recs surface.
// Included by: Playernew_index.tpl, Parknew_index.tpl, Kingdomnew_recommendations_panel.tpl,
// Recommendations_manage.tpl. Safe to include more than once per request.
if (!function_exists('ork_monarchy_only_badge')) {
    function ork_monarchy_only_badge(int $level, string $extraClass = ''): string
    {
        if ($level <= 0) {
            return '';
        }
        $tip = $level >= 2 ? 'Visible only to kingdom officers' : 'Visible to park and kingdom officers';
        return '<span class="mo-badge' . ($extraClass !== '' ? ' ' . htmlspecialchars($extraClass, ENT_QUOTES) : '')
            . '" data-tip="' . $tip . '"><i class="fas fa-eye-slash" aria-hidden="true"></i> Monarchy Only</span>';
    }
}
