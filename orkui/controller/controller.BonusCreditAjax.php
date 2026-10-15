<?php

/**
 * JSON endpoints for bonus credits (class credits that are not attendance)
 * and the categories they are granted for. Backs the Add Bonus Credits modal on
 * the park and kingdom pages and the credit list on the player page.
 *
 * Envelope: {status: 0, ...} ok; {status: 1, error} bad request;
 * {status: 3, error} not authorized; {status: 5, error} not logged in.
 * Authority is decided in the domain (BonusCredit::canGrant on the category's
 * org); this layer only reads the request and the session.
 */
class Controller_BonusCreditAjax extends Controller
{
    public function __construct($call = null, $id = null)
    {
        parent::__construct($call, $id);
        $this->load_model('BonusCredit');
    }

    /**
     * Everything the modal needs to open. A kingdom host gets the credits it
     * defines and may create more; a park host gets the credits its kingdom
     * (and the kingdoms above it) defined, to hand out to its own players.
     */
    public function options($p = null)
    {
        $uid = $this->requireLogin();
        [$scopeType, $scopeId] = $this->scope();
        $orkCredits = [];
        if ($scopeType === 'ork') {
            // Amtgard-wide: ORK admins only. They define, and may grant anywhere a kingdom has adopted.
            if (!$this->BonusCredit->is_ork_admin($uid)) {
                $this->jsonOut(['status' => 3, 'error' => 'Only ORK admins can manage Amtgard-wide credits.']);
            }
            $categories    = $this->BonusCredit->categories('ork', 0, !empty($_GET['archived']));
            $canDefine  = true;
            $canAppoint = false;
            $granters   = [];
        } elseif ($scopeType === 'kingdom') {
            // Kingdom EDIT opens the modal to hand credits out across the kingdom;
            // only CREATE (or ADMIN) gets the Credits and Granters tabs.
            $canDefine = $this->BonusCredit->can_define($uid, 'kingdom', $scopeId);
            if (!$canDefine && !$this->BonusCredit->can_grant_in_kingdom($uid, $scopeId)) {
                $this->jsonOut(['status' => 3, 'error' => 'You do not have authority over this kingdom.']);
            }
            // The kingdom's own credits plus adopted Amtgard-wide ones; a granter-only
            // user sees just the credits their rows cover.
            $categories    = $this->BonusCredit->grantable_categories_in_kingdom($uid, $scopeId, $canDefine && !empty($_GET['archived']));
            $canAppoint = $this->BonusCredit->can_appoint($uid, $scopeId);
            $granters   = $canAppoint ? $this->BonusCredit->granters($scopeId) : [];
            // Every live Amtgard-wide credit, flagged with this kingdom's adoption, for the Credits tab.
            $orkCredits = $canDefine ? $this->BonusCredit->ork_credits_for($scopeId) : [];
        } else {
            if (!$this->BonusCredit->can_grant($uid, $scopeId)) {
                $this->jsonOut(['status' => 3, 'error' => 'You do not have authority over this park.']);
            }
            $categories = $this->BonusCredit->grantable_categories_for_park($uid, $scopeId);
            $canDefine = false;
            $canAppoint = false;
            $granters = [];
        }
        $this->load_model('Attendance');
        $classes = array_map(function ($c) {
            return ['ClassId' => (int) $c['ClassId'], 'ClassName' => (string) $c['Name']];
        }, $this->Attendance->get_classes()['Classes'] ?? []);
        $this->jsonOut(['status' => 0, 'categories' => $categories, 'classes' => $classes, 'canDefine' => $canDefine, 'canAppoint' => $canAppoint,
                        'granters' => $granters, 'orkCredits' => $orkCredits]);
    }

    /** Adopt an Amtgard-wide credit for a kingdom (kingdom CREATE, or an ORK admin on its behalf). */
    public function adopt($p = null)
    {
        $uid = $this->requireLogin();
        $kingdomId = (int) ($_POST['KingdomId'] ?? 0);
        $r = $this->BonusCredit->adopt($uid, (int) ($_POST['CategoryId'] ?? 0), $kingdomId);
        $this->envelope($r, ['adopted' => true, 'orkCredits' => $this->BonusCredit->ork_credits_for($kingdomId),
                             'categories' => $this->BonusCredit->grantable_categories_in_kingdom($uid, $kingdomId, true)]);
    }

