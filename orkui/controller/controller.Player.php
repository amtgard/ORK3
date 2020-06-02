<?php

class Controller_Player extends Controller {

	public function __construct($call=null, $id=null) {
		parent::__construct($call, $id);

		$this->load_model('Park');
		$this->load_model('Award');
		$params = explode('/',$id);
		$id = $params[0];
				
		$this->data['Player'] = $this->Player->fetch_player($id);
		
		$park_info = $this->Park->get_park_info($this->data['Player']['ParkId']);
		$this->session->park_name = $park_info['ParkInfo']['ParkName'];
		$this->session->park_id = $park_info['ParkInfo']['ParkId'];
		$this->session->kingdom_id = $park_info['KingdomInfo']['KingdomId'];
		$this->session->kingdom_name = $park_info['KingdomInfo']['KingdomName'];
		$this->data['menu']['admin'] = array( 'url' => UIR.'Admin/player/'.$id, 'display' => 'Admin Panel <i class="fas fa-cog"></i>', 'no-crumb' => 'no-crumb' );
		$this->data['menulist']['admin'] = array(
				array( 'url' => UIR.'Admin/player/'.$id, 'display' => 'Player' ),
				array( 'url' => UIR.'Admin/park/'.$this->session->park_id, 'display' => 'Park' ),
				array( 'url' => UIR.'Admin/kingdom/'.$this->session->kingdom_id, 'display' => 'Kingdom' )
			);
		if (valid_id($this->session->kingdom_id)) {
			$this->data['menu']['kingdom'] = array( 'url' => UIR.'Kingdom/index/'.$this->session->kingdom_id, 'display' => $this->session->kingdom_name );
			$this->data['menu']['park'] = array( 'url' => UIR.'Park/index/'.$this->session->park_id, 'display' => $this->session->park_name );
		} else {
			unset($this->data['menu']['kingdom']);
			unset($this->data['menu']['park']);
		}
		$this->data['menu']['player'] = array( 'url' => UIR."Player/$call/$id", 'display' => $this->data['Player']['Persona'] );
	
	}
	
