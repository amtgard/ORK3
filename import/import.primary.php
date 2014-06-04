<?php

/*******************************************************************************
 * 
 * Import Routine
 *  Stages
 *      Import Kingdoms
 *      Import Chapters
 *      Import Players
 * 
 ******************************************************************************/

include_once('../config.php');

class ImportOrk2 {

    var $adminuser = 'adminimport';
    var $adminpassword = 'e01e44f3';
    
    var $ORK2;
    
    function __construct($DB) {
        $this->ORK2 = new yapo_mysql(DB_HOSTNAME, 'orkrecords_ork2test', DB_USERNAME, DB_PASSWORD);
        $this->ORK3 = new yapo_mysql(DB_HOSTNAME, 'orkrecords_dbimport', DB_USERNAME, DB_PASSWORD);
        $this->DB = $DB;
        
        $this->Attendance = new APIModel('Attendance');
        $this->Award = new APIModel('Award');
        $this->Authorization = new APIModel('Authorization');
        $this->Heraldry = new APIModel('Heraldry');
        
        $this->Login();    
    }
    
    function Login() {
        $this->Token = $this->Authorization->Authorize(array(
            'UserName' => $this->adminuser,
            'Password' => $this->adminpassword
        ));
        
        $this->token = $this->Token['Token'];
        return $this->token;
    }
    
    function Reset() {
    	$this->ORK2->query('truncate table dbimport_status');
        $this->ORK2->query('truncate table dbimport_cache');
    }

    function ImportNotes() {
    /***************************************************************************
     * Notes
     * -    titles
     * -    positions
     * -    misc
     * -    knights blobs
     * -    knightaccomplish
     * -    groups
     **************************************************************************/
        if ($this->ImportTitles(5000) == 0) {
            if ($this->ImportPositions(15000) == 0) {
                if ($this->ImportMisc(10000) == 0) {
                    if ($this->ImportKnightAccomplish(1000) == 0) {
                        if ($this->ImportGroups(5000) == 0) {
                            return 0;                        
                        }
                    }
                }
            }
        }   
        return 1;
    }

    function ImportGroups($number = 5) {
        set_time_limit(600);  
        
        echo "<h2>Import Groups</h2>";
        
        list($groupID, $detail) = $this->LastStatus('groups');

        $sql = "SELECT *
                    FROM  `groups` 
                    WHERE playerID > 0 and groupID > $groupID order by groupID limit $number";
        
        $groups = $this->ORK2->query($sql);
        
        $Player = new APIModel('Player');
        
        echo $sql . "<p>";
        
        $imported = 0;
        
        if ($groups->size() > 0) do {
            list($tmp, $mundane_id) = $this->CacheMap('players', $groups->playerID);
            if ($mundane_id == 0) continue;
            $imported++;
            if ($imported % 10000)
                set_time_limit(600);
            $group = $Player->AddNote(array(
                'Token' => $this->token,
                'MundaneId' => $mundane_id,
                'Note' => $groups->groupname
            ));
            if ($date['Status'] == ServiceErrorIds::Success) {
                if ($imported % 500 == 0)
                    echo "G ({$groups->playerID}, {$mundane_id}): ";
                $this->RecordTransfer('groups', 'Group-Note', $groups->groupID, $group['Detail'], array($detail));
                if ($imported % 500 == 0)
                    echo "{$groups->groupID}, $group[Detail]; ";
            }
        } while ($groups->next());
        echo "<h3>Import Groups Complete ($imported)</h3>";
        return $imported;
    }

    function ImportKnightAccomplish($number = 5) {
        set_time_limit(600);  
        
        echo "<h2>Import Knight Accomplishments</h2>";
        
        list($accomplishID, $detail) = $this->LastStatus('knightaccomplish');

        $sql = "SELECT knightaccomplish . * , knights.orkID
                    FROM  `knightaccomplish` 
                        LEFT JOIN knights ON knights.knightID = knightaccomplish.knightID
                    WHERE orkID > 0 and accomplishID > $accomplishID order by accomplishID limit $number";
        
        $accomplishes = $this->ORK2->query($sql);
        
        $Player = new APIModel('Player');
        
        echo $sql . "<p>";
        
        $imported = 0;
        
        if ($accomplishes->size() > 0) do {
            list($tmp, $mundane_id) = $this->CacheMap('players', $accomplishes->orkID);
            if ($mundane_id == 0) continue;
            $imported++;
            if ($imported % 10000)
                set_time_limit(600);
            $accomplish = $Player->AddNote(array(
                'Token' => $this->token,
                'MundaneId' => $mundane_id,
                'Note' => $accomplishes->accomplish
            ));
            if ($date['Status'] == ServiceErrorIds::Success) {
                if ($imported % 500 == 0)
                    echo "A ({$accomplishes->orkID}, {$mundane_id}): ";
                $this->RecordTransfer('knightaccomplish', 'Accomplish-Note', $accomplishes->accomplishID, $accomplish['Detail'], array($detail));
                if ($imported % 500 == 0)
                    echo "{$accomplishes->accomplishID}, $accomplish[Detail]; ";
            }
        } while ($accomplishes->next());
        echo "<h3>Import Knight Accomplishments Complete ($imported)</h3>";
        return $imported;
    }

