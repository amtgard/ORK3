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

class ImportWlOrk3 {
    var $adminuser = 'admin';
    var $adminpassword = 'e01e44f3';
    
    var $ORK2;
    
    var $chapterMap = array(
            "69" => 7,
            "149" => 6,
            "161" => 11,
            "175" => 8,
            "176" => 10,
            "178" => 9,
            "240" => 20,
            "264" => 5,
            "297" => 23,
            "298" => 18,
            "299" => 1,
            "342" => 2,
            "344" => 24,
            "345" => 21
        );
    
    function __construct($DB, $iOrk2) {
        $this->iOrk2 = $iOrk2;
        
        $this->ORK2 = new yapo_mysql(DB_HOSTNAME, 'orkrecords_ork2test', DB_USERNAME, DB_PASSWORD);
        $this->ORK3 = new yapo_mysql(DB_HOSTNAME, 'orkrecords_dbimport', DB_USERNAME, DB_PASSWORD);
        $this->DB = $DB;
        
        $this->Token = $this->iOrk2->Login();
    }
    
    function Import() {
        $this->MapKingdom();
        $this->MapChapters();
        $this->MapPlayers();
        //$this->ImportWetlands();
    }
    
    function MapKingdom() {
        $this->iOrk2->RecordTransfer('kingdoms', 'Kingdom', 14, 1, array(), 1);
    }
    
    function MapChapters() {
        foreach ($this->chapterMap as $ork2_id => $ork3_id)
            $this->iOrk2->RecordTransfer('chapters', 'Park', $ork2_id, $ork3_id, array(), 1);
    }
    
    function MapPlayers() {
        echo "<h3>Map Players</h3>";
        $sql = "select mundane_id, given_name, surname, persona, park_id from ork_mundane where (length(given_name) > 0 or length(surname) > 0 or length(persona) > 0) and ork_mundane.park_id not in (11, 9, 1, 0)";
        $ork3_players = $this->ORK3->query($sql);
        $hit = 0;
        $nohit = 0;
        if ($ork3_players->size() > 0) do {
            if (!$this->FindOrk2Player($ork3_players->mundane_id, $ork3_players->persona, $ork3_players->given_name, $ork3_players->surname, $ork3_players->park_id)) {
                //echo "Could not find: {$ork3_players->mundane_id}: {$ork3_players->given_name} {$ork3_players->surname} ({$ork3_players->persona})";
                $nohit++;
            } else {
                $hit++;
            }
        } while ($ork3_players->next());
        echo "<h3>Hit: $hit; No Hit: $nohit</h3>";
    }
    
    function FindOrk2Player($ork3_id, $aname, $fname, $lname, $chapter) {
        if (array_key_exists($chapter, $this->chapterMap)) {
            $sql = "
                    select * 
                        from ORKplayers
                        where chapterID = {$this->chapterMap[$chapter]}
                            and aname like '$aname' and fname like '$fname' and lname like '$lname'";
            $player = $this->ORK2->query($sql);
            
            if ($player->size() == 1) {
                //echo "Found $ork3_id: ($aname): {$player->playerID}; ";
                $this->iOrk2->RecordTransfer('players', 'Player', $player->playerID, $ork3_id, array(), 1);
                return true;
            } else if ($player->size() > 1) {
                //echo "Too many...";
            } else {
                //echo "No hits...";
            }
        }
        $sql = "
                select * 
                    from ORKplayers
                    where 
                        aname like '" . mysql_real_escape_string($aname) . "' 
                        and fname like '" . mysql_real_escape_string($fname) . "' 
                        and lname like '" . mysql_real_escape_string($lname) . "'";
        $player = $this->ORK2->query($sql);
        
        if ($player->size() == 1) {
            //echo "Found $ork3_id: ($aname): {$player->playerID}; ";
            $this->iOrk2->RecordTransfer('players', 'Player', $player->playerID, $ork3_id, array(), 1);
            return true;
        } else if ($player->size() > 1) {
            //echo "Too many...";
        } else {
            //echo "No hits...";
        }
        
        return false;
    }
    
}

?>