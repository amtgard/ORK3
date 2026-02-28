<?php

class Controller_Attendance extends Controller {

	public function __construct($call=null, $id=null) {
		parent::__construct($call, $id);

		$this->load_model('Park');
		$this->data[ 'no_index' ] = true;
		
		switch ($call) {
			case 'kingdom':
				break;
			case 'park':
				$park_info = $this->Park->get_park_info($id);
				$this->session->park_id = $park_info['ParkInfo']['ParkId'];
				$this->session->park_name = $park_info['ParkInfo']['ParkName'];
				$this->session->kingdom_id = $park_info['KingdomInfo']['KingdomId'];
				$this->session->kingdom_name = $park_info['KingdomInfo']['KingdomName'];
				$this->data['menu']['kingdom'] = array( 'url' => UIR.'Kingdom/index/'.$this->session->kingdom_id, 'display' => $this->session->kingdom_name );
				$this->data['menu']['park'] = array( 'url' => UIR.'Park/index/'.$this->session->park_id, 'display' => $this->session->park_name );
				break;
			case 'event':
				break;
		}
		
		$params = explode('/',$id);
		$id = $params[0];
		$this->data['menu']['attendance'] = array( 'url' => UIR."Attendance/$call/$id", 'display' => 'Attendance' );
	
	}
	
	public function index($action = null) {
	
	}
	
	public function kingdom($k) {
		$params = explode('/',$k);
		$id = $params[0];
		if (count($params) > 1)
			$action = $params[1];
		if (count($params) > 2)
			$del_id = $params[2];

		$this->data['Type'] = $type;
		$this->data['Id'] = $id;
		
		$this->data['DefaultCredits'] = 1;
		
		if (strlen($action) > 0) {
			$this->request->save('Attendance_kingdom', true);
			$r = array('Status'=>0);
			if (!isset($this->session->user_id)) {
				header( 'Location: '.UIR."Login/login/Attendance/park/$id" );
			} else {
				switch ($action) {
					case 'new':
						$r = $this->Attendance->add_attendance(
								$this->session->token, 
								$this->request->Attendance_kingdom->AttendanceDate, 
								$id, 
								null,
								$this->request->Attendance_kingdom->MundaneId, 
								$this->request->Attendance_kingdom->ClassId, 
								$this->request->Attendance_kingdom->Credits
							);
						break;
						$this->data['DefaultCredits'] = $this->request->Attendance_kingdom->Credits;
					case 'delete':
						$r = $this->Attendance->delete_attendance($this->session->token, $del_id);
						break;
				}
				if ($r['Status'] == 0) {
					$this->data['AttendanceDate'] = $this->request->Attendance_kingdom->AttendanceDate;
					$this->request->clear('Attendance_kingdom');
				} else if($r['Status'] == 5) {
					header( 'Location: '.UIR."Login/login/Attendance/park/$id" );
				} else {
					$this->data['Error'] = $r['Error'].':<p>'.$r['Detail'];
				}
			}
		}
		logtrace('Attendance->park()',array($params, $id, $this->request, $this->request->Attendance_kingdom->AttendanceDate));
				
		if (!isset($this->data['AttendanceDate'])) {
			$this->data['AttendanceDate'] = isset($this->request->AttendanceDate)?$this->request->AttendanceDate:date('Y-m-d');
		}
		$this->data['AttendanceReport'] = $this->Attendance->get_kingdom_attendance_for_date($id, $this->data['AttendanceDate']);
		if ($this->request->exists('Attendance_kingdom')) {
			$this->data['Attendance_kingdom'] = $this->request->Attendance_kingdom->Request;
		}
		$this->data['Classes'] = $this->Attendance->get_classes();
		if ($this->data['Classes']['Status']['Status'] != 0) {
			$this->data['Error'] = $this->data['Classes']['Status']['Error'];
		}
	}
	