    function ImportMisc($number = 5) {
        set_time_limit(600);  
        
        echo "<h2>Import Misc</h2>";
        
        list($miscID, $detail) = $this->LastStatus('misc');

        $sql = "SELECT * 
                    FROM  `misc` 
                WHERE playerID > 0 and miscID > $miscID order by miscID limit $number";
        
        $misces = $this->ORK2->query($sql);
        
        $Player = new APIModel('Player');
        
        echo $sql . "<p>";
        
        $imported = 0;
        
        if ($misces->size() > 0) do {
            list($tmp, $mundane_id) = $this->CacheMap('players', $misces->playerID);
            if ($mundane_id == 0) continue;
            $imported++;
            if ($imported % 10000)
                set_time_limit(600);
            $misc = $Player->AddNote(array(
                'Token' => $this->token,
                'MundaneId' => $mundane_id,
                'Description' => '',
                'Note' => $misces->misc,
                'Date' => $misces->miscTS
            ));
            if ($date['Status'] == ServiceErrorIds::Success) {
                if ($imported % 500 == 0)
                    echo "M ({$misces->playerID}, {$mundane_id}): ";
                $this->RecordTransfer('misc', 'Misc-Note', $misces->miscID, $misc['Detail'], array($detail));
                if ($imported % 500 == 0)
                    echo "{$misces->miscID}, $misc[Detail]; ";
            }
        } while ($misces->next());
        echo "<h3>Import Misc Complete ($imported)</h3>";
        return $imported;
    }

    function ImportPositions($number = 10) {
        set_time_limit(600);  
        
        echo "<h2>Import Positions</h2>";
        
        list($positionID, $detail) = $this->LastStatus('positions');

        $sql = "SELECT * 
                    FROM  `positions` 
                WHERE playerID > 0 and positionID > $positionID order by positionID limit $number";
        
        $positions = $this->ORK2->query($sql);
        
        $Player = new APIModel('Player');
        
        echo $sql . "<p>";
        
        $imported = 0;
        
        if ($positions->size() > 0) do {
            list($tmp, $mundane_id) = $this->CacheMap('players', $positions->playerID);
            if ($mundane_id == 0) continue;
            $imported++;
            if ($imported % 10000)
                set_time_limit(600);
            $position = $Player->AddNote(array(
                'Token' => $this->token,
                'MundaneId' => $mundane_id,
                'Description' => '',
                'Note' => $positions->position,
                'Date' => $positions->startdate,
                'DateComplete' => $positions->enddate
            ));
            if ($date['Status'] == ServiceErrorIds::Success) {
                if ($imported % 500 == 0)
                    echo "N ({$positions->playerID}, {$mundane_id}): ";
                $this->RecordTransfer('positions', 'Positions-Note', $positions->positionID, $position['Detail'], array($detail));
                if ($imported % 500 == 0)
                    echo "{$positions->positionID}, $position[Detail]; ";
            }
        } while ($positions->next());
        echo "<h3>Import Positions Complete ($imported)</h3>";
        return $imported;
    }

    function ImportTitles($number = 5) {
        set_time_limit(600);  
        
        echo "<h2>Import Titles</h2>";
        
        list($titleID, $detail) = $this->LastStatus('titles');

        $sql = "SELECT * 
                    FROM  `titles` 
                        LEFT JOIN tbltitletypes ON titles.titletypeID = tbltitletypes.titletypeID 
                WHERE playerID > 0 and titleID > $titleID order by titleID limit $number";
        
        $titles = $this->ORK2->query($sql);
        
        $Player = new APIModel('Player');
        
        echo $sql . "<p>";
        
        $imported = 0;
        
        if ($titles->size() > 0) do {
            list($tmp, $mundane_id) = $this->CacheMap('players', $titles->playerID);
            if ($mundane_id == 0) continue;
            $imported++;
            if ($imported % 10000)
                set_time_limit(600);
            if ($titles->ork3_award_id > 0) {
                $award = $Player->AddAward(array(
                    'Token' => $this->token,
                    'RecipientId' => $mundane_id,
                    'AwardId' => $titles->ork3_award_id,
                    'Note' => $titles->given,
                    'Date' => $titles->date,
                    'CustomName' => $titles->title,
                    'Rank' => 0
                ));
                if ($date['Status'] == ServiceErrorIds::Success) {
                    echo "A ({$titles->playerID}, {$mundane_id}): ";
                    $this->RecordTransfer('titles', 'Titles-Awards', $titles->titleID, $award['Detail'], array($detail));
                    echo "{$titles->titleID}, $award[Detail]; ";
                }
            } else {
                $title = $Player->AddNote(array(
                    'Token' => $this->token,
                    'MundaneId' => $mundane_id,
                    'Description' => $titles->given,
                    'Note' => $titles->title,
                    'Date' => $titles->date
                ));
                if ($date['Status'] == ServiceErrorIds::Success) {
                    if ($imported % 500 == 0)
                        echo "N ({$titles->playerID}, {$mundane_id}): ";
                    $this->RecordTransfer('titles', 'Titles-Note', $titles->titleID, $title['Detail'], array($detail));
                    if ($imported % 500 == 0)
                        echo "{$titles->titleID}, $title[Detail]; ";
                }
            }
        } while ($titles->next());
        echo "<h3>Import Titles Complete ($imported)</h3>";
        return $imported;
    }

    function ImportOtherAwards() {
        /*
         * -    companyawards
         * -    knightsbelts
         * -    squires
         * -    masters
         * -    companyawards
        */
        if ($this->ImportKnights(1000) == 0) {
            if ($this->ImportSquires(1000) == 0) {
                if ($this->ImportMasters(2500) == 0) {
                    if ($this->ImportCompanyAwards(300) == 0) {
                        return 0;
                    }
                }
            }
        }
        return 1;
    }

