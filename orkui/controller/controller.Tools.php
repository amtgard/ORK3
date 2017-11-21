<?php

class Controller_Tools extends Controller {

	public function __construct($call=null, $id=null) {
		parent::__construct($call, $id);

		$this->load_model('Tools');
		$this->load_model('Kingdom');
		$this->data['Call'] = $call;
	}

	public function index($duh = null) {
  }

  public function digitalsignature() {
  }
  
  public function editchapter($params=null) {
		$params = explode('/',$params);
		if ('submit' == $params[0]) {
			$post = 'submit';
			$this->data['KingdomId'] = $this->session->kingdom_id;
		} else if ('park' == $params[0]) {
			$park_id = $params[1];
			$this->data['ParkId'] = $park_id;
			$this->data['KingdomId'] = $this->session->kingdom_id;
		} else if ('kingdom' == $params[0]) {
			$kingdom_id = $params[1];
			$this->data['KingdomId'] = $kingdom_id;
		}
		logtrace('createpark', $params);
		if (strlen($post) > 0) {
			$this->request->save('Tools_addchapter', true);
			if (!isset($this->session->user_id)) {
				header('Location: '.UIR.'Login/login/Admin/createpark' . (($post!=null)?('/'.$post):''));
			} else if (trimlen($this->request->Tools_addchapter->Name) == 0) {
				$this->data['Error'] = "Park must have a name.";
			} else if (trimlen($this->request->Tools_addchapter->Abbreviation) == 0) {
				$this->data['Error'] = "Park must have an abbreviation.";
			} else if (!valid_id($this->request->Tools_addchapter->kingdom_id)) {
				$this->data['Error'] = "Somehow, a Kingdom was not selected.  Good luck with that.";
			} else if (!valid_id($this->request->Tools_addchapter->ParkTitleId)) {
				$this->data['Error'] = "Parks must have a title.";
			} else {
				$r = $this->Park->create_park(array(
						'Token' => $this->session->token,
						'Name' => $this->request->Tools_addchapter->Name,
						'Abbreviation' => $this->request->Tools_addchapter->Abbreviation,
						'KingdomId' => $this->session->kingdom_id,
						'ParkTitleId' => $this->request->Tools_addchapter->ParkTitleId
					));
				if ($r['Status'] == 0) {
					$this->request->clear('Tools_addchapter');
					//header( 'Location: '.UIR.'Park/index/'.$r['Detail'] );
				} else if($r['Status'] == 5) {
					header('Location: '.UIR.'Login/login/Admin/createpark' . (($post!=null)?('/'.$post):''));
				} else {
					$this->data['Error'] = $r['Error'].':<p>'.$r['Detail'];
				}
			}
		}
		$this->data['ParkTitleId_options'] = array();
		if ($this->request->exists('Tools_addchapter')) {
			$this->data['Tools_addchapter'] = $this->request->Tools_addchapter->Request;
		}
  }
  
  public function submitchapter($params=null) {
		
	}

	public function digitalsignature($params=null) {
		
	}
  
}