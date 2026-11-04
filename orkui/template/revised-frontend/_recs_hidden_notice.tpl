<?php
// Recommendations tab — the notice shown when the list is empty because the
// kingdom keeps award recommendations private (AwardRecsPublic = 0), rather
// than because there are none.
// Included by: Playernew_index.tpl, Parknew_index.tpl,
// Kingdomnew_recommendations_panel.tpl.
//
// $canViewOwn: the viewer still sees recommendations they submitted themselves,
// which is every logged-in viewer today. Returns plain text — escape on output.
if (!function_exists('ork_recs_hidden_notice')) {
    function ork_recs_hidden_notice(bool $canViewOwn): string
    {
        return 'Award recommendations are not visible due to kingdom administrative settings. '
            . 'You may still submit award recommendations'
            . ($canViewOwn ? ' and view any recommendations you have submitted.' : '.');
    }
}