    function ImportCompanyAwards($number = 5) {
        set_time_limit(600);  
        
        echo "<h2>Import Company Awards</h2>";
        
        list($companyawardID, $detail) = $this->LastStatus('companyawards');

        $sql = "SELECT * FROM 
                    `companyawards` 
                WHERE companyawardID > $companyawardID order by companyawardID limit $number";
        
        $awards = $this->ORK2->query($sql);
        
        $Unit = new APIModel('Unit');
        
        echo $sql . "<p>";
        
        $imported = 0;
        
        if ($awards->size() > 0) do {
            list($tmp, $unit_id) = $this->CacheMap('companies', $awards->companyID);
            if ($unit_id == 0) continue;
            $imported++;
            if ($imported % 10000)
                set_time_limit(600);
            $award = $Unit->AddAward(array(
                'Token' => $this->token,
                'RecipientId' => $unit_id,
                'AwardId' => 0,
                'Note' => $awards->given . ' on ' . $awards->awarddate,
                'Date' => date('Y-m-d', strtotime(str_replace(',', '', $awards->awarddate))),
                'CustomName' => $awards->awardtype,
                'Rank' => 0
            ));
            if ($date['Status'] == ServiceErrorIds::Success) {
                $this->RecordTransfer('companyawards', 'Company-Awards', $awards->companyawardID, $award['Detail'], array($detail));
                if ($imported % 500 == 0)
                    echo "CA: {$awards->companyawardID}, $award[Detail]; ";
            }
        } while ($awards->next());
        echo "<h3>Import Company Awards Complete ($imported)</h3>";
        return $imported;
    }

    function ImportMasters($number = 5) {
        set_time_limit(600);  
        
        echo "<h2>Import Masters</h2>";
        
        list($MID, $detail) = $this->LastStatus('masters');

        $sql = "SELECT * FROM 
                    `masters` 
                        left join mastertypes on masters.masterID = mastertypes.masterID 
                WHERE playerID > 0 and MID > $MID order by MID limit $number";
        
        $masters = $this->ORK2->query($sql);
        
        $Player = new APIModel('Player');
        
        echo $sql . "<p>";
        
        $imported = 0;
        
        if ($masters->size() > 0) do {
            list($tmp, $mundane_id) = $this->CacheMap('players', $masters->playerID);
            if ($mundane_id == 0) continue;
            $imported++;
            if ($imported % 10000)
                set_time_limit(600);
            $award = $Player->AddAward(array(
                'Token' => $this->token,
                'RecipientId' => $mundane_id,
                'AwardId' => $masters->ork3_award_id,
                'Note' => $masters->given,
                'Date' => $masters->date,
                'CustomName' => $masters->master,
                'Rank' => 0
            ));
            if ($date['Status'] == ServiceErrorIds::Success) {
                $this->RecordTransfer('masters', 'Master-Awards', $masters->MID, $award['Detail'], array($detail));
                if ($imported % 500 == 0)
                    echo "A: {$masters->MID}, $award[Detail]; ";
            }
        } while ($masters->next());
        echo "<h3>Import Masters Complete ($imported)</h3>";
        return $imported;
    }

    function ImportSquires($number = 5) {
        set_time_limit(600);  
        
        echo "<h2>Import Squires</h2>";
        
        list($squireID, $detail) = $this->LastStatus('knightsquires');

        $sql = "SELECT squireID, knightsquires.name, knightsquires.orkID as squire_orkID, knights.orkID as knight_orkID 
                    FROM knightsquires
                        left join knights on knightsquires.knightID = knights.knightID 
                WHERE knights.orkID > 0 and knightsquires.orkID > 0 and squireID > $squireID order by squireID limit $number";
        
        $squires = $this->ORK2->query($sql);
        
        $Player = new APIModel('Player');
        
        echo $sql . "<p>";
        
        $imported = 0;
        
        if ($squires->size() > 0) do {
            list($tmp, $squire_id) = $this->CacheMap('players', $squires->squire_orkID);
            if ($squire_id == 0) continue;
            list($tmp, $knight_id) = $this->CacheMap('players', $squires->knight_orkID);
            if ($knight_id == 0) continue;
            $imported++;
            if ($imported % 10000)
                set_time_limit(600);
            $redbelt = $Player->AddAward(array(
                'Token' => $this->token,
                'RecipientId' => $squire_id,
                'GivenById' => $knight_id,
                'AwardId' => 16,
                'Rank' => 0
            ));
            if ($date['Status'] == ServiceErrorIds::Success) {
                $this->RecordTransfer('knightsquires', 'Squire-Awards', $squires->squireID, $redbelt['Detail'], array($detail));
                if ($imported % 500 == 0)
                    echo "A: {$squires->squireID}, $redbelt[Detail]; ";
            }
        } while ($squires->next());
        echo "<h3>Import Squires Complete ($imported)</h3>";
        return $imported;
    }

    function ImportKnights($number = 5) {
        set_time_limit(600);  
        
        echo "<h2>Import Knights</h2>";
        
        list($beltID, $detail) = $this->LastStatus('knightbelts');

        $sql = "SELECT * FROM 
                    `knightbelts` 
                        left join knights on knightbelts.knightID = knights.knightID 
                WHERE orkID > 0 and beltID > $beltID order by beltID limit $number";
        
        $belts = $this->ORK2->query($sql);
        
        $Player = new APIModel('Player');
        
        echo $sql . "<p>";
        
        $imported = 0;
        
        if ($belts->size() > 0) do {
            list($tmp, $mundane_id) = $this->CacheMap('players', $belts->orkID);
            if ($mundane_id == 0) continue;
            list($tmp, $kingdom_id) = $this->CacheMap('kingdoms', $belts->kingdomID);
            $imported++;
            if ($imported % 10000)
                set_time_limit(600);
            switch ($belts->type) {
                case 'Crown': $award_id = 18; break;
                case 'Flame': $award_id = 17; break;
                case 'Serpent': $award_id = 19; break;
                case 'Sword': $award_id = 20; break;
            }
            $award = $Player->AddAward(array(
                'Token' => $this->token,
                'RecipientId' => $mundane_id,
                'AwardId' => $award_id,
                'Note' => $belts->whoby . ' at ' . $belts->location,
                'Date' => $belts->dateknighted,
                'CustomName' => 'Knight of the ' . $belts->type,
                'Rank' => 0
            ));
            if ($date['Status'] == ServiceErrorIds::Success) {
                $this->RecordTransfer('knightbelts', 'Knight-Awards', $belts->beltID, $award['Detail'], array($detail));
                if ($imported % 500 == 0)
                    echo "A: {$belts->beltID}, $award[Detail]; ";
            }
        } while ($belts->next());
        echo "<h3>Import Knights Complete ($imported)</h3>";
        return $imported;
    }

