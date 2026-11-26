<?php

/**
 * Guided Tours: one page listing every OrkTour (script/tours/*.js) with a link
 * that opens the page the tour runs on and starts it there (?ork_tour=<id>).
 * The Resources menu only lists the tours for the page you are on; this is the
 * whole set. Officer tours link only for people who can open that tool.
 *
 * The titles and ids here mirror the OrkTour.register() calls in
 * script/tours/*.js, so a new tour needs a row in catalog() as well. The full
 * checklist for adding one is "ADDING A TOUR" at the top of
 * orkui/template/revised-frontend/script/ork-tour.js.
 */
class Controller_Tour extends Controller
{
    public function __construct($call = null, $method = null)
    {
        parent::__construct($call, $method);
        unset($this->data['menu']['kingdom'], $this->data['menu']['park']);
        $this->data['menu']['tour'] = ['url' => UIR . 'Tour', 'display' => 'Guided Tours'];
        $this->data['no_index'] = true;
    }

    public function index($action = null)
    {
        if (empty($this->data['LoggedIn'])) {
            header('Location: ' . UIR . 'Login/login/Tour');
            exit;
        }
        $uid = (int) $this->session->user_id;

        $this->load_model('Player');
        $this->load_model('Court');
        $this->load_model('QualTest');
        $info      = $this->Player->player_info($uid) ?: [];
        $parkId    = (int) ($info['ParkId'] ?? 0);
        $kingdomId = (int) ($this->Player->get_home_kingdom($uid)['KingdomId'] ?? 0);

        $courtKingdom = $kingdomId > 0 && $this->Court->can_manage($uid, $kingdomId, 0);
        $courtPark    = $parkId > 0 && $this->Court->can_manage($uid, $kingdomId, $parkId);
        $qualTests    = $kingdomId > 0 && $this->QualTest->can_manage($uid, $kingdomId);

        $this->template = 'Tour_index.tpl';
        $this->data['page_title'] = 'Guided Tours';
        $this->data['TourGroups'] = $this->catalog($uid, $kingdomId, $parkId, $courtKingdom, $courtPark, $qualTests);
        $this->data['TourProgress'] = is_array($this->data['TourProgress'] ?? null) ? $this->data['TourProgress'] : [];
    }

    /**
     * @return list<array{Title: string, Tours: list<array<string, mixed>>}>
     */
    private function catalog(int $uid, int $kingdomId, int $parkId, bool $courtKingdom, bool $courtPark, bool $qualTests): array
    {
        $go = static fn (string $route, string $tour): string => UIR . $route . '&ork_tour=' . $tour;
        $profile = 'Player/profile/' . $uid;
        $officerPlace = $courtKingdom ? 'Kingdom/profile/' . $kingdomId : ($courtPark ? 'Park/profile/' . $parkId : '');
        $recsPlace    = $courtKingdom ? 'Recommendations/manage/kingdom/' . $kingdomId : ($courtPark ? 'Recommendations/manage/park/' . $parkId : '');
        $officerOnly  = 'For officers who run court for a kingdom or park.';

        return [
            [
                'Title' => 'For everyone',
                'Tours' => [
                    [
                        'Ids' => ['new-player'], 'Icon' => 'fa-user', 'Title' => 'Your Profile',
                        'About' => 'What each part of your profile shows: attendance, awards, class levels and more.',
                        'Where' => 'Runs on your own profile.',
                        'Links' => [['Label' => 'Start tour', 'Url' => $go($profile, 'new-player')]],
                    ],
                    [
                        'Ids' => ['design-profile'], 'Icon' => 'fa-palette', 'Title' => 'Designing Your Profile',
                        'About' => 'Banners, colors, fonts and icons: how to make your profile your own.',
                        'Where' => 'Runs in Design My Profile, on your own profile.',
                        'Links' => [['Label' => 'Start tour', 'Url' => $go($profile, 'design-profile')]],
                    ],
                    [
                        'Ids' => ['kingdom-profile'], 'Icon' => 'fa-crown', 'Title' => 'Kingdom Profiles',
                        'About' => 'A walk through a kingdom page: its parks, officers, events, players and reports.',
                        'Where' => 'Runs on any kingdom page. This opens your own.',
                        'Links' => $kingdomId > 0 ? [['Label' => 'Start tour', 'Url' => $go('Kingdom/profile/' . $kingdomId, 'kingdom-profile')]] : [],
                        'Note' => 'Open any kingdom page, then choose the tour from Resources.',
                    ],
                    [
                        'Ids' => ['park-profile'], 'Icon' => 'fa-tree', 'Title' => 'Park Profiles',
                        'About' => 'A walk through a park page: when it meets, who runs it, its players and its records.',
                        'Where' => 'Runs on any park page. This opens your own.',
                        'Links' => $parkId > 0 ? [['Label' => 'Start tour', 'Url' => $go('Park/profile/' . $parkId, 'park-profile')]] : [],
                        'Note' => 'Open any park page, then choose the tour from Resources.',
                    ],
                ],
            ],
            [
                'Title' => 'For officers',
                'Tours' => [
                    [
                        'Ids' => ['recommendations-manager'], 'Icon' => 'fa-star', 'Title' => 'The Recommendations Manager',
                        'About' => 'Review award recommendations: filter them, grant them, plan them for court, snooze or dismiss them.',
                        'Where' => 'Runs on the Manage Recs page.',
                        'Links' => $recsPlace !== '' ? [['Label' => 'Start tour', 'Url' => $go($recsPlace, 'recommendations-manager')]] : [],
                        'Note' => $officerOnly,
                    ],
                    [
                        'Ids' => ['court-planner'], 'Icon' => 'fa-gavel', 'Title' => 'The Court Planner',
                        'About' => 'Where courts are created and kept: drafts, status, staged grants and how to open one.',
                        'Where' => 'Runs in Admin Tasks on your kingdom or park page.',
                        'Links' => $officerPlace !== '' ? [['Label' => 'Start tour', 'Url' => $go($officerPlace, 'court-planner')]] : [],
                        'Note' => $officerOnly,
                    ],
                    [
                        'Ids' => ['court-detail'], 'Icon' => 'fa-scroll', 'Title' => 'Planning a Court',
                        'About' => 'Building one court: adding awards, setting the order, then running it and recording grants.',
                        'Where' => 'Runs on a court\'s own page.',
                        'Links' => [],
                        'Note' => $officerPlace !== ''
                            ? 'Open a court from the Court Planner, then choose the tour from Resources.'
                            : $officerOnly,
                    ],
                    [
                        'Ids' => ['qualtest-configure', 'qualtest-builder', 'qualtest-question'], 'Icon' => 'fa-clipboard-check', 'Title' => 'Building Qualification Tests',
                        'About' => 'Set up the Reeve\'s and Corpora tests: settings, who manages them, versions and writing questions.',
                        'Where' => 'Three short tours, one for each page.',
                        'Links' => $qualTests ? [
                            ['Label' => 'Settings', 'Url' => $go('QualTest/manage/' . $kingdomId, 'qualtest-configure')],
                            ['Label' => 'Questions', 'Url' => $go('QualTest/questions/' . $kingdomId . '/reeve', 'qualtest-builder')],
                            ['Label' => 'Writing a question', 'Url' => $go('QualTest/question/create/' . $kingdomId . '/reeve', 'qualtest-question')],
                        ] : [],
                        'Note' => 'For the people who manage a kingdom\'s qualification tests.',
                    ],
                ],
            ],
        ];
    }
}
