<?php

class Award  extends Ork3 {

	public function __construct() {
		parent::__construct();
		$this->award = new yapo($this->db, DB_PREFIX . 'award');
	}

    public function LookupAward($request) {
        if (valid_id($request['KingdomId']) && valid_id($request['AwardId'])) {
        	$kingdomaward = new yapo($this->db, DB_PREFIX . 'kingdomaward');
            $kingdomaward->clear();
            $kingdomaward->kingdom_id = $request['KingdomId'];
            $kingdomaward->award_id = $request['AwardId'];
            $kingdomaward->find();
			return array($kingdomaward->kingdomaward_id, $kingdomaward->award_id);
        }
    }

    public function LookupKingdomAward($request) {
        if (valid_id($request['KingdomAwardId'])) {
            $kingdomaward = new yapo($this->db, DB_PREFIX . 'kingdomaward');
            $kingdomaward->clear();
            $kingdomaward->kingdomaward_id = $request['KingdomAwardId'];
            $kingdomaward->find();
            return array($kingdomaward->kingdom_id, $kingdomaward->award_id);
        }
    }

	public function GetAwardList($request) {
		if ($request['IsLadder'] == 'Ladder') {
			$ladder_clause = " and ka.is_ladder = 1";
		} else if ($request['IsLadder'] == 'NonLadder') {
			$ladder_clause = " and ka.is_ladder = 0";
		}
		if ($request['IsTitle'] == 'Title') {
			$ladder_clause = " and is_title = 1";
		} else if ($request['IsTitle'] == 'NonTitle') {
			$ladder_clause = " and is_title = 0";
		}
    if (isset($request['OfficerRole']) && $request['OfficerRole'] == 'Awards') {
      $officer_role_clause = " and officer_role = 'none'"; 
    } else if (isset($request['OfficerRole']) && $request['OfficerRole'] == 'Officers') {
      $officer_role_clause = " and officer_role != 'none'"; 
    } 
		$sql = "select award_id, name, a.award_id, a.is_ladder, is_title, title_class, a.officer_role
					from " . DB_PREFIX . "award a 
					where 1
						$ladder_clause
						$title_clause
            $officer_role_clause
					order by is_ladder, a.is_title, a.title_class desc, a.name";
		$r = $this->db->query($sql);

		$response = array();
    $response['Awards'] = array();
		if ($r !== false && $r->size() > 0) {
			while ($r->next()) {
				$response['Awards'][] = array(
					'KingdomAwardId' => $r->award_id,
					'KingdomAwardName' => $r->name,
					'ReignLimit' => 0,
					'MonthLimit' => 0,
					'AwardName' => $r->name,
					'AwardId' => $r->award_id,
					'IsLadder' => $r->is_ladder,
					'IsTitle' => $r->is_title,
					'TitleClass' => $r->title_class,
          			'OfficerRole' => $r->officer_role
				);
			}
			$response['Status'] = Success();
		} else {
			$response['Status'] = InvalidParameter(NULL, 'Problem processing your request.');
		}
		return $response;
	}