    function ImportAwards($number = 5) {
        set_time_limit(600);  
        
        echo "<h2>Import Awards</h2>";
        
        list($AID, $detail) = $this->LastStatus('awards');

        $sql = "SELECT *
                    FROM awards
                        left join awardtypes on awards.awardID = awardtypes.awardID
                    WHERE AID > $AID and playerID > 0 order by AID limit $number";
        
        $awards = $this->ORK2->query($sql);
        
        $Player = new APIModel('Player');
        
        echo $sql . "<p>";
        
        $imported = 0;
        
        if ($awards->size() > 0) do {
            list($tmp, $mundane_id) = $this->CacheMap('players', $awards->playerID);
            if ($mundane_id == 0) continue;
            $imported++;
            if ($imported % 10000)
                set_time_limit(600);
            $award = $Player->AddAward(array(
                'Token' => $this->token,
                'RecipientId' => $mundane_id,
                'AwardId' => valid_id($awards->ork3_award_id)?$awards->ork3_award_id:94,
                'Note' => $awards->given . '; ' . $awards->memo,
                'Date' => $awards->date,
                'CustomName' => $awards->award,
                'Rank' => 0
            ));
            if ($date['Status'] == ServiceErrorIds::Success) {
                $this->RecordTransfer('awards', 'Player-Awards', $awards->AID, $award['Detail'], array($detail));
                if ($imported % 500 == 0)
                    echo "A: {$awards->AID}, $award[Detail]; ";
            }
        } while ($awards->next());
        echo "<h3>Import Awards Complete ($imported)</h3>";
        return $imported;
    }



    function ImportVisitorCredits($number = 5) {
        set_time_limit(600);  
        
        echo "<h2>Import Visitor Credits</h2>";
        
        list($viscreditID, $detail) = $this->LastStatus('viscredits');

        $sql = "SELECT credits.*, classes.*
                    FROM `viscredits` credits
                        left join classtypes classes on classes.classID = credits.classID 
                    WHERE length(credits.player) > 0 and classes.classID > 0 and viscreditID > $viscreditID and chapterID > 0 order by viscreditID limit $number";
        
        $credits = $this->ORK2->query($sql);
        
        $Attendance = new APIModel('Attendance');
        
        echo $sql . "<p>";
        
        $imported = 0;
        
        if ($credits->size() > 0) do {
            list($tmp, $chapter_id) = $this->CacheMap('chapters', $credits->chapterID);
            if ($chapter_id == 0)
                list($tmp, $chapter_id) = $this->CacheMap('principality-chapters', $credits->chapterID);
            if ($chapter_id == 0) continue;
            $imported++;
            if ($imported % 10000)
                set_time_limit(600);
            $date = $Attendance->AddAttendance(array(
                'Token' => $this->token,
                'MundaneId' => 0,
                'ParkId' => $chapter_id,
                'Persona' => $credits->player,
                'ClassId' => $credits->IsStandard,
                'Date' => $credits->date,
                'Credits' => $credits->value,
                'Note' => $credits->playerchap,
                'Flavor' => $credits->class
            ));
            if ($date['Status'] == ServiceErrorIds::Success) {
                $this->RecordTransfer('viscredits', 'Attendance', $credits->viscreditID, $date['Detail'], array($detail));
                echo "VA: {$credits->viscreditID}, $date[Detail]; ";
            }
        } while ($credits->next());
        echo "<h3>Import Visitor Credits Complete ($imported)</h3>";
        return $imported;
    }

    function ImportCredits($number = 5) {
        set_time_limit(600);  
        
        echo "<h2>Import Credits</h2>";
        
        list($creditID, $detail) = $this->LastStatus('credits');

        $sql = "SELECT creditID, creditchapterID, playerID, IsStandard, value, date, class
                    FROM `credits`
                        left join classtypes classes on classes.classID = credits.classID 
                    WHERE credits.playerID > 0 and classes.classID > 0 and creditID > $creditID order by creditID limit $number";
        
        $credits = $this->ORK2->query($sql);
        
        $Attendance = new APIModel('Attendance');
        
        echo $sql . "<p>";
        
        $imported = 0;
        
        echo "credits: " . $credits->size() . "<p>";
        
        if ($credits->size() > 0) do {
            if (!valid_id($credits->creditchapterID)) {
                //echo "no xID; ";
                continue;
            }
            list($tmp, $chapter_id) = $this->CacheMap('chapters', $credits->creditchapterID);
            if ($chapter_id == 0)
                list($tmp, $chapter_id) = $this->CacheMap('principality-chapters', $credits->creditchapterID);
            if ($chapter_id == 0) {
                //echo "no cID; ";
                continue;
            }
            list($tmp, $mundane_id) = $this->CacheMap('players', $credits->playerID);
            if ($mundane_id == 0) {
                //echo "no mID ({$credits->playerID}, $mundane_id) ; ";
                continue;
            }
            $imported++;
            if ($imported % 5000 == 0)
                set_time_limit(600);
            $date = $Attendance->AddAttendance(array(
            	'Token' => $this->token,
                'MundaneId' => $mundane_id,
                'ParkId' => $chapter_id,
                'Persona' => '',
                'ClassId' => $credits->IsStandard,
                'Date' => $credits->date,
                'Credits' => $credits->value,
                'Note' => '',
                'Flavor' => $credits->class
            ));
            if ($date['Status'] == ServiceErrorIds::Success) {
                $this->RecordTransfer('credits', 'Attendance', $credits->creditID, $date['Detail'], array($detail));
                if ($imported % 500 == 0)
                    echo "A: {$credits->creditID}, $date[Detail] (record $imported); ";
            }
        } while ($credits->next());
        echo "<h3>Import Credits Complete ($imported)</h3>";
        return $imported;
    }

