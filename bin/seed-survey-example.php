#!/usr/bin/env php
<?php

/**
 * DEV-ONLY seeder: builds a realistic example survey ("Voice of the Kingdom")
 * and, optionally, a pile of weighted random responses to it, so the builder,
 * the runner and the Highcharts results page all have something honest to show.
 *
 * Everything is written through the domain classes (Survey / SurveyResponse) —
 * never raw INSERTs into ork_survey_* — so the same validation, structure lock,
 * show_if evaluation and consent scrub that a real respondent hits are exercised
 * here too. The only direct SQL is a read-only SELECT that picks respondents.
 *
 * Usage (inside the app container; the repo mounts at /var/www/ork.amtgard.com):
 *
 *   docker exec ork3-php8-app php /var/www/ork.amtgard.com/bin/seed-survey-example.php \
 *       --kingdom=17 --responses=60 --open
 *
 * Options:
 *   --kingdom=N     REQUIRED. Kingdom the survey is scoped to, and the kingdom
 *                   respondents are drawn from.
 *   --creator=N     Survey owner / created_by. Default 46193. Must pass canCreate.
 *   --title="..."   Override the survey title (the rest of the copy is unchanged).
 *   --responses=N   Generate N responses. Requires an open survey, so pair with
 *                   --open unless you are opening it by hand afterwards.
 *   --open          setStatus('open') after building (this locks the structure).
 *   --dry-run       Print the plan and write nothing at all.
 *
 * Re-running always creates a NEW survey; there is deliberately no dedupe, so
 * you can seed several and compare.
 */

// ---------------------------------------------------------------------------
// Guards. These run BEFORE startup.php, which loads a config that assumes a web
// request; nothing below may touch the database until they have all passed.
// ---------------------------------------------------------------------------

if ('cli' !== PHP_SAPI) {
    header('HTTP/1.1 404 Not Found');
    exit(1);
}

$env = (string) getenv('ENVIRONMENT');
if (!in_array($env, ['DEV', 'TEST'], true)) {
    fwrite(STDERR, "REFUSING: ENVIRONMENT is '" . ($env ?: '(unset)') . "'. This seeder only runs with ENVIRONMENT=DEV or TEST.\n");
    exit(1);
}

$ambientHost = strtolower(trim((string) (getenv('HTTP_HOST') ?: ($_SERVER['HTTP_HOST'] ?? ''))));
$hostOnly    = preg_replace('/:\d+$/', '', $ambientHost);
if ('' !== $hostOnly) {
    $looksProd = (bool) preg_match('/(^|\.)amtgard\.com$/', $hostOnly)
        && !preg_match('/^(dev|staging|stage|local)\./', $hostOnly);
    if ($looksProd) {
        fwrite(STDERR, "REFUSING: HTTP_HOST '" . $ambientHost . "' looks like production.\n");
        exit(1);
    }
}

// config.dev.php builds every HTTP_* constant from $_SERVER['HTTP_HOST']; on the
// CLI there is none, so stand one in for the URLs printed at the end.
$_SERVER['HTTP_HOST'] = '' !== $ambientHost ? $ambientHost : 'localhost:19080';

require_once dirname(__DIR__) . '/startup.php';

// ---------------------------------------------------------------------------
// Arguments
// ---------------------------------------------------------------------------

$opt = [
    'kingdom'   => 0,
    'creator'   => 46193,
    'title'     => 'Voice of the Kingdom 2026',
    'responses' => 0,
    'open'      => false,
    'dry-run'   => false,
];

foreach (array_slice($argv, 1) as $arg) {
    if ('--open' === $arg) {
        $opt['open'] = true;
    } elseif ('--dry-run' === $arg) {
        $opt['dry-run'] = true;
    } elseif ('--help' === $arg || '-h' === $arg) {
        fwrite(STDOUT, "Usage: seed-survey-example.php --kingdom=N [--creator=N] [--title=\"...\"] [--responses=N] [--open] [--dry-run]\n");
        exit(0);
    } elseif (preg_match('/^--(kingdom|creator|responses)=(\d+)$/', $arg, $m)) {
        $opt[$m[1]] = (int) $m[2];
    } elseif (preg_match('/^--title=(.*)$/s', $arg, $m)) {
        $opt['title'] = trim($m[1]);
    } else {
        fwrite(STDERR, "Unknown argument '" . $arg . "'. Try --help.\n");
        exit(1);
    }
}