    /** Withdraw a kingdom's adoption of an Amtgard-wide credit. */
    public function withdraw($p = null)
    {
        $uid = $this->requireLogin();
        $kingdomId = (int) ($_POST['KingdomId'] ?? 0);
        $r = $this->BonusCredit->withdraw($uid, (int) ($_POST['CategoryId'] ?? 0), $kingdomId);
        $this->envelope($r, ['adopted' => false, 'orkCredits' => $this->BonusCredit->ork_credits_for($kingdomId),
                             'categories' => $this->BonusCredit->grantable_categories_in_kingdom($uid, $kingdomId, true)]);
    }

    /** The kingdoms that adopted an Amtgard-wide credit (ORK admins). */
    public function adoptions($p = null)
    {
        $uid = $this->requireLogin();
        if (!$this->BonusCredit->is_ork_admin($uid)) {
            $this->jsonOut(['status' => 3, 'error' => 'Not authorized.']);
        }
        $this->jsonOut(['status' => 0, 'adoptions' => $this->BonusCredit->adoptions((int) ($_GET['CategoryId'] ?? $_POST['CategoryId'] ?? 0))]);
    }

    /** Appoint a bonus credit granter for a kingdom (kingdom CREATE). */
    public function granter_add($p = null)
    {
        $uid = $this->requireLogin();
        $r = $this->BonusCredit->add_granter($uid, (int) ($_POST['KingdomId'] ?? 0), (int) ($_POST['MundaneId'] ?? 0), (int) ($_POST['CategoryId'] ?? 0));
        $this->envelope($r, ['granters' => $r['Granters'] ?? []]);
    }

    /** Remove a bonus credit granter (kingdom CREATE). */
    public function granter_remove($p = null)
    {
        $uid = $this->requireLogin();
        $r = $this->BonusCredit->remove_granter($uid, (int) ($_POST['KingdomId'] ?? 0), (int) ($_POST['GranterId'] ?? 0));
        $this->envelope($r, ['granters' => $r['Granters'] ?? []]);
    }

    public function category_create($p = null)
    {
        $uid = $this->requireLogin();
        [$scopeType, $scopeId] = $this->scope();
        if ($scopeType === 'park') {
            $this->jsonOut(['status' => 1, 'error' => 'Credits are defined by a kingdom, or Amtgard-wide.']);
        }
        $r = $this->BonusCredit->create_category($uid, [
            'ScopeType'      => $scopeType,
            'ScopeId'        => $scopeId,
            'Name'           => $_POST['Name'] ?? '',
            'Description'    => $_POST['Description'] ?? '',
            'DefaultCredits' => $_POST['DefaultCredits'] ?? 1,
            'Repeatable'     => $_POST['Repeatable'] ?? 0,
        ]);
        $this->envelope($r, ['category' => $r['Category'] ?? null, 'restored' => !empty($r['Restored'])]);
    }

    public function category_archive($p = null)
    {
        $uid = $this->requireLogin();
        $r = $this->BonusCredit->archive_category($uid, (int) ($_POST['CategoryId'] ?? 0));
        $this->envelope($r, ['category' => $r['Category'] ?? null]);
    }

    public function category_restore($p = null)
    {
        $uid = $this->requireLogin();
        $r = $this->BonusCredit->restore_category($uid, (int) ($_POST['CategoryId'] ?? 0));
        $this->envelope($r, ['category' => $r['Category'] ?? null]);
    }