    function ImportCompanyMembers($number = 5) {
        set_time_limit(120);  
        
        echo "<h2>Import Company Members</h2>";
        
        list($memberID, $detail) = $this->LastStatus('companymembers');

        $sql = "SELECT cm.*, ct.title
                    FROM `companymembers` cm
                        left join companytitles ct on cm.companytitleID = ct.companytitleID 
                    WHERE cm.orkID > 0 and cm.memberID > $memberID order by cm.memberID limit $number";
        
        $members = $this->ORK2->query($sql);
        
        $Company = new APIModel('Unit');
        
        echo $sql . "<p>";
        
        $imported = 0;
        
        if ($members->size() > 0) do {
            list($tmp, $company_id) = $this->CacheMap('companies', $members->companyID);
            echo "company_id({$members->companyID}): $company_id; ";
            if ($company_id > 0)
                list($tmp, $mundane_id) = $this->CacheMap('players', $members->orkID);
            else
                continue;
            echo "mundane_id({$members->orkID}): $mundane_id. ";
            if ($mundane_id == 0) continue;
            set_time_limit(20);  
            echo "Add Company Member {$members->name}; ";
            $imported++;
            $member = $Company->AddMember(array(
        		'Token' => $this->token,
                'UnitId' => $company_id,
                'MundaneId' => $mundane_id,
                'Role' => 'member',
                'Title' => $members->title,
                'Active' => 'Active',
                'Force' => 1
            ));
            if ($member['Status'] == ServiceErrorIds::Success)
                $this->RecordTransfer('companymembers', 'Members', $members->memberID, $member['Detail'], array());
        } while ($members->next());
        echo "<h3>Import Company Members Complete ($imported)</h3>";
        return $imported;
    }

    function ImportCompanies($number = 5) {
        set_time_limit(120);  
        
        echo "<h2>Import Companies</h2>";
        
        list($companyID, $detail) = $this->LastStatus('companies');

        $sql = "select * from companies where companyID > $companyID and (companyID > 0) order by companyID limit $number";
        
        $companies = $this->ORK2->query($sql);
        
        $Company = new APIModel('Unit');
        
        echo $sql . "<p>";
        
        $imported = 0;
        
        if ($companies->size() > 0) do {
            set_time_limit(20);  
            echo "Create Company {$companies->companyname}; ";
            $imported++;
            $company = $Company->CreateUnit(array(
    			'Token' => $this->token,
        		'Name' => $companies->companyname,
            	'Type' => 'Company',
            	'Description' => $companies->description,
            	'History' => $companies->history,
                'Url' => $companies->url,
                'Anonymous' => 1
    		));
            $this->RecordTransfer('companies', 'Unit', $companies->companyID, $company['Detail'], array());
        } while ($companies->next());
        echo "<h3>Import Companies Complete ($imported)</h3>";
        return $imported;
    }

    function ImportPlayers($number = 20) {
        set_time_limit(120);  
        
        echo "<h2>Import Players</h2>";
        
        list($playerID, $detail) = $this->LastStatus('players');

        $sql = "select * 
                    from players 
                        left join chapters on players.chapterID = chapters.chapterID
                    where playerID > $playerID and (players.chapterID > 0 and chapters.kingdomID != 14) order by playerID limit $number";
        
        $players = $this->ORK2->query($sql);
        
        $Player = new APIModel('Player');
        
        echo $sql . "<p>";
        
        $imported = 0;
        
        if ($players->size() > 0) do {
            set_time_limit(120);  
            if ($players->kingdomID == 2) continue;
            list($tmp, $chapter_id) = $this->CacheMap('chapters', $players->chapterID);
            if ($chapter_id == 0)
                list($tmp, $chapter_id) = $this->CacheMap('principality-chapters', $players->chapterID);
            if ($chapter_id == 0) continue;
            echo "Create Player {$players->aname}; ";
            $imported++;
        	$player = $Player->CreatePlayer(array(
    			'Token' => $this->token,
    			'GivenName' => $players->fname,
    			'Surname' => $players->lname,
    			'OtherName' => '',
    			'UserName' => str_replace(array(" ", ".", "'"), "_", strtolower($players->fullaname)),
    			'Password' => md5($players->fullaname),
    			'Persona' => $players->fullaname,
    			'Email' => $players->email,
    			'ParkId' => $chapter_id,
    			'KingdomId' => 0,
    			'Restricted' => 0,
    			'IsActive' => ($players->active == 'Y')?1:0,
    			'Waivered' => 0,
    			'Waiver' => '',
    			'WaiverExt' => '',
    			'HasHeraldry' => 0,
    			'Heraldry' => '',
    			'HasImage' => 0,
    			'Image' => ''
    		));
            /*
            if (trimlen($players->heraldry) > 0)
                $Player->SetHeraldry(array(
                    'Token' => $this->token,
                    'MundaneId' => $player['Detail'],
                    'HeraldryUrl' => $players->heraldry
                ));
            if (trimlen($players->photo) > 0)
                $Player->SetImage(array(
                    'Token' => $this->token,
                    'MundaneId' => $player['Detail'],
                    'ImageUrl' => $players->photo
                ));
            */
            $this->RecordTransfer('players', 'Player', $players->playerID, $player['Detail'], array());
        } while ($players->next());
        echo "<h3>Import Players Complete ($imported)</h3>";
        return $imported;
    }
    
