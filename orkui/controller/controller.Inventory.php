<?php

class Controller_Inventory extends Controller
{
    public function kingdom($id = null) { $this->render('kingdom', (int)preg_replace('/[^0-9]/', '', $id)); }
    public function park($id = null)    { $this->render('park', (int)preg_replace('/[^0-9]/', '', $id)); }

    private function render($owner_type, $owner_id)
    {
        $uid = isset($this->session->user_id) ? (int)$this->session->user_id : 0;
        $authType = $owner_type === 'park' ? AUTH_PARK : AUTH_KINGDOM;
        if (!valid_id($owner_id)) { header('Location: ' . UIR); exit; }
        if (!$uid || !Ork3::$Lib->authorization->HasAuthority($uid, $authType, $owner_id, AUTH_EDIT)) {
            header('Location: ' . UIR . 'Login/login/Inventory/' . $owner_type . '/' . $owner_id); exit;
        }

        $this->template = '../revised-frontend/Inventory_index.tpl';
        $this->load_model('Inventory');
        $tok = $this->session->token;

        $this->data['owner_type']      = $owner_type;
        $this->data['owner_id']        = $owner_id;
        $this->data['categories']      = Inventory::$CATEGORIES;
        $this->data['removal_reasons'] = Inventory::$REMOVAL_REASONS;
        $this->data['conditions']      = Inventory::$CONDITIONS;
        $this->data['treasury_income_categories'] = Treasury::$CATEGORIES['income'];
        $this->data['treasury_methods'] = ['cash' => 'Cash', 'check' => 'Check', 'digital' => 'Digital'];

        $nameRes = $this->Inventory->get_owner_name($tok, $owner_type, $owner_id);
        $this->data['org_name']   = ($nameRes['Status'] ?? 4) === 0 ? $nameRes['Detail']['Name'] : '';
        $this->data['kingdom_id'] = ($nameRes['Status'] ?? 4) === 0 ? (int)$nameRes['Detail']['KingdomId'] : 0;

        $sum = $this->Inventory->get_summary($tok, $owner_type, $owner_id, []);
        $this->data['summary'] = ($sum['Status'] ?? 4) === 0 ? $sum['Detail']
            : ['TotalValue' => 0, 'TotalUnits' => 0, 'LineItems' => 0, 'NeedsRepair' => 0, 'ByCategory' => [], 'ByCondition' => []];

        $items = $this->Inventory->get_items($tok, $owner_type, $owner_id, ['page' => 1, 'per' => 25, 'status' => 'active']);
        $this->data['items'] = ($items['Status'] ?? 4) === 0 ? $items['Detail'] : ['Rows' => [], 'Total' => 0];
    }
}
