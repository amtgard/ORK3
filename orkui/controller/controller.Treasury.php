<?php

class Controller_Treasury extends Controller
{
    public function kingdom($id = null)
    {
        $this->render('kingdom', (int)preg_replace('/[^0-9]/', '', $id));
    }

    public function park($id = null)
    {
        $this->render('park', (int)preg_replace('/[^0-9]/', '', $id));
    }

    private function render($owner_type, $owner_id)
    {
        $uid = isset($this->session->user_id) ? (int)$this->session->user_id : 0;
        $authType = $owner_type === 'park' ? AUTH_PARK : AUTH_KINGDOM;
        if (!valid_id($owner_id)) {
            header('Location: ' . UIR);
            exit;
        }
        if (!$uid || !Ork3::$Lib->authorization->HasAuthority($uid, $authType, $owner_id, AUTH_EDIT)) {
            header('Location: ' . UIR . 'Login/login/Treasury/' . $owner_type . '/' . $owner_id);
            exit;
        }

        $this->template = '../revised-frontend/Treasury_index.tpl';
        $this->load_model('Treasury');
        $tok = $this->session->token;

        $this->data['owner_type'] = $owner_type;
        $this->data['owner_id']   = $owner_id;
        $this->data['categories'] = Treasury::$CATEGORIES;
        $nameRes = $this->Treasury->get_owner_name($tok, $owner_type, $owner_id);
        $this->data['org_name']   = ($nameRes['Status'] ?? 4) === 0 ? $nameRes['Detail']['Name'] : '';
        $this->data['kingdom_id'] = ($nameRes['Status'] ?? 4) === 0 ? (int)$nameRes['Detail']['KingdomId'] : 0;

        $hasOpen = $this->Treasury->has_opening($tok, $owner_type, $owner_id);
        $this->data['has_opening'] = ($hasOpen['Status'] ?? 4) === 0 ? (bool)$hasOpen['Detail']['HasOpening'] : false;

        $sum = $this->Treasury->get_summary($tok, $owner_type, $owner_id, null, null);
        $this->data['summary'] = ($sum['Status'] ?? 4) === 0 ? $sum['Detail'] : ['CurrentBalance' => 0, 'TotalIn' => 0, 'TotalOut' => 0, 'ByCategory' => []];

        $led = $this->Treasury->get_ledger($tok, $owner_type, $owner_id, ['page' => 1, 'per' => 25]);
        $this->data['ledger'] = ($led['Status'] ?? 4) === 0 ? $led['Detail'] : ['Rows' => [], 'Total' => 0];

        $rec = $this->Treasury->get_reconciliations($tok, $owner_type, $owner_id);
        $this->data['reconciliations'] = ($rec['Status'] ?? 4) === 0 ? $rec['Detail']['Rows'] : [];

        $ser = $this->Treasury->get_series($tok, $owner_type, $owner_id);
        $this->data['series'] = ($ser['Status'] ?? 4) === 0 ? $ser['Detail']['Points'] : [];
    }
}
