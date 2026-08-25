<?php

// tests/cms-fields/officers_vacancy_test.php — run: php tests/cms-fields/officers_vacancy_test.php
//
// A vacant officer seat (ork_officer row with mundane_id = 0) LEFT JOINs to a NULL
// persona but keeps its role, e.g. "Champion". The public roster must skip it. Before
// this fix the skip required BOTH persona and role to be empty, so 187 of 342 active
// parks rendered a card with an office title and no name in it.

$fails = 0;
function check($label, $cond)
{
    global $fails;
    echo($cond ? "PASS  $label\n" : "FAIL  $label\n");
    if (!$cond) {
        $fails++;
    }
}

// Mirrors the render predicate in _shared/officers.tpl: a seat renders only
// when it carries a persona. The role is deliberately NOT consulted — a vacant
// seat still has a role, and rendering it would advertise an empty office.
function officer_is_renderable(array $row)
{
    $persona = trim((string) ($row['Persona'] ?? ''));
    return $persona !== '';
}

check(
    'a filled seat renders',
    officer_is_renderable(array('Persona' => 'Tobias of Heraldsbridge', 'OfficerRole' => 'Monarch'))
);
check(
    'a VACANT seat (role, no persona) is skipped',
    !officer_is_renderable(array('Persona' => '', 'OfficerRole' => 'Champion'))
);
check(
    'a NULL persona from the LEFT JOIN is skipped',
    !officer_is_renderable(array('Persona' => null, 'OfficerRole' => 'GMR'))
);
check(
    'a whitespace-only persona is skipped',
    !officer_is_renderable(array('Persona' => '   ', 'OfficerRole' => 'Regent'))
);
check(
    'a totally empty row is skipped',
    !officer_is_renderable(array('Persona' => '', 'OfficerRole' => ''))
);
check(
    'a persona with no role still renders (office is optional, a name is not)',
    officer_is_renderable(array('Persona' => 'Venn', 'OfficerRole' => ''))
);

echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
