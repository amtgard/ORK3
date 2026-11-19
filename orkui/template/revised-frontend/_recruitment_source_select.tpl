<?php
// "How did you find us?" field — one definition for every form that asks it.
// Included by: Admin_index.tpl, Kingdomnew_index.tpl, Parknew_index.tpl (officer
// create-player modals), Playernew_index.tpl (Account modal), SelfReg_form.tpl.
// Safe to include more than once per request.
//
// $field comes from Model_Player::recruitment_source_field($audience):
//   ['Options' => code => label, 'Label', 'Placeholder', 'Hint'].
// $o: id (required), name ('' = no name attribute), selected (code),
//     wrap_class (the form's own field wrapper class), hint_class.
if (!function_exists('ork_recruitment_source_select')) {
    function ork_recruitment_source_select(array $field, array $o): string
    {
        $h = static function ($v) {
            return htmlspecialchars((string)$v, ENT_QUOTES);
        };
        $id        = (string)($o['id'] ?? 'recruitment-source');
        $name      = (string)($o['name'] ?? '');
        $selected  = (string)($o['selected'] ?? '');
        $wrapClass = (string)($o['wrap_class'] ?? '');
        $hintClass = (string)($o['hint_class'] ?? 'ork-field-hint');
        $hint      = (string)($field['Hint'] ?? '');
        $hintId    = $id . '-hint';

        $out = '<div class="' . $h($wrapClass) . '">'
            . '<label for="' . $h($id) . '">' . $h($field['Label'] ?? '') . '</label>'
            . '<select id="' . $h($id) . '"' . ($name !== '' ? ' name="' . $h($name) . '"' : '')
            . ($hint !== '' ? ' aria-describedby="' . $h($hintId) . '"' : '') . '>'
            . '<option value="">' . $h($field['Placeholder'] ?? '') . '</option>';
        foreach (($field['Options'] ?? []) as $code => $label) {
            $out .= '<option value="' . $h($code) . '"' . ((string)$code === $selected ? ' selected' : '') . '>'
                . $h($label) . '</option>';
        }
        $out .= '</select>';
        if ($hint !== '') {
            $out .= '<div class="' . $h($hintClass) . '" id="' . $h($hintId) . '">' . $h($hint) . '</div>';
        }
        return $out . '</div>';
    }
}
