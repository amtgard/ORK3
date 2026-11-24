<?php

/**
 * The Hall of Legends (In Memoriam) page: the roll of every Legend (class.Legend.php), each linking
 * to their profile and its Memory Wall. For logged-in members; the home page links
 * to it for them. The Legend modals and the wall itself are in LegendAjax.
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
}