	public function index($id=NULL) {
		$this->load_model('Unit');
		
		$params = explode('/',$id);
		$id = $params[0];
		if (count($params) > 1)
			$action = $params[1];
		if (count($params) > 2)
			$roastbeef = $params[2];
				
		if (strlen($action) > 0) {
			$this->request->save('Player_index', true);
			$r = array('Status'=>0);
			if (!isset($this->session->user_id)) {
				header( 'Location: '.UIR."Login/login/Player/index/$id" );
			} else {
				switch ($action) {
					case 'updateclasses':
						$class_update = array();
						if (is_array($this->request->Reconciled)) {
							foreach ($this->request->Reconciled as $class_id => $qty) {
								$class_update[] = array( 'ClassId' => $class_id, 'Quantity' => $qty );
							}
							$this->Player->update_class_reconciliation(array( 'Token' => $this->session->token, 'MundaneId' => $id, 'Reconcile' => $class_update ));
						}
						break;
					case 'update':
						if ($_FILES['Heraldry']['size'] > 0 && Common::supported_mime_types($_FILES['Heraldry']['type'])) {
							if (move_uploaded_file($_FILES['Heraldry']['tmp_name'], DIR_TMP . sprintf("h_%06d", $id))) {
								$h_im = file_get_contents(DIR_TMP . sprintf("h_%06d", $id));
								$h_imdata = base64_encode($h_im); 
							}
						}
						if ($_FILES['Waiver']['size'] > 0 && Common::supported_mime_types($_FILES['Waiver']['type'])) {
							if (move_uploaded_file($_FILES['Waiver']['tmp_name'], DIR_TMP . sprintf("w_%06d", $id))) {
								$w_im = file_get_contents(DIR_TMP . sprintf("w_%06d", $id));
								$w_imdata = base64_encode($w_im); 
							}
						}
						$r = $this->Player->update_player(array(
								'MundaneId' => $id,
								'GiveName' =>  $this->request->Player_index->GivenName,
								'Surname' =>  $this->request->Player_index->Surname,
								'Persona' =>  $this->request->Player_index->Persona,
								'UserName' =>  $this->request->Player_index->UserName,
								'Password' =>  $this->request->Player_index->Password==$this->request->Player_index->PasswordAgain?$this->request->Player_index->Password:null,
								'Email' =>  $this->request->Player_index->Email,
								'Restricted' =>  $this->request->Player_index->Restricted=='Restricted'?1:0,
								'Active' =>  $this->request->Player_index->Active=='Active'?1:0,
								'HasImage' => strlen($pi_imdata),
								'Image' => strlen($pi_imdata)>0?$pi_imdata:null,
								'ImageMimeType' => strlen($pi_imdata)>0?$_FILES['PlayerImage']['type']:'',
								'Heraldry' => strlen($h_imdata)>0?$h_imdata:null,
								'HeraldryMimeType' => strlen($h_imdata)>0?$_FILES['Heraldry']['type']:'',
								'Waivered' => strlen($w_imdata),
								'Waiver' => strlen($w_imdata)>0?$w_imdata:null,
								'WaiverMimeType' => strlen($w_imdata)>0?$_FILES['Waiver']['type']:'',
								'Token' => $this->session->token
							));
						if ($this->request->Player_index->Password!=$this->request->Player_index->PasswordAgain)
							$this->data['Error'] = 'Passwords do not match.';
						break;
					case 'addaward':
						$r = $this->Player->add_player_award(array(
								'Token' => $this->session->token,
								'RecipientId' => $id,
								'AwardId' => $this->request->Player_index->AwardId,
								'Rank' => $this->request->Player_index->Rank,
								'Date' => $this->request->Player_index->Date,
								'GivenById' => $this->request->Player_index->MundaneId,
								'Note' => $this->request->Player_index->Note,
								'ParkId' => valid_id($this->request->Player_index->ParkId)?$this->request->Player_index->ParkId:0,
								'KingdomId' => valid_id($this->request->Player_index->KingdomId)?$this->request->Player_index->KingdomId:0,
								'EventId' => valid_id($this->request->Player_index->EventId)?$this->request->Player_index->EventId:0
							));
						break;
					case 'deleteaward':
						$r = $this->Player->delete_player_award(array(
								'Token' => $this->session->token,
								'AwardsId' => $roastbeef
							));
						break;
					case 'updateaward':
						$r = $this->Player->update_player_award(array(
								'Token' => $this->session->token,
								'AwardsId' => $roastbeef,
								'RecipientId' => $id,
								'AwardId' => $this->request->Player_index->AwardId,
								'Rank' => $this->request->Player_index->Rank,
								'Date' => $this->request->Player_index->Date,
								'GivenById' => $this->request->Player_index->MundaneId,
								'Note' => $this->request->Player_index->Note,
								'ParkId' => valid_id($this->request->Player_index->ParkId)?$this->request->Player_index->ParkId:0,
								'KingdomId' => valid_id($this->request->Player_index->KingdomId)?$this->request->Player_index->KingdomId:0,
								'EventId' => valid_id($this->request->Player_index->EventId)?$this->request->Player_index->EventId:0
							));
						break;
				}
				if ($r['Status'] == 0) {
					$this->data['Message'] = 'Player has been updated';
					$this->request->clear('Player_index');
				} else if($r['Status'] == 5) {
					header( 'Location: '.UIR."Login/login/Player/index/$id" );
				} else {
					$this->data['Error'] = $r['Error'].':<p>'.$r['Detail'];
				}
			}
		}
		
		if ($this->request->exists('Player_index')) {
			$this->data['Player_index'] = $this->request->Player_index->Request;
		}
		$this->data['KingdomId'] = $this->session->kingdom_id;
		$this->data['AwardOptions'] = $this->Award->fetch_award_option_list($this->session->kingdom_id, 'Awards');
		$this->data['OfficerOptions'] = $this->Award->fetch_award_option_list($this->session->kingdom_id, 'Officers');
		$this->data['Player'] = $this->Player->fetch_player($id);
		$this->data['Details'] = $this->Player->fetch_player_details($id);
    	$this->data['Notes'] = $this->Player->get_notes($id);
		$this->data['Units'] = $this->Unit->get_unit_list(array( 'MundaneId' => $id, 'IncludeCompanies' => 1, 'IncludeHouseHolds' =>1, 'IncludeEvents' => 1, 'ActiveOnly' => 1 ));
		$this->data['menu']['admin'] = array( 'url' => UIR."Admin/player/$id", 'display' => 'Admin Panel <i class="fas fa-cog"></i>', 'no-crumb' => 'no-crumb' );
		$this->data['menu']['player'] = array( 'url' => UIR."Player/index/$id", 'display' => $this->data['Player']['Persona'] );
		
	}
	
}



?>