    /**
     * Who already holds a category's credit (newest first). A kingdom officer
     * sees every holder; a park host (ParkId) sees its own players only, and
     * must be allowed to grant there.
     */
    public function category_credits($p = null)
    {
        $uid = $this->requireLogin();
        $category = $this->BonusCredit->category((int) ($_GET['CategoryId'] ?? $_POST['CategoryId'] ?? 0));
        if ($category === null) {
            $this->jsonOut(['status' => 1, 'error' => 'Credit not found.']);
        }
        $parkId    = (int) ($_GET['ParkId'] ?? $_POST['ParkId'] ?? 0);
        $kingdomId = (int) ($_GET['KingdomId'] ?? $_POST['KingdomId'] ?? 0);
        $offered = function (array $list) use ($category) {
            foreach ($list as $rs) {
                if ($rs['CategoryId'] === $category['CategoryId']) {
                    return true;
                }
            }
            return false;
        };
        if ($parkId > 0) {
            // A park host: the credit must be offered there, and the caller allowed to grant there.
            if (!$this->BonusCredit->can_grant($uid, $parkId) || !$offered($this->BonusCredit->categories_for_park($parkId))) {
                $this->jsonOut(['status' => 3, 'error' => 'Not authorized.']);
            }
        } elseif ($kingdomId > 0) {
            // A kingdom host: its own credits and adopted Amtgard-wide ones, listed for its own players.
            if (!$this->BonusCredit->can_grant_in_kingdom($uid, $kingdomId) || !$offered($this->BonusCredit->categories_for_kingdom($kingdomId, true))) {
                $this->jsonOut(['status' => 3, 'error' => 'Not authorized.']);
            }
        } elseif (!$this->BonusCredit->can_define($uid, $category['ScopeType'], $category['ScopeId'])) {
            $this->jsonOut(['status' => 3, 'error' => 'Not authorized.']);
        }
        $this->jsonOut(['status' => 0, 'category' => $category, 'credits' => $this->BonusCredit->for_category($category['CategoryId'], $parkId, 200, $kingdomId)]);
    }

    public function grant($p = null)
    {
        $uid = $this->requireLogin();
        $r = $this->BonusCredit->grant($uid, [
            'MundaneId' => $_POST['MundaneId'] ?? 0,
            'ClassId'   => $_POST['ClassId'] ?? 0,
            'CategoryId'  => $_POST['CategoryId'] ?? 0,
            'Date'      => $_POST['Date'] ?? date('Y-m-d'),
            'Note'      => $_POST['Note'] ?? '',
        ]);
        $this->envelope($r, ['credit' => $r['Credit'] ?? null]);
    }

    public function revoke($p = null)
    {
        $uid = $this->requireLogin();
        $r = $this->BonusCredit->revoke($uid, (int) ($_POST['BonusCreditId'] ?? 0), (string) ($_POST['Note'] ?? ''));
        $this->envelope($r, []);
    }

    /**
     * A player's bonus credits. The player sees their own; an officer with
     * authority over the player's park sees them too (and revoked ones).
     */
    public function player($p = null)
    {
        $uid = $this->requireLogin();
        $mundaneId = (int) ($_GET['MundaneId'] ?? $_POST['MundaneId'] ?? $p ?? 0);
        if (!valid_id($mundaneId)) {
            $this->jsonOut(['status' => 1, 'error' => 'Invalid player.']);
        }
        $this->load_model('Player');
        $info = $this->Player->player_info($mundaneId);
        $parkId = is_array($info) ? (int) ($info['ParkId'] ?? 0) : 0;
        $officer = $parkId > 0 && $this->BonusCredit->can_grant($uid, $parkId);
        if ($uid !== $mundaneId && !$officer) {
            $this->jsonOut(['status' => 3, 'error' => 'Not authorized.']);
        }
        $this->jsonOut(['status' => 0, 'credits' => $this->BonusCredit->for_player($mundaneId, $officer && !empty($_GET['revoked']))]);
    }

    // -----------------------------------------------------------------------

    private function requireLogin(): int
    {
        if (!isset($this->session->user_id) || (int) $this->session->user_id <= 0) {
            $this->jsonOut(['status' => 5, 'error' => 'Not logged in']);
        }
        return (int) $this->session->user_id;
    }

    /** @return array{0:string, 1:int} */
    private function scope(): array
    {
        $type = (string) ($_POST['ScopeType'] ?? $_GET['ScopeType'] ?? '');
        $id   = (int) ($_POST['ScopeId'] ?? $_GET['ScopeId'] ?? 0);
        if ($type === 'ork') {
            return ['ork', 0];
        }
        if (!in_array($type, ['kingdom', 'park'], true) || !valid_id($id)) {
            $this->jsonOut(['status' => 1, 'error' => 'Choose a kingdom or park.']);
        }
        return [$type, $id];
    }

    private function envelope(array $r, array $extra): void
    {
        if ((int) ($r['Status'] ?? 1) !== 0) {
            $this->jsonOut(['status' => (int) $r['Status'], 'error' => (string) ($r['Error'] ?? 'Request failed.')]);
        }
        $this->jsonOut(['status' => 0] + $extra);
    }

    private function jsonOut(array $payload): void
    {
        header('Content-Type: application/json');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    }
}
