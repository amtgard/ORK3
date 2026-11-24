<?php

/**
 * The Hall of Legends (In Memoriam) page: the roll of every Legend (class.Legend.php), each linking
 * to their profile and its Memory Wall. For logged-in members; the home page links
 * to it for them, and each kingdom's reports link to that kingdom's own roll.
 * The Legend modals and the wall itself are in LegendAjax.
 */
class Controller_Legend extends Controller
{
    public function __construct($call = null, $method = null)
    {
        parent::__construct($call, $method);
        unset($this->data['menu']['kingdom'], $this->data['menu']['park']);
        $this->data['menu']['legend'] = ['url' => UIR . 'Legend', 'display' => 'Hall of Legends'];
        $this->data['no_index'] = true;
    }

    public function index($action = null)
    {
        if (empty($this->data['LoggedIn'])) {
            header('Location: ' . UIR . 'Login/login/Legend');
            exit;
        }

        $this->load_model('Legend');
        $this->template = 'Legend_index.tpl';
        $this->data['page_title'] = 'Hall of Legends';
        $this->data['Legends'] = $this->Legend->roll();
    }

    /** One kingdom's Hall of Legends (Legend/kingdom/<id>), linked from the kingdom's reports. */
    public function kingdom($id = null)
    {
        $kingdomId = (int) $id;
        if (empty($this->data['LoggedIn'])) {
            header('Location: ' . UIR . 'Login/login/Legend/kingdom/' . $kingdomId);
            exit;
        }

        $this->load_model('Legend');
        $name = $this->Legend->kingdom_name($kingdomId);
        if ($name === '') {
            header('Location: ' . UIR . 'Legend');
            exit;
        }

        $this->template = 'Legend_index.tpl';
        $this->data['page_title'] = 'Hall of Legends: ' . stripslashes($name);
        $this->data['LegendKingdomId'] = $kingdomId;
        $this->data['LegendKingdomName'] = $name;
        $this->data['Legends'] = $this->Legend->roll($kingdomId);
    }
}