  public function behold($p) {
		$params = explode('/',$p);
		$action = $params[0];

    if (is_numeric($action)) {
      $this->session->behold_park_id = $action;
    }
    
    switch ($action) {
      case 'gaze':
          if ($_FILES['GroupSelfie']['size'] > 0 && Common::supported_mime_types($_FILES['GroupSelfie']['type'])) {
            $id = rand(0, 1000000);
            if (move_uploaded_file($_FILES['GroupSelfie']['tmp_name'], DIR_TMP . sprintf("gs_%06d", $id))) {
              $face_im = file_get_contents(DIR_TMP . sprintf("gs_%06d", $id));
              $face_imdata = base64_encode($face_im);
              $faces = $this->Attendance->lookup_by_faces([
                  'Base64Selfie' => $face_imdata
                ]);
              unlink(DIR_TMP . sprintf("gs_%06d", $id));
            }
          }
    		  $this->data['QueryImage'] = $face_imdata;
    		  $this->data['FaceLocations'] = $faces;
          $fdata = array();
          foreach ($faces as $k => $fd) {
            $fdata[] = $fd[0]['id'] > 0 ? [$fd[0]['id'], $fd[0]['Persona'], $fd[1]] : [$fd[0]['id'], "", $fd[1]];
          }
    		  $this->data['FaceData'] = $fdata;
      		$this->data['Classes'] = $this->Attendance->get_classes();
          
          logtrace("Faces of Gazes", $faces);
        break;
      case 'behold':
        if (!is_numeric($this->session->behold_park_id)) {
          die ('Park ID is not numeric'); 
        }
        foreach ($this->request->class as $mundane_id => $class_id) {
						$r = $this->Attendance->add_attendance(
								$this->session->token, 
								$this->request->attendance_date, 
								$this->session->behold_park_id, 
								null,
								$mundane_id, 
								$class_id, 
								1
							);
          if ($r['Status'] == 5) {
            header('location: /orkui/index.php?Route=Login/login'); 
          }
        }
        $id = $this->session->behold_park_id;
        unset($this->session->behold_park_id);
        header('location: /orkui/index.php?Route=Attendance/park/' . $id);
        break;
    }
		if (!isset($this->data['AttendanceDate'])) {
			$this->data['AttendanceDate'] = isset($this->request->AttendanceDate)?$this->request->AttendanceDate:date('Y-m-d');
		}
  }
  
	public function park($p) {
		$params = explode('/',$p);
		$id = $params[0];
		if (count($params) > 1)
			$action = $params[1];
		if (count($params) > 2)
			$del_id = $params[2];

		$this->data['Type'] = $type;
		$this->data['Id'] = $id;
		
		$this->data['DefaultCredits'] = 1;
        $this->data['DefaultParkName'] = $this->session->park_name;
        $this->data['DefaultParkId'] = $this->session->park_id;
        $this->data['DefaultKingdomName'] = $this->session->kingdom_name;
        $this->data['DefaultKingdomId'] = $this->session->kingdom_id;
		
		if (strlen($action) > 0) {
			$this->request->save('Attendance_park', true);
			$r = array('Status'=>0);
			if (!isset($this->session->user_id)) {
				header( 'Location: '.UIR."Login/login/Attendance/park/$id" );
			} else {
				switch ($action) {
					case 'new':
						$r = $this->Attendance->add_attendance(
								$this->session->token, 
								$this->request->Attendance_park->AttendanceDate, 
								$id, 
								null,
								$this->request->Attendance_park->MundaneId, 
								$this->request->Attendance_park->ClassId, 
								$this->request->Attendance_park->Credits
							);
                		$this->data['DefaultCredits'] = $this->request->Attendance_park->Credits;
                        $this->data['DefaultParkName'] = $this->request->Attendance_park->ParkName;
                        $this->data['DefaultParkId'] = $this->request->Attendance_park->ParkId;
                        $this->data['DefaultKingdomName'] = $this->request->Attendance_park->KingdomName;
                        $this->data['DefaultKingdomId'] = $this->request->Attendance_park->KingdomId;
						break;
					case 'delete':
						$r = $this->Attendance->delete_attendance($this->session->token, $del_id);
						break;
				}
				if ($r['Status'] == 0) {
					$this->data['AttendanceDate'] = $this->request->Attendance_park->AttendanceDate;
					$this->request->clear('Attendance_park');
				} else if($r['Status'] == 5) {
					header( 'Location: '.UIR."Login/login/Attendance/park/$id" );
				} else {
					$this->data['Error'] = $r['Error'].':<p>'.$r['Detail'];
				}
			}
		}
		logtrace('Attendance->park()',array($params, $id, $this->request, $this->request->Attendance_park->AttendanceDate));
				
		if (!isset($this->data['AttendanceDate'])) {
			$this->data['AttendanceDate'] = isset($this->request->AttendanceDate)?$this->request->AttendanceDate:date('Y-m-d');
		}
		$this->data['AttendanceReport'] = $this->Attendance->get_attendance_for_date($id, $this->data['AttendanceDate']);
		if ($this->request->exists('Attendance_park')) {
			$this->data['Attendance_park'] = $this->request->Attendance_park->Request;
		}
		$this->data['Classes'] = $this->Attendance->get_classes();
		if ($this->data['Classes']['Status']['Status'] != 0) {
			$this->data['Error'] = $this->data['Classes']['Status']['Error'];
		}
		$this->data['RecentAttendees'] = $this->Attendance->get_recent_attendees($id);
	}