    function ImportPrincipalityParks($number = 20) {
        global $STATES;
        
        echo "<h2>Import Principality Parks</h2>";
        
        list($chapterID, $detail) = $this->LastStatus('principality-chapters');

        $sql = "select * from chapters where chapterID > $chapterID and (principID > 0) order by chapterID limit $number";
        
        $chapters = $this->ORK2->query($sql);
        
        $Park = new APIModel('Park');
        
        echo $sql . "<p>";
        
       if ($chapters->size() > 0)  do {
            set_time_limit(120);  
            if ($chapters->kingdomID == 2) continue;
            echo "Create Principality Park {$chapters->name}<br />";
            list($tmp, $kingdom_id) = $this->CacheMap('princips', $chapters->principID);
            $park = $Park->CreatePark(array(
            	'Token' => $this->token,
    			'Name' => $chapters->name,
    			'Abbreviation' => $chapters->abbre,
    			'KingdomId' => $kingdom_id,
    			'ParkTitleId' => 0
		    ));
            $Park->SetParkDetails(array(
				'Token' => $this->token,
				'ParkId' => $park['Detail'],
				'Heraldry' => '',
				'HeraldryMimeType' => '',
				'Url' => $chapters->URL,
				'Address' => $chapters->ParkAddress,
				'City' => $chapters->ParkCity,
				'Province' => $STATES[$chapters->ParkStateID],
				'PostalCode' => $chapters->ParkZip,
				'MapUrl' => '',
				'Directions' => str_replace("'", "&quote;", str_replace("\n", "<br>", str_replace("\r", "", $chapters->ParkInfo))),
                'GeoCode' => $chapters->GeoCode
			));
            $this->RecordTransfer('principality-chapters', 'Park', $chapters->chapterID, $park['Detail'], array());
        } while ($chapters->next());
        echo "<h3>Import Principality Parks Complete ($imported)</h3>";
    }
    
    function ImportKingdomParks($number = 20) {
        global $STATES;
        
        echo "<h2>Import Kingdom Parks</h2>";
        
        list($chapterID, $detail) = $this->LastStatus('chapters', array("69","149","161","175","176","178","240","264","297","298","299","342","344","345"));

        $sql = "select * from chapters where chapterID > $chapterID and (principID is null or principID = 0) and kingdomID != 14 order by chapterID limit $number";
        
        $chapters = $this->ORK2->query($sql);
        
        $Park = new APIModel('Park');
        
        echo $sql . "<p>";
        
        if ($chapters->size() > 0) do {
            set_time_limit(120);  
            if ($chapters->kingdomID == 2) continue;
            echo "Create Kingdom Park {$chapters->name}<br />";
            list($tmp, $kingdom_id) = $this->CacheMap('kingdoms', $chapters->kingdomID);
            $park = $Park->CreatePark(array(
        		'Token' => $this->token,
    			'Name' => $chapters->name,
    			'Abbreviation' => $chapters->abbre,
    			'KingdomId' => $kingdom_id,
    			'ParkTitleId' => 0
		    ));
            $Park->SetParkDetails(array(
				'Token' => $this->token,
				'ParkId' => $park['Detail'],
				'Heraldry' => '',
				'HeraldryMimeType' => '',
				'Url' => $chapters->URL,
				'Address' => $chapters->ParkAddress,
				'City' => $chapters->ParkCity,
				'Province' => $STATES[$chapters->ParkStateID],
				'PostalCode' => $chapters->ParkZip,
				'MapUrl' => '',
				'Directions' => str_replace("'", "&quote;", str_replace("\n", "<br>", str_replace("\r", "", $chapters->ParkInfo))),
                'GeoCode' => $chapters->GeoCode
			));
            $this->RecordTransfer('chapters', 'Park', $chapters->chapterID, $park['Detail'], array());
        } while ($chapters->next());
        echo "<h3>Import Kingdom Parks Complete</h3>";
    }
    
    function ImportPrincipalities() {
        echo "<h2>Import Principalities</h2>";
        
        list($princip_id, $detail) = $this->LastStatus('princips');

        $sql = "select * from princips where principID > $princip_id order by principID";
        
        $princips = $this->ORK2->query($sql);
        
        $Principality = new APIModel('Principality');
        
        echo $sql . "<p>";
        
        if ($princips->size() > 0) do {
            echo "Create Principality {$princips->principname}<br />";
            list($tmp, $kingdom_id) = $this->CacheMap('kingdoms', $princips->kingdomID);
            $princip = $Principality->CreatePrincipality(array(
                'Token' => $this->token,
                'Name' => $princips->principname,
            	'Abbreviation' => '',
            	'AveragePeriod' => 6,
            	'AttendancePeriodType' => 'Month',
            	'AttendanceMinimum' => 6,
            	'AttendanceCreditMinimum' => 9,
            	'DuesPeriod' => 6,
            	'DuesPeriodType' => 'Month',
            	'DuesAmount' => 6.0,
            	'KingdomDuesTake' => 3.0,
                'KingdomId' => $kingdom_id
            ));
            $this->RecordTransfer('princips', 'Principality', $princips->principID, $princip['Detail'], array());
        } while ($princips->next());
        echo "<h3>Import Principalities Complete</h3>";
    }