	public function CreateAward($request) {
		if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) > 0
				&& Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_ADMIN, 0, AUTH_CREATE)) {
			$this->log->Write('Award', $mundane_id, LOG_ADD, $request);
			$this->award->clear();
			$this->award->name = $request['Name'];
			$this->award->is_ladder = $request['IsLadder'];
			$this->award->is_title = $request['IsTitle'];
			$this->award->title_class = $request['TitleClass'];
			$this->award->peerage = $request['Peerage'];
			$this->award->officer_role = $request['OfficerRole'];
			$this->award->save();
		} else {
			return NoAuthorization();
		}
	}

	public function EditAward($request) {
		if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) > 0
				&& Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_ADMIN, 0, AUTH_EDIT)) {
			$this->log->Write('Award', $mundane_id, LOG_EDIT, $request);
			$this->award->clear();
			$this->award->award_id = $request['AwardId'];
			if ($this->kingdomaward->find()) {
				$this->award->name = $request['Name'];
				$this->award->is_ladder = $request['IsLadder'];
				$this->award->is_title = $request['IsTitle'];
				$this->award->title_class = $request['TitleClass'];
				$this->award->peerage = $request['Peerage'];
  				$this->award->officer_role = $request['OfficerRole'];
				$this->award->award->save();

			} else {
				return InvalidParameter();
			}
		} else {
			return NoAuthorization();
		}
	}

	public function RemoveAward($request) {
		if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) > 0
				&& Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_ADMIN, 0, AUTH_EDIT)) {
			$this->log->Write('Award', $mundane_id, LOG_REMOVE, $request);
			$this->award->award_id = $request['AwardId'];
			if ($this->award->find()) {
				$this->award->delete();
			}
			return Success();
		}
		return NoAuthorization();
	}

	public function create_system_awards() {

		$this->create_award('Master Rose', 0, 1, 10, 'Master');
		$this->create_award('Master Smith', 0, 1, 10, 'Master');
		$this->create_award('Master Lion', 0, 1, 10, 'Master');
		$this->create_award('Master Owl', 0, 1, 10, 'Master');
		$this->create_award('Master Dragon', 0, 1, 10, 'Master');
		$this->create_award('Master Garber', 0, 1, 10, 'Master');
		$this->create_award('Master Jovius', 0, 1, 10);
		$this->create_award('Master Zodiac', 0, 1, 10);
		$this->create_award('Master Mask', 0, 1, 10);
		$this->create_award('Master Hydra', 0, 1, 10);
		$this->create_award('Master Griffin', 0, 1, 10);
		$this->create_award('Warlord', 0, 1, 10, 'Master');

		$this->create_award("Lord's Page", 0, 1, 5, 'Lords-Page');
		$this->create_award('Man-at-Arms', 0, 1, 5, 'Man-at-Arms');
		$this->create_award('Page', 0, 1, 5, 'Page');
		$this->create_award('Squire', 0, 1, 15, 'Squire');

		$this->create_award('Knight of the Flame', 0, 1, 20, 'Knight');
		$this->create_award('Knight of the Crown', 0, 1, 20, 'Knight');
		$this->create_award('Knight of the Serpent', 0, 1, 20, 'Knight');
		$this->create_award('Knight of the Sword', 0, 1, 20, 'Knight');

		$this->create_award('Order of the Rose', 1, 0, 0);
		$this->create_award('Order of the Smith',  1, 0, 0);
		$this->create_award('Order of the Lion',  1, 0, 0);
		$this->create_award('Order of the Owl',  1, 0, 0);
		$this->create_award('Order of the Dragon',  1, 0, 0);
		$this->create_award('Order of the Garber',  1, 0, 0);
		$this->create_award('Order of the Warrior',  1, 0, 0);
		$this->create_award('Order of the Jovius',  1, 0, 0);
		$this->create_award('Order of the Mask',  1, 0, 0);
		$this->create_award('Order of the Zodiac',  1, 0, 0);
		$this->create_award('Order of the Walker in the Middle',  1, 0, 0);
		$this->create_award('Order of the Hydra',  1, 0, 0);
		$this->create_award('Order of the Griffin',  1, 0, 0);
		$this->create_award('Order of the Flame',  1, 0, 0);

		$this->create_award('Defender', 0, 1, 10);
		$this->create_award('Weaponmaster', 0, 1, 10);

		$this->create_award('Master Anti-Paladin', 0, 1, 10);
		$this->create_award('Master Archer', 0, 1, 10);
		$this->create_award('Master Assassin', 0, 1, 10);
		$this->create_award('Master Barbarian', 0, 1, 10);
		$this->create_award('Master Bard', 0, 1, 10);
		$this->create_award('Master Druid', 0, 1, 10);
		$this->create_award('Master Healer', 0, 1, 10);
		$this->create_award('Master Monk', 0, 1, 10);
		$this->create_award('Master Monster', 0, 1, 10);
		$this->create_award('Master Paladin', 0, 1, 10);
		$this->create_award('Master Peasant', 0, 1, 10);
		$this->create_award('Master Raider', 0, 1, 10);
		$this->create_award('Master Scout', 0, 1, 10);
		$this->create_award('Master Warrior', 0, 1, 10);
		$this->create_award('Master Wizard', 0, 1, 10);

		$this->create_award('Lord', 0, 1, 30);
		$this->create_award('Lady', 0, 1, 30);
		$this->create_award('Baronet', 0, 1, 40);
		$this->create_award('Baronetess', 0, 1, 40);
		$this->create_award('Baron', 0, 1, 50);
		$this->create_award('Baroness', 0, 1, 50);
		$this->create_award('Viscount', 0, 1, 60);
		$this->create_award('Viscountess', 0, 1, 60);
		$this->create_award('Count', 0, 1, 70);
		$this->create_award('Countess', 0, 1, 70);
		$this->create_award('Marquis', 0, 1, 80);
		$this->create_award('Marquess', 0, 1, 80);
		$this->create_award('Duke', 0, 1, 90);
		$this->create_award('Duchess', 0, 1, 90);
		$this->create_award('Archduke', 0, 1, 100);
		$this->create_award('Archduchess', 0, 1, 100);
		$this->create_award('Grand Duke', 0, 1, 110);
		$this->create_award('Grand Duchess', 0, 1, 110);

		$this->create_award('Sheriff',  0, 0, 0);
		$this->create_award('Provincial Baron',  0, 0, 0);
		$this->create_award('Provincial Baroness',  0, 0, 0);
		$this->create_award('Provincial Duke',  0, 0, 0);
		$this->create_award('Provincial Duchess',  0, 0, 0);
		$this->create_award('Provincial Grand Duke',  0, 0, 0);
		$this->create_award('Provincial Grand Duchess',  0, 0, 0);

		$this->create_award('Shire Regent',  0, 0, 0);
		$this->create_award('Baronial Regent',  0, 0, 0);
		$this->create_award('Ducal Regent',  0, 0, 0);
		$this->create_award('Grand Ducal Regent',  0, 0, 0);

		$this->create_award('Shire Clerk',  0, 0, 0);
		$this->create_award('Baronial Seneschal',  0, 0, 0);
		$this->create_award('Ducal Chancellor',  0, 0, 0);
		$this->create_award('Grand Ducal General Minister',  0, 0, 0);

		$this->create_award('Provincial Champion',  0, 0, 0);
		$this->create_award('Baronial Champion',  0, 0, 0);
		$this->create_award('Ducal Defender',  0, 0, 0);
		$this->create_award('Grand Ducal Defender',  0, 0, 0);

		$this->create_award('Kingdom Champion',  0, 0, 0);
		$this->create_award('Kingdom Regent',  0, 0, 0);
		$this->create_award('Kingdom Prime Minister',  0, 0, 0);
		$this->create_award('Kingdom Monarch',  0, 0, 0);

		$this->create_award('Director of the Board',  0, 0, 0);

		$this->create_award('Custom Award',  0, 0, 0);
	}

	public function create_award($name, $is_ladder, $is_title, $title_class, $peerage = 'None', $officer_role = 'none') {
		$this->award->clear();
		$this->award->name = $name;
		$this->award->is_ladder = $is_ladder;
		$this->award->is_title = $is_title;
		$this->award->title_class = $title_class;
		$this->award->peerage = $peerage;
		$this->award->officer_role = $officer_role;
		$this->award->save();
	}
}

?>
