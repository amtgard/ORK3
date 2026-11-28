<?php

/**
 * The Circle of Knights Manager page: a kingdom's knight roster and eligibility,
 * the candidate pipeline, and the Circle's settings and officers.
 *
 * Route: ?Route=KnightCircle/index/{kingdom_id}[&tab=roster|pipeline|settings]
 *
 * This layer reads the session and request and hands the template a JSON config
 * (window.KC_CFG). Who may see or change what is decided in the domain
 * (KnightCircle::access); the page only hides controls the viewer cannot use.
 * Data loads through KnightCircleAjax.
 */
class Controller_KnightCircle extends Controller
{
    public function __construct($call = null, $id = null)
    {
        parent::__construct($call, $id);
        $this->load_model('KnightCircle');
        $this->load_model('Kingdom');
    }

    public function index($kingdom_id = null)
    {
        $this->template = '../revised-frontend/KnightCircle_index.tpl';

        $kid = (int) preg_replace('/[^0-9].*$/', '', (string) ($kingdom_id ?? ''));
        $uid = isset($this->session->user_id) ? (int) $this->session->user_id : 0;
        $this->data['KingdomId'] = $kid;

        if (!valid_id($kid)) {
            $this->data['Error'] = 'That kingdom does not exist.';
            return;
        }
        if ($uid <= 0) {
            $this->data['Error'] = 'Log in to see the Circle of Knights.';
            return;
        }

        $access = $this->KnightCircle->access($uid, $kid);
        if (empty($access['CanView'])) {
            $this->data['Error'] = 'You do not have access to this kingdom\'s Circle of Knights.';
            return;
        }

        $info = $this->Kingdom->get_kingdom_info($kid);
        $name = (string) ($info['KingdomInfo']['KingdomName'] ?? '');
        if ($name === '') {
            $this->data['Error'] = 'That kingdom does not exist.';
            return;
        }

        $tab = (string) ($_GET['tab'] ?? 'roster');
        if (!in_array($tab, ['roster', 'pipeline', 'settings'], true)) {
            $tab = 'roster';
        }

        $orders = [];
        foreach (KnightCircle::ORDERS as $id => $short) {
            $orders[] = ['id' => (int) $id, 'short' => $short, 'name' => KnightCircle::ORDER_NAMES[$id] ?? $short];
        }

        $this->data['KingdomName'] = $name;
        $this->data['Tab']         = $tab;
        $this->data['Access']      = $access;
        $this->data['KcCfg']       = [
            'kingdomId'   => $kid,
            'kingdomName' => $name,
            'userId'      => $uid,
            'tab'         => $tab,
            'access'      => $access,
            'config'      => $this->KnightCircle->config($kid),
            'orders'      => $orders,
            'stages'      => KnightCandidate::STAGES,
            'boardStages' => KnightCandidate::BOARD_STAGES,
            'transitions' => KnightCandidate::TRANSITIONS,
            'urls'        => [
                'ajax'         => UIR . 'KnightCircleAjax/',
                'playersearch' => UIR . 'KingdomAjax/playersearch/' . $kid,
                'player'       => UIR . 'Player/profile/',
                'kingdom'      => UIR . 'Kingdom/profile/' . $kid,
            ],
        ];
    }
}