    function ImportKingdoms() {
        echo "<h2>Import Kingdoms</h2>";
        
        list($kingdom_id, $detail) = $this->LastStatus('kingdoms', array(14));
 
        $sql = "select * from kingdoms where kingdomID > $kingdom_id and kingdomID != 14 order by kingdomID";
        
        $kingdoms = $this->ORK2->query($sql);
        
        $Kingdom = new APIModel('Kingdom');
        
        echo $sql . "<p>";
        
        if ($kingdoms->size() > 0) do {
            if ($kingdoms->kingdomID == 2) continue;
            echo "Create Kingdom {$kingdoms->name}<br />";
            $kingdom = $Kingdom->CreateKingdom(array(
                'Token' => $this->token,
            	'Name' => $kingdoms->name,
            	'Abbreviation' => $kingdoms->abbr,
            	'AveragePeriod' => 6,
            	'AttendancePeriodType' => 'Month',
            	'AttendanceMinimum' => 6,
            	'AttendanceCreditMinimum' => 9,
            	'DuesPeriod' => 6,
            	'DuesPeriodType' => 'Month',
            	'DuesAmount' => 6.0,
            	'KingdomDuesTake' => 3.0,
                'HeraldryUrl' => 'http://www.amtgardrecords.com/images/kingdomthumbs/' . $kingdoms->kingdomthumb
            ));
            $this->RecordTransfer('kingdoms', 'Kingdom', $kingdoms->kingdomID, $kingdom['Detail'], array());
        } while ($kingdoms->next());
        echo "<h3>Import Kingdoms Complete</h3>";
    } 
    
    function InitializeImport() {
        if ($this->IsStarted()) {
            $this->cache = new Yapo($this->ORK2, 'dbimport_cache');
            $this->status = new Yapo($this->ORK2, 'dbimport_status');
            return;
        }
        $cache = "
            CREATE TABLE IF NOT EXISTS `dbimport_cache` (
              `cache_id` int(11) NOT NULL AUTO_INCREMENT,
              `ork2_table` varchar(120) NOT NULL,
              `ork3_table` varchar(120) NOT NULL,
              `ork2_id` int(11) NOT NULL,
              `ork3_id` int(11) NOT NULL,
              PRIMARY KEY (`cache_id`),
              UNIQUE KEY `ork2_table` (`ork2_table`,`ork3_table`,`ork2_id`,`ork3_id`),
              KEY `ork2_table_2` (`ork2_table`),
              KEY `ork3_table` (`ork3_table`),
              KEY `ork2_id` (`ork2_id`),
              KEY `ork3_id` (`ork3_id`)
            ) ENGINE=MyISAM  DEFAULT CHARSET=utf8 AUTO_INCREMENT=1 ;
        ";
        $status = "
            CREATE TABLE IF NOT EXISTS `dbimport_status` (
              `status_id` int(11) NOT NULL AUTO_INCREMENT,
              `table` varchar(40) NOT NULL,
              `last_record` int(11) NOT NULL,
              `status` varchar(2000) NOT NULL,
              `wl` tinyint(1) NOT NULL,
              PRIMARY KEY (`status_id`),
              UNIQUE KEY `table` (`table`,`last_record`)
            ) ENGINE=MyISAM  DEFAULT CHARSET=utf8 AUTO_INCREMENT=1 ;
        ";
        $this->ORK2->query($cache);
        $this->ORK2->query($status);
        
        $this->cache = new Yapo($this->ORK2, 'dbimport_cache');
        $this->status = new Yapo($this->ORK2, 'dbimport_status');
    }
    
    public function FinalizeInit() {
        $this->RecordTransfer('init', 'init', 1, 1, array());
    }
    
    public function InitFinalized() {
        list($rec, $status) = $this->LastStatus('init');
        return ($rec > 0);
    }
    
    public function RecordTransfer($ork2_t, $ork3_t, $ork2_id, $ork3_id, $status, $wl = 0) {
        $this->AddCacheLine($ork2_t, $ork3_t, $ork2_id, $ork3_id, $status);
        $this->AddStatus($ork2_t, $ork2_id, $status, $wl);
    }
    
    public function AddStatus($table, $record_id, $status, $wl = 0) {
        $this->status->clear();
        $this->status->table = $table;
        $this->status->last_record = $record_id;
        $this->status->status = json_encode($status);
        $this->status->wl = $wl;
        $this->status->save();
    }
    
    public function LastStatus($table, $exclude = null, $wl = 0) {
        $sql = "select * from dbimport_status where `table` = '$table'";
        if (is_array($exclude))
            $sql .= " and last_record not in (" . implode(', ', $exclude) . ")";
        $sql .= " and wl = $wl order by last_record desc limit 1";
        /*
        $this->status->clear();
        $this->status->table = $table;
        if ($this->status->find(array('last_record desc'))) {
            return array($this->status->last_record, json_decode($this->status->status, true));
        }
        */
        echo "<h4 style='color:#00f'>$sql</h4>";
        $last = $this->ORK2->query($sql);
        if ($last !== false && $last->size() == 1)
            return array($last->last_record, json_decode($last->status, true));
        return array(0,array());
    }
    
    function IsStarted() {
        if (!isset($this->status)) return false;
        $this->status->clear();
        return $this->status->find() > 0;
    }
    
    function AddCacheLine($ork2_t, $ork3_t, $ork2_id, $ork3_id, $status = null) {
        $this->cache->clear();
        if ($ork2_id * $ork3_id == 0) {
            die("<h2>Cache miss!: AddCacheLine($ork2_t, $ork3_t, $ork2_id, $ork3_id): " . print_r($status, true) . "</h2>");
        }
        $this->cache->ork2_table = $ork2_t;
        $this->cache->ork2_id = $ork2_id;
        $this->cache->ork3_table = $ork3_t;
        $this->cache->ork3_id = $ork3_id;
        $this->cache->save();
    }
    
