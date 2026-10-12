<?php

class Controller_Atlas extends Controller
{
    public function __construct($call = null, $method = null)
    {
        parent::__construct($call, $method);
        $this->Map = new APIModel('Map');
    }

    public function index($action = null)
    {
        $this->data[ 'page_title' ] = "Amtgard Atlas";
        $this->data['Parks'] = $this->Map->GetParkLocations(array('KingdomId' => $kingdom_id));
        // Search snippet + link card (default.theme reuses og description for
        // <meta name=description>); without it the Atlas shared the generic
        // site-wide line.
        $this->data['og'] = array(
            'title'       => 'Amtgard Atlas — Find a Chapter',
            'url'         => UIR . 'Atlas',
            'description' => 'Interactive atlas of Amtgard LARP chapters worldwide — find a chapter near you by map, kingdom, or meeting day.',
        );
    }

    public function map($kingdom_id = null)
    {
        $this->data['Parks'] = $this->Map->GetParkLocations(array('KingdomId' => $kingdom_id));
    }

}