	public function event($p) {
		$params = explode('/',$p);
		$event_id = $params[0];
		$detail_id = $params[1];
		
		if (count($params) > 2)
			$action = $params[2];
		if (count($params) > 3)
			$del_id = $params[3];

		$this->data['DetailId'] = $detail_id;
		$this->data['EventId'] = $event_id;
		$this->data['Id'] = $event_id; // Legacy reference ... christ

        $this->data['DefaultAttendanceCredits'] = 1;
        $this->data['DefaultParkName'] = $this->session->park_name;
        $this->data['DefaultParkId'] = $this->session->park_id;
        $this->data['DefaultKingdomName'] = $this->session->kingdom_name;
        $this->data['DefaultKingdomId'] = $this->session->kingdom_id;
                    
        if (strlen($action) > 0) {
			$this->request->save('Attendance_event', true);
			$r = array('Status'=>0);
			if (!isset($this->session->user_id)) {
				//header( 'Location: '.UIR."Login/login/Attendance/event/$event_id/$detail_id" );
			} else {
				switch ($action) {
					case 'new':
            $detail = $this->Attendance->get_eventdetail_info($detail_id);

            $r = $this->Attendance->add_attendance(
								$this->session->token, 
								$this->request->Attendance_event->AttendanceDate, 
								valid_id($detail['AtParkId'])?$detail['AtParkId']:null, 
								$detail_id,
								$this->request->Attendance_event->MundaneId, 
								$this->request->Attendance_event->ClassId, 
								$this->request->Attendance_event->Credits
							);
						break;
					case 'delete':
						$r = $this->Attendance->delete_attendance($this->session->token, $del_id);
						break;
				}
				if ($r['Status'] == 0) {
					$this->data['AttendanceDate'] = $this->request->Attendance_event->AttendanceDate;
                    $this->data['DefaultParkName'] = $this->request->Attendance_event->ParkName;
                    $this->data['DefaultParkId'] = $this->request->Attendance_event->ParkId;
                    $this->data['DefaultKingdomName'] = $this->request->Attendance_event->KingdomName;
                    $this->data['DefaultKingdomId'] = $this->request->Attendance_event->KingdomId;
                    $this->data['DefaultAttendanceCredits'] = $this->request->Attendance_event->Credits;
					$this->request->clear('Attendance_event');
				} else if($r['Status'] == 5) {
					//header( 'Location: '.UIR."Login/login/Attendance/event/$event_id/$detail_id" );
				} else {
					$this->data['Error'] = $r['Error'].':<p>'.$r['Detail'];
				}
			}
		}
			
		$this->data['EventDetailInfo'] = $this->Attendance->get_eventdetail_info($detail_id);
		$this->data['EventInfo'] = $this->Attendance->get_event_info($event_id);
		$this->data['page_title'] = $this->data['EventInfo'][0]['Name'];
		logtrace('Attendance->event()',array($params, $this->request));
		$this->data['AttendanceReport'] = $this->Attendance->get_attendance_for_event($event_id, $detail_id);
		if ($this->request->exists('Attendance_event')) {
			$this->data['Attendance_event'] = $this->request->Attendance_event->Request;
		}
    $classes = $this->Attendance->get_classes();
		$this->data['Classes'] = $classes['Classes'];
		if ($this->data['Classes']['Status']['Status'] != 0) {
			$this->data['Error'] = $this->data['Classes']['Status']['Error'];
		}
	}	
	
}



?>