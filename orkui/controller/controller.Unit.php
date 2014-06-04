<?php

class Controller_Unit extends Controller {

	public function __construct($call=null, $id=null) {
		parent::__construct($call, $id);

	}

	public function unitlist($params=null) {
		$this->data['Units'] = $this->Unit->get_unit_list(array(
									'KingdomId' => $this->request->KingdomId,
									'ParkId' => $this->request->ParkId,
									'IncludeCompanies' => 1,
									'IncludeHouseHolds' => 1,
									'IncludeEvents' => 1
								));
	}

	public function index($unit_id) {
		$this->data['Unit_heraldryurl'] = $this->Unit->get_heraldry($unit_id);
		$this->data['Unit'] = $this->Unit->get_unit_details($unit_id);
		$this->data['menu']['admin'] = array( 'url' => UIR."Admin/unit/$unit_id", 'display' => 'Admin' );
		$this->data['menu']['unit'] = array( 'url' => UIR."Unit/index/$id", 'display' => $this->data['Unit']['Details']['Unit']['Name'] );
	}
	
	public function create($mundane_id) {
		if (trimlen($this->request->Action) > 0) {
			$this->request->save('Unit_create', true);
			if (!isset($this->session->user_id)) {
				header( 'Location: '.UIR.'Login/login/Unit/create/' . $mundane_id );
			} else {
				if ($_FILES['Heraldry']['size'] > 0 && Common::supported_mime_types($_FILES['Heraldry']['type'])) {
					if (move_uploaded_file($_FILES['Heraldry']['tmp_name'], DIR_TMP . sprintf("um_%05d", $mundane_id))) {
						$h_im = file_get_contents(DIR_TMP . sprintf("um_%05d", $mundane_id));
						$h_imdata = base64_encode($h_im); 
					} else {
						$Status = array(
							'Status' => 1000,
							'Error' => 'File IO Error',
							'Detail' => 'File could not be moved to .../tmp',
						);
					}
				}
				$r = $this->Unit->create_unit(array(
						'Heraldry' => $h_imdata,
						'HeraldryMimeType' => $_FILES['Heraldry']['type'],
						'Name' => $this->request->Unit_create->Name,
						'Type' => $this->request->Unit_create->Type,
						'Description' => $this->request->Unit_create->Description,
						'History' => $this->request->Unit_create->History,
						'Url' => $this->request->Unit_create->Url,
						'Token' => $this->session->token,
						'MundaneId' => $mundane_id
					));
				if ($r['Status'] == 0) {
					$this->request->clear('Unit_create');
					header( 'Location: '.UIR.'Unit/index/' . $r['Detail'] );
				} else if($r['Status'] == 5) {
					header( 'Location: '.UIR.'Login/login/Unit/create/' . $mundane_id );
				} else {
					$this->data['Error'] = $r['Error'].':<p>'.$r['Detail'];
				}
			}
		}
		if ($this->request->exists('Unit_create')) {
			$this->data['Unit_create'] = $this->request->Unit_create->Request;
		}
		$this->data['MundaneId'] = $mundane_id;
	}
}



?>