    function CacheMap($ork_t, $ork_id, $reverse = false, $ork_t_opp = null) {
        if (!valid_id($ork_id))
            die("<h3>Must select a valid ork id!: CacheMap($ork_t, $ork_id, $reverse = false, $ork_t_opp = null)</h3>");
        $this->cache->clear();
        if ($reverse) {
            $table = 'ork3_table';
            $id = 'ork3_id';
            $rtable = 'ork2_table';
            $rid = 'ork2_id';
        } else {
            $table = 'ork2_table';
            $id = 'ork2_id';
            $rtable = 'ork3_table';
            $rid = 'ork3_id';
        }
        $this->cache->$table = $ork_t;
        $this->cache->$id = $ork_id;
        if (!is_null($ork_t_opp))
            $this->cache->$rtable = $ork_t_opp;
        if ($this->cache->find())
            return array($this->cache->$rtable, $this->cache->$rid);
    }
    
    function InitializeOrk3() {
        if ($this->IsStarted()) return;
        /*
        $clear = array( 'account', 'application', 'application_auth', 'attendance', 'authorization', 'awardlimit', 'award', 'awards', 'bracket', 'bracket_officiant', 'class_reconciliation', 'configuration', 'credential', 'event', 
    	'event_calendardetail', 'glicko2', 'kingdom', 'kingdomaward', 'log', 'match', 'mundane', 'officer', 'park', 'parkday', 'parktitle', 'participant', 'participant_mundane', 'seed', 'split', 'team', 'tournament', 'transaction', 
    	'unit', 'unit_mundane');
    
    	echo "<h1>Empty Tables &amp; Prep Admin User</h1>";
    
    	foreach ($clear as $dbname) {
    		echo "Empty table $dbname ... ";
    		$this->DB->query('truncate table orkdev_' . $dbname);
    	}
        */
        $sql = "INSERT INTO `" . DB_PREFIX . "mundane` 
            (`given_name`, `surname`, `other_name`, `username`, `persona`, `email`, `park_id`, `kingdom_id`, `token`, `modified`, `restricted`, `waivered`, `waiver_ext`, `has_heraldry`, `has_image`, `company_id`, `token_expires`, `password_expires`, `password_salt`, `xtoken`, `penalty_box`, `active`) VALUES 
                ('adminimport', 'adminimport', 'adminimport', 'adminimport', 'adminimport', 'en.gannim@gmail.com', 0, 0, '', '2013-09-24 12:55:31', 0, 0, '', 0, 0, 0, '0000-00-00 00:00:00', '2014-04-24 11:55:31', 'b1a838cc8bbbdc7d2008ac00890cb8eb', '', 0, 1)";
        $this->DB->query($sql);
        
        $sql = "SELECT mundane_id
                    FROM  `" . DB_PREFIX . "mundane` 
                    WHERE username = 'adminimport'";
        $admin = $this->ORK3->query($sql);
        
        $sql = "INSERT INTO `" . DB_PREFIX . "credential` (`key`, `expiration`) VALUES ('" . Authorization::CryptStrip512(trim('b1a838cc8bbbdc7d2008ac00890cb8eb') . trim($this->adminpassword), 'b1a838cc8bbbdc7d2008ac00890cb8eb') . "', '2014-09-29 23:08:36')";
        $this->DB->query($sql);
        
        $sql = "INSERT INTO `" . DB_PREFIX . "authorization` (`mundane_id`, `park_id`, `kingdom_id`, `event_id`, `unit_id`, `role`, `modified`) VALUES (" . $admin->mundane_id . ", 0, 0, 0, 0, 'admin', '2013-09-24 13:28:25')";
        $this->DB->query($sql);
        
        $this->Attendance->create_system_classes();
        $this->Award->create_system_awards();
        
        
    }
}

$STATES = array(
        '1'=>'Alabama',
        '2'=>'Alaska',
        '3'=>'Arizona',
        '4'=>'Arkansas',
        '5'=>'California',
        '6'=>'Colorado',
        '7'=>'Connecticut',
        '8'=>'Delaware',
        '9'=>'Florida',
        '10'=>'Georgia',
        '11'=>'Hawaii',
        '12'=>'Idaho',
        '13'=>'Illinois',
        '14'=>'Indiana',
        '15'=>'Iowa',
        '16'=>'Kansas',
        '17'=>'Kentucky',
        '18'=>'Louisiana',
        '19'=>'Maine',
        '20'=>'Maryland',
        '21'=>'Massachusetts',
        '22'=>'Michigan',
        '23'=>'Minnesota',
        '24'=>'Mississippi',
        '25'=>'Missouri',
        '26'=>'Montana',
        '27'=>'Nebraska',
        '28'=>'Nevada',
        '29'=>'New Hampshire',
        '30'=>'New Jersey',
        '31'=>'New Mexico',
        '32'=>'New York',
        '33'=>'North Carolina',
        '34'=>'North Dakota',
        '35'=>'Ohio',
        '36'=>'Oklahoma',
        '37'=>'Oregon',
        '38'=>'Pennsylvania',
        '39'=>'Rhode Island',
        '40'=>'South Carolina',
        '47'=>'Washington',
        '46'=>'Virginia',
        '45'=>'Vermont',
        '44'=>'Utah',
        '43'=>'Texas',
        '42'=>'Tennessee',
        '41'=>'South Dakota',
        '48'=>'West Virginia',
        '49'=>'Wisconsin',
        '50'=>'Wyoming',
    );

?>