if ($opt['kingdom'] <= 0) {
    fwrite(STDERR, "REFUSING: --kingdom=N is required (the survey scope and the respondent pool).\n");
    exit(1);
}
if ('' === $opt['title']) {
    fwrite(STDERR, "REFUSING: --title may not be blank.\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// Content: the owner's example set. Order here is the order in the survey.
// ---------------------------------------------------------------------------

const SEED_DESCRIPTION = 'A five-minute check-in on classes, events and awards.';
const SEED_WELCOME_MD  = "# Voice of the Kingdom 2026\n\nThe Crown wants to hear from you. This takes about **five minutes**. Your answers help plan next year's events and awards.";
const SEED_THANKS_MD   = "## Thank you!\n\nResults will be shared at the next kingdom court.";

const OTHER_CLASS_LABEL = 'Something else (please specify)';

/**
 * The whole survey as data, so --dry-run can describe it without writing.
 *
 * @return list<array{title: string, questions: list<array<string, mixed>>}>
 */
function seed_plan(): array
{
    return [
        [
            'title'     => 'About you',
            'questions' => [
                [
                    'key'      => 'q1',
                    'type'     => 'single',
                    'prompt'   => 'Which class do you play most often?',
                    'required' => true,
                    'choices'  => [
                        ['label' => 'Warrior'], ['label' => 'Scout'], ['label' => 'Archer'],
                        ['label' => 'Barbarian'], ['label' => 'Assassin'], ['label' => 'Monk'],
                        ['label' => 'Healer'], ['label' => 'Wizard'], ['label' => 'Druid'],
                        ['label' => 'Bard'], ['label' => 'Anti-Paladin'], ['label' => 'Paladin'],
                        ['label' => OTHER_CLASS_LABEL, 'is_other' => 1],
                    ],
                ],
                [
                    'key'      => 'q2',
                    'type'     => 'multi',
                    'prompt'   => 'Which parts of Amtgard do you take part in?',
                    'settings' => ['min_select' => 1],
                    'choices'  => [
                        ['label' => 'Battlegames'], ['label' => 'Ditching'], ['label' => 'Arts & Sciences'],
                        ['label' => 'Quests'], ['label' => 'Kingdom offices and politics'],
                        ['label' => 'Camping at events'], ['label' => 'Tournaments'],
                        ['label' => 'Teaching new players'],
                    ],
                ],
                [
                    'key'      => 'q3',
                    'type'     => 'number',
                    'prompt'   => 'How many years have you been playing?',
                    'required' => true,
                    'settings' => ['min' => 0, 'max' => 60, 'step' => 1, 'unit' => 'years'],
                ],
                [
                    'key'    => 'q4',
                    'type'   => 'yesno',
                    'prompt' => 'Are you currently in a company or household?',
                ],
                [
                    'key'     => 'q5',
                    'type'    => 'dropdown',
                    'prompt'  => "Which day is your park's main fighter practice?",
                    'choices' => [
                        ['label' => 'Saturday'], ['label' => 'Sunday'],
                        ['label' => 'A weekday evening'], ['label' => 'It varies'],
                    ],
                ],
            ],
        ],
        [
            'title'     => 'Events',
            'questions' => [
                [
                    'key'      => 'q6',
                    'type'     => 'rating',
                    'prompt'   => 'How would you rate the last kingdom event you attended?',
                    'required' => true,
                    'settings' => ['min' => 1, 'max' => 5, 'min_label' => 'Poor', 'max_label' => 'Excellent', 'icon' => 'star'],
                ],
                [
                    'key'    => 'q7',
                    'type'   => 'nps',
                    'prompt' => 'How likely are you to recommend Amtgard to a friend?',
                ],
                [
                    'key'     => 'q8',
                    'type'    => 'matrix',
                    'prompt'  => 'How satisfied are you with these parts of events?',
                    'rows'    => [
                        ['label' => 'Battlegames'], ['label' => 'Feast'], ['label' => 'Court'],
                        ['label' => 'Camping arrangements'], ['label' => 'Communication before the event'],
                    ],
                    'columns' => [
                        ['label' => 'Very dissatisfied', 'value_num' => 1],
                        ['label' => 'Dissatisfied', 'value_num' => 2],
                        ['label' => 'Neutral', 'value_num' => 3],
                        ['label' => 'Satisfied', 'value_num' => 4],
                        ['label' => 'Very satisfied', 'value_num' => 5],
                    ],
                ],
                [
                    'key'      => 'q9',
                    'type'     => 'ranking',
                    'prompt'   => 'Rank what matters most to you at an event',
                    'settings' => ['rank_all' => true],
                    'choices'  => [
                        ['label' => 'Good fights'], ['label' => 'Meeting people'], ['label' => 'The feast'],
                        ['label' => 'Court and awards'], ['label' => 'Arts & Sciences displays'],
                    ],
                ],
                [
                    'key'     => 'q9p',
                    'type'    => 'pairwise',
                    'prompt'  => 'Which event should the kingdom add next?',
                    'help'    => 'The calendar has room for one new event next year. Think about which one you would travel to.',
                    'choices' => [
                        ['label' => 'Spring war'], ['label' => 'Tournament of champions'], ['label' => 'Quest weekend'],
                        ['label' => 'Fall feast'], ['label' => 'Camping campaign'], ['label' => 'Fighter practice weekend'],
                        ['label' => 'Arts & Sciences faire'], ['label' => 'Newcomer demo day'], ['label' => 'Youth day'],
                    ],
                ],
                [
                    'key'      => 'q10',
                    'type'     => 'date',
                    'prompt'   => 'When did you attend your first Amtgard event?',
                    'settings' => ['max' => date('Y-m-d')],
                ],
                [
                    'key'      => 'q11',
                    'type'     => 'paragraph',
                    'prompt'   => 'What is one thing that would make the next event better?',
                    'settings' => ['max_length' => 1000, 'placeholder' => 'Be specific — the event team reads every one of these.'],
                ],
            ],
        ],
        [
            'title'     => 'Awards and culture',
            'questions' => [
                [
                    'key'    => 'q12',
                    'type'   => 'section',
                    'prompt' => 'Awards',
                    'help'   => 'Kingdom leadership is considering a **new award** for service to new players. Help us name it and decide who deserves it.',
                ],
                [
                    'key'      => 'q13',
                    'type'     => 'short_text',
                    'prompt'   => 'Suggest a name for a new award recognizing mentorship of new players',
                    'settings' => ['max_length' => 80, 'placeholder' => 'e.g. Order of the Lantern'],
                ],
                [
                    'key'    => 'q14',
                    'type'   => 'yesno',
                    'prompt' => 'Would you nominate someone for it?',
                ],
                [
                    'key'      => 'q15',
                    'type'     => 'short_text',
                    'prompt'   => 'Who would you nominate, and why?',
                    'settings' => ['max_length' => 200],
                    'show_if'  => ['q14', 'Yes'],
                ],
                [
                    'key'     => 'q16',
                    'type'    => 'multi',
                    'prompt'  => 'What would you like to see more of in the kingdom newsletter?',
                    'choices' => [
                        ['label' => 'Event recaps'], ['label' => 'Award announcements'],
                        ['label' => 'Rules clarifications'], ['label' => 'Garb and crafting tutorials'],
                        ['label' => 'Fighter tips'], ['label' => 'Park spotlights'],
                    ],
                ],
                [
                    'key'      => 'q17',
                    'type'     => 'rating',
                    'prompt'   => 'How welcoming is your park to new players?',
                    'settings' => ['min' => 1, 'max' => 10, 'icon' => 'number', 'min_label' => 'Not at all', 'max_label' => 'Extremely'],
                ],
            ],
        ],
    ];
}

// ---------------------------------------------------------------------------
// Small helpers
// ---------------------------------------------------------------------------

function say(string $line): void
{
    fwrite(STDOUT, $line . "\n");
}

function bail(string $msg): void
{
    fwrite(STDERR, 'ERROR: ' . $msg . "\n");
    exit(1);
}

/**
 * Every domain call returns the QualTest-style envelope; anything but Status 0
 * during the build is fatal, because a half-built survey is worse than none.
 *
 * @param  array<string, mixed> $result
 * @return array<string, mixed>
 */
function must(array $result, string $what): array
{
    if (0 !== (int) ($result['Status'] ?? 1)) {
        $detail = (string) ($result['Error'] ?? 'unknown error');
        if (!empty($result['Errors'])) {
            $detail .= ' ' . json_encode($result['Errors']);
        }
        bail($what . ' failed: ' . $detail);
    }
    return $result;
}

/** True $pct% of the time. */
function chance(int $pct): bool
{
    return random_int(1, 100) <= $pct;
}

/**
 * @param  list<mixed> $list
 * @return mixed
 */
function pick_one(array $list)
{
    return $list[random_int(0, count($list) - 1)];
}

/**
 * Weighted pick: [key => weight] => key.
 *
 * @param  array<int|string, int> $weights
 * @return int|string
 */
function wpick(array $weights)
{
    $total = 0;
    foreach ($weights as $w) {
        $total += (int) $w;
    }
    if ($total <= 0) {
        return array_key_first($weights);
    }
    $r = random_int(1, $total);
    foreach ($weights as $k => $w) {
        $r -= (int) $w;
        if ($r <= 0) {
            return $k;
        }
    }
    return array_key_last($weights);
}

/**
 * Weighted sample without replacement, highest-weight-first on average.
 *
 * @param  array<int|string, int> $weights
 * @return list<int|string>
 */
function wsample(array $weights, int $k): array
{
    $out = [];
    while (count($out) < $k && $weights) {
        $key = wpick($weights);
        unset($weights[$key]);
        $out[] = $key;
    }
    return $out;
}

// ---------------------------------------------------------------------------
// Build
// ---------------------------------------------------------------------------

/**
 * Create one question, apply its copy/settings, then replace its options.
 * Records the question id and a label => option_id map per role.
 *
 * @param array<string, mixed>              $spec
 * @param array<string, array<string, mixed>> $built
 */
function build_question(Survey $survey, int $surveyId, int $pageId, array $spec, array &$built): void
{
    $key = (string) $spec['key'];
    $add = must($survey->questionAdd($surveyId, $pageId, (string) $spec['type'], null), 'questionAdd(' . $key . ')');
    $qid = (int) $add['Question']['question_id'];

    $fields = [
        'Prompt'   => (string) $spec['prompt'],
        'Required' => !empty($spec['required']) ? 1 : 0,
    ];
    if (array_key_exists('help', $spec)) {
        $fields['HelpMd'] = (string) $spec['help'];
    }
    if (array_key_exists('settings', $spec)) {
        $fields['Settings'] = $spec['settings'];
    }
    must($survey->questionUpdate($qid, $fields), 'questionUpdate(' . $key . ')');

    $maps = ['choice' => [], 'row' => [], 'column' => []];
    foreach ($add['Question']['Options'] as $o) {
        $maps[(string) $o['role']][(string) $o['label']] = (int) $o['option_id'];
    }
    foreach (['choice' => 'choices', 'row' => 'rows', 'column' => 'columns'] as $role => $specKey) {
        if (empty($spec[$specKey])) {
            continue;
        }
        $set = must($survey->optionSet($qid, $role, $spec[$specKey]), 'optionSet(' . $key . '/' . $role . ')');
        $maps[$role] = [];
        foreach ($set['Options'] as $o) {
            $maps[$role][(string) $o['label']] = (int) $o['option_id'];
        }
    }

    $built[$key] = ['id' => $qid, 'type' => (string) $spec['type']] + $maps;
}

// ---------------------------------------------------------------------------
// Answer generation — deliberately lopsided, because uniform noise makes every
// chart look like the same flat bar and proves nothing about the reporting.
// ---------------------------------------------------------------------------

const OTHER_CLASS_TEXTS = ['Peasant', 'Reeve', 'Whatever the team needs', 'Monster crew', 'Healer when nobody else will'];

const PARAGRAPH_POOL = [
    'More shade at the battlefield.',
    'Post the schedule earlier.',
    'A beginner bracket in the tournament.',
    'Feast ran late — court at dusk was cold.',
    'Better signage from the parking lot.',
    'More water stations near the fields.',
    'Start the first battlegame on time.',
    'Cheaper camping for players driving in from out of kingdom.',
    'A quiet camping area away from the drum circle.',
    'More A&S space under cover in case of rain.',
    'Let the reeves rotate so nobody works all day.',
    'Publish the rules packet a week ahead.',
    'Fewer announcements before each battlegame.',
    'A newcomer tent with loaner garb and weapons.',
    'Keep court under an hour.',
    'More port-a-potties on the far side of the field.',
    'Post a map of the site online before the event.',
    'Run the tournament in the morning while people are fresh.',
    'Better lighting on the path back to camp.',
    'Feed the kitchen crew before they serve everyone else.',
];

const AWARD_NAME_POOL = [
    'Order of the Lantern',
    'The Guiding Hand',
    'Order of the Torchbearer',
    "Mentor's Mark",
    'Keeper of the Gate',
    'Order of the Open Door',
    'The Hearthfire',
    'Order of the First Sword',
    'The Welcoming Shield',
    'Order of the Steady Hand',
];

const NOMINATION_POOL = [
    'Sir Kestrel — ran newbie night for two years.',
    'Lady Wren — she loans garb to every new player who shows up.',
    'Squire Bram, who taught half our park to fight.',
    'Our Prime Minister — she answers every question twice, patiently.',
    'Duke Alder for the loaner weapons he keeps rebuilding.',
    'Bex from the shire — quietly mentors everyone under a year.',
    'Master Oakleaf, who runs the A&S beginner table at every event.',
    'Sir Halloran — he walks new fighters through the rules without making them feel dumb.',
];

/**
 * One respondent's answers, keyed by question_id per spec §6.
 *
 * @param  array<string, array<string, mixed>> $b
 * @return array<int, mixed>
 */
function seed_answers(array $b): array
{
    $a = [];
    $skip = static function (): bool {
        // ~5% of optional questions go unanswered, like a real submission.
        return chance(5);
    };

    // 1. class — skewed to the common melee/support classes.
    $cls = (string) wpick([
        'Warrior' => 22, 'Scout' => 15, 'Archer' => 12, 'Healer' => 12,
        'Barbarian' => 7, 'Wizard' => 6, 'Assassin' => 6, 'Monk' => 5,
        'Druid' => 5, 'Paladin' => 4, 'Bard' => 4, 'Anti-Paladin' => 3,
        OTHER_CLASS_LABEL => 4,
    ]);
    $a[$b['q1']['id']] = (OTHER_CLASS_LABEL === $cls)
        ? ['option_id' => $b['q1']['choice'][$cls], 'other' => pick_one(OTHER_CLASS_TEXTS)]
        : $b['q1']['choice'][$cls];

    // 2. participation — independent draws, at least one (min_select 1).
    if (!$skip()) {
        $likelihood = [
            'Battlegames' => 85, 'Ditching' => 55, 'Arts & Sciences' => 30, 'Quests' => 25,
            'Kingdom offices and politics' => 20, 'Camping at events' => 45,
            'Tournaments' => 40, 'Teaching new players' => 35,
        ];
        $picked = [];
        foreach ($likelihood as $label => $pct) {
            if (chance($pct)) {
                $picked[] = $b['q2']['choice'][$label];
            }
        }
        if (!$picked) {
            $picked[] = $b['q2']['choice']['Battlegames'];
        }
        $a[$b['q2']['id']] = $picked;
    }

    // 3. years playing — long tail, median around four.
    $r = random_int(1, 100);
    if ($r <= 20) {
        $years = random_int(0, 1);
    } elseif ($r <= 55) {
        $years = random_int(2, 6);
    } elseif ($r <= 80) {
        $years = random_int(7, 12);
    } elseif ($r <= 94) {
        $years = random_int(13, 20);
    } else {
        $years = random_int(21, 32);
    }
    $a[$b['q3']['id']] = $years;

    // 4. company / household
    if (!$skip()) {
        $a[$b['q4']['id']] = $b['q4']['choice'][chance(38) ? 'Yes' : 'No'];
    }

    // 5. practice day
    if (!$skip()) {
        $a[$b['q5']['id']] = $b['q5']['choice'][(string) wpick([
            'Saturday' => 55, 'Sunday' => 25, 'A weekday evening' => 12, 'It varies' => 8,
        ])];
    }

    // 6. last event rating — people who answer surveys mostly liked it.
    $a[$b['q6']['id']] = (int) wpick([1 => 3, 2 => 7, 3 => 20, 4 => 38, 5 => 32]);

    // 7. NPS — mostly promoters, a real handful of detractors.
    if (!$skip()) {
        $a[$b['q7']['id']] = (int) wpick([
            0 => 1, 1 => 1, 2 => 1, 3 => 2, 4 => 2, 5 => 4, 6 => 5,
            7 => 13, 8 => 20, 9 => 25, 10 => 26,
        ]);
    }

    // 8. satisfaction grid — Communication trends lower than everything else.
    if (!$skip()) {
        $normal        = [1 => 2, 2 => 6, 3 => 18, 4 => 44, 5 => 30];
        $communication = [1 => 10, 2 => 22, 3 => 30, 4 => 28, 5 => 10];
        $columns       = array_values($b['q8']['column']);
        $grid          = [];
        foreach ($b['q8']['row'] as $label => $rowId) {
            if (chance(7) && count($grid) > 0) {
                continue; // require_all_rows is off; a few rows get left blank
            }
            $w = ('Communication before the event' === $label) ? $communication : $normal;
            $grid[$rowId] = $columns[((int) wpick($w)) - 1];
        }
        $a[$b['q8']['id']] = $grid;
    }

    // 9. ranking — rank_all, so all five, but "Good fights" wins most firsts.
    if (!$skip()) {
        $order = wsample([
            'Good fights' => 40, 'Meeting people' => 25, 'The feast' => 15,
            'Court and awards' => 10, 'Arts & Sciences displays' => 10,
        ], 5);
        $a[$b['q9']['id']] = array_map(
            static fn ($label) => $b['q9']['choice'][(string) $label],
            $order
        );
    }

    // 9p. pairwise — each respondent judges a random share of the 36 matchups;
    // a hidden strength per event decides most picks, so the ranking is real.
    if (!$skip()) {
        $strength = [
            'Spring war' => 9, 'Tournament of champions' => 8, 'Quest weekend' => 7, 'Fall feast' => 6,
            'Camping campaign' => 5, 'Fighter practice weekend' => 4, 'Arts & Sciences faire' => 3,
            'Newcomer demo day' => 2, 'Youth day' => 2,
        ];
        $labels = array_keys($strength);
        $pairs = [];
        foreach ($labels as $i => $x) {
            foreach (array_slice($labels, $i + 1) as $y) {
                $pairs[] = [$x, $y];
            }
        }
        shuffle($pairs);
        $list = [];
        foreach (array_slice($pairs, 0, random_int(4, count($pairs))) as [$x, $y]) {
            if (random_int(0, 1) === 1) {
                [$x, $y] = [$y, $x];
            }
            $roll = random_int(1, $strength[$x] + $strength[$y] + 2);
            $idX = $b['q9p']['choice'][$x];
            $idY = $b['q9p']['choice'][$y];
            $list[] = ['a' => $idX, 'b' => $idY, 'w' => $roll <= 2 ? 0 : ($roll - 2 <= $strength[$x] ? $idX : $idY)];
        }
        $a[$b['q9p']['id']] = $list;
    }

    // 10. first event — spread across three decades of play.
    if (!$skip()) {
        $first = mktime(0, 0, 0, 1, 1, 1995);
        $last  = time();
        $a[$b['q10']['id']] = date('Y-m-d', random_int($first, $last));
    }

    // 11. one thing to improve
    if (!$skip()) {
        $text = (string) pick_one(PARAGRAPH_POOL);
        if (chance(25)) {
            $second = (string) pick_one(PARAGRAPH_POOL);
            if ($second !== $text) {
                $text .= ' ' . $second;
            }
        }
        $a[$b['q11']['id']] = $text;
    }

    // 13. award name
    if (!$skip()) {
        $a[$b['q13']['id']] = (string) pick_one(AWARD_NAME_POOL);
    }

    // 14 / 15. nomination — 15 is show_if-gated on "Yes", so honour that here.
    $wouldNominate = null;
    if (!$skip()) {
        $wouldNominate = chance(44);
        $a[$b['q14']['id']] = $b['q14']['choice'][$wouldNominate ? 'Yes' : 'No'];
    }
    if (true === $wouldNominate && !chance(8)) {
        $a[$b['q15']['id']] = (string) pick_one(NOMINATION_POOL);
    }

    // 16. newsletter — one to three picks.
    if (!$skip()) {
        $order = wsample([
            'Event recaps' => 30, 'Award announcements' => 22, 'Fighter tips' => 18,
            'Park spotlights' => 14, 'Rules clarifications' => 10, 'Garb and crafting tutorials' => 12,
        ], random_int(1, 3));
        $a[$b['q16']['id']] = array_map(
            static fn ($label) => $b['q16']['choice'][(string) $label],
            $order
        );
    }

    // 17. how welcoming — mostly 6-10 on a ten-point scale.
    if (!$skip()) {
        $a[$b['q17']['id']] = (int) wpick([
            1 => 1, 2 => 1, 3 => 1, 4 => 2, 5 => 5,
            6 => 12, 7 => 18, 8 => 26, 9 => 22, 10 => 14,
        ]);
    }

    return $a;
}

/**
 * Candidate respondents: active, non-banned players in the target kingdom.
 * Read-only; every write still goes through SurveyResponse::submit().
 *
 * @return list<int>
 */
function candidate_players(int $kingdomId, int $limit): array
{
    global $DB;

    $DB->Clear();
    $rs = $DB->DataSet(
        'SELECT mundane_id FROM ' . DB_PREFIX . 'mundane
         WHERE kingdom_id = ' . (int) $kingdomId . ' AND active = 1 AND penalty_box = 0
         ORDER BY RAND() LIMIT ' . (int) $limit
    );
    $ids = [];
    if ($rs) {
        while ($rs->Next()) {
            $ids[] = (int) $rs->mundane_id;
        }
    }
    return $ids;
}

// ---------------------------------------------------------------------------
// Run
// ---------------------------------------------------------------------------

/** @var Survey $survey */
$survey = Ork3::$Lib->survey;
/** @var SurveyResponse $responses */
$responses = Ork3::$Lib->surveyresponse;

$kingdomId = (int) $opt['kingdom'];
$creator   = (int) $opt['creator'];
$plan      = seed_plan();

if (!$survey->canCreate($creator, 'kingdom', $kingdomId)) {
    bail('Player ' . $creator . ' cannot create a survey for kingdom ' . $kingdomId . '. Pass --creator= a kingdom officer or ORK admin.');
}

$scopeName = $survey->scopeName('kingdom', $kingdomId);
$questionCount = 0;
foreach ($plan as $page) {
    $questionCount += count($page['questions']);
}

say('');
say('Survey seeder — ' . ($opt['dry-run'] ? 'DRY RUN (nothing will be written)' : 'writing'));
say(str_repeat('-', 72));
say('  Title      : ' . $opt['title']);
say('  Scope      : kingdom ' . $kingdomId . ' (' . $scopeName . ')');
say('  Creator    : ' . $creator);
say('  Settings   : data_gate_enabled=1 show_progress=1 allow_resume=1 show_banner=0');
say('  Structure  : ' . count($plan) . ' pages, ' . $questionCount . ' questions');
foreach ($plan as $i => $page) {
    say('    Page ' . ($i + 1) . ' — ' . $page['title']);
    foreach ($page['questions'] as $q) {
        $bits = [];
        if (!empty($q['required'])) {
            $bits[] = 'required';
        }
        foreach (['choices' => 'choices', 'rows' => 'rows', 'columns' => 'columns'] as $k => $name) {
            if (!empty($q[$k])) {
                $bits[] = count($q[$k]) . ' ' . $name;
            }
        }
        if (!empty($q['settings'])) {
            $bits[] = 'settings ' . json_encode($q['settings']);
        }
        if (!empty($q['show_if'])) {
            $bits[] = 'show_if ' . $q['show_if'][0] . '=' . $q['show_if'][1];
        }
        say(sprintf('      %-4s %-11s %s%s', $q['key'], $q['type'], $q['prompt'], $bits ? '  [' . implode(', ', $bits) . ']' : ''));
    }
}
say('  Open       : ' . ($opt['open'] ? 'yes (setStatus open — locks the structure)' : 'no (stays draft)'));
say('  Responses  : ' . ($opt['responses'] > 0 ? $opt['responses'] . ' (consent ~45% full / 35% partial / 20% anonymous, 180-600s)' : 'none'));

if ($opt['responses'] > 0) {
    $poolSize = count(candidate_players($kingdomId, $opt['responses'] * 3 + 100));
    say('  Pool       : ' . $poolSize . ' active, non-banned players available in kingdom ' . $kingdomId);
    if ($poolSize < $opt['responses']) {
        bail('Only ' . $poolSize . ' eligible players in kingdom ' . $kingdomId . '; asked for ' . $opt['responses'] . ' responses.');
    }
    if (!$opt['open']) {
        bail('--responses needs an open survey. Add --open.');
    }
}
say(str_repeat('-', 72));

if ($opt['dry-run']) {
    say('Dry run complete. Nothing was written.');
    exit(0);
}

// --- build -----------------------------------------------------------------

$created  = must($survey->create($creator, 'kingdom', $kingdomId, (string) $opt['title']), 'create');
$surveyId = (int) $created['SurveyId'];

must($survey->update($surveyId, [
    'Description'     => SEED_DESCRIPTION,
    'WelcomeMd'       => SEED_WELCOME_MD,
    'ThanksMd'        => SEED_THANKS_MD,
    'DataGateEnabled' => 1,
    'ShowProgress'    => 1,
    'AllowResume'     => 1,
    'ShowBanner'      => 0,
]), 'update');

$loaded    = must($survey->get($surveyId), 'get');
$firstPage = (int) $loaded['Pages'][0]['page_id'];

$built    = [];
$showIfs  = [];
foreach ($plan as $i => $page) {
    if (0 === $i) {
        $pageId = $firstPage;
    } else {
        $added  = must($survey->pageAdd($surveyId), 'pageAdd(' . ($i + 1) . ')');
        $pageId = (int) $added['Page']['page_id'];
    }
    must($survey->pageUpdate($pageId, ['Title' => $page['title']]), 'pageUpdate(' . $page['title'] . ')');

    foreach ($page['questions'] as $spec) {
        build_question($survey, $surveyId, $pageId, $spec, $built);
        if (!empty($spec['show_if'])) {
            $showIfs[(string) $spec['key']] = $spec['show_if'];
        }
    }
}

// show_if is wired after the build so the source question always exists.
foreach ($showIfs as $key => $cond) {
    [$sourceKey, $optionLabel] = $cond;
    $optionId = $built[$sourceKey]['choice'][$optionLabel] ?? 0;
    if ($optionId <= 0) {
        bail('show_if for ' . $key . ' cannot resolve option "' . $optionLabel . '" on ' . $sourceKey . '.');
    }
    must($survey->questionUpdate($built[$key]['id'], [
        'ShowIfQuestionId' => $built[$sourceKey]['id'],
        'ShowIfOptionId'   => $optionId,
    ]), 'questionUpdate(show_if ' . $key . ')');
}

say('Built survey ' . $surveyId . ' — ' . count($plan) . ' pages, ' . $questionCount . ' questions.');

if ($opt['open']) {
    must($survey->setStatus($surveyId, 'open'), 'setStatus(open)');
    say('Opened survey ' . $surveyId . ' (structure now locked).');
}

// --- responses -------------------------------------------------------------

$wanted = (int) $opt['responses'];
if ($wanted > 0) {
    $candidates = candidate_players($kingdomId, $wanted * 3 + 100);
    $consentPlan = ['full' => 45, 'partial' => 35, 'anonymous' => 20];

    $done    = 0;
    $skipped = 0;
    $tally   = ['full' => 0, 'partial' => 0, 'anonymous' => 0];

    foreach ($candidates as $uid) {
        if ($done >= $wanted) {
            break;
        }
        $consent = (string) wpick($consentPlan);
        $result  = $responses->submit(
            $surveyId,
            $uid,
            seed_answers($built),
            $consent,
            random_int(180, 600),
            false
        );
        if (0 !== (int) ($result['Status'] ?? 1)) {
            ++$skipped;
            if ($skipped <= 5) {
                say('  skip uid ' . $uid . ': ' . ($result['Error'] ?? 'unknown')
                    . (!empty($result['Errors']) ? ' ' . json_encode($result['Errors']) : ''));
            }
            continue;
        }
        ++$done;
        ++$tally[(string) ($result['Consent'] ?? $consent)];
        if (0 === $done % 10) {
            say('  ' . $done . ' / ' . $wanted . ' responses');
        }
    }

    if ($done < $wanted) {
        say('WARNING: only ' . $done . ' of ' . $wanted . ' responses landed (' . $skipped . ' players were ineligible).');
    }
    say('Responses: ' . $done . ' stored (' . $skipped . ' skipped) — '
        . 'full=' . $tally['full'] . ' partial=' . $tally['partial'] . ' anonymous=' . $tally['anonymous'] . '.');
}

// --- report ----------------------------------------------------------------

$final = must($survey->get($surveyId), 'get(final)');
$base  = HTTP_UI . 'index.php?Route=Survey/';

say('');
say('Survey id  : ' . $surveyId);
say('Slug       : ' . $final['Survey']['slug']);
say('Status     : ' . $final['Survey']['status']);
say('Build      : ' . $base . 'build/' . $surveyId);
say('Results    : ' . $base . 'results/' . $surveyId);
say('Take       : ' . $base . 'take/' . $surveyId);
say('');
exit(0);
