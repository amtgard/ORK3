<?php

include_once("../config.php");
error_reporting(E_ERROR | E_WARNING | E_PARSE);
include_once("import.primary.php");
include_once("import.ork2.wl.php");

$iOrk2 = new ImportOrk2($DB);
$iOrk2->InitializeImport();

if ($_REQUEST['init'] == init) {
    if ($iOrk2->InitFinalized()) {
        die("Import is already finalized.  You will need to clean the database (or import from Beta) to proceed.<p>Alternatively, remove the ?init=init parameter to proceed with the ORK2 import.");
    }
    echo "Initialize()";
    $iOrk2->Reset();
    $iOrk2->InitializeOrk3();
    $iOrk2->Login();    
    /*******************************************************************************
     * 
     * Import WL Beta site
     *  Map Parks to Cache
     * 
     ******************************************************************************/
    $iOrkWl = new ImportWlOrk3($DB, $iOrk2);
    $iOrkWl->Import();
    $iOrk2->ImportKingdoms();
    $iOrk2->ImportPrincipalities();
    
    $iOrk2->FinalizeInit();
    if (!$iOrk2->InitFinalized()) {
        die("<h2>Script initialization has failed catastrophically.  Please restart.</h2>");
    }
    die("Import setup is finalized.  Remove ?init=init parameter.");
} else if (!$iOrk2->InitFinalized()) {
    die("Import is not finalized, run with ?init=init parameter.\n");
}

$iOrk2->ImportKingdomParks(600);
$iOrk2->ImportPrincipalityParks(100);
if ($iOrk2->ImportPlayers(10000) == 0) {
    if ($iOrk2->ImportCompanies(2500) == 0) { //companies
        if ($iOrk2->ImportCompanyMembers(10000) == 0) { //companies
    /***************************************************************************
     * Attendance
     * -    credits
     *      -   Assumes classtype table has IsStandard set to corresponding Ork3 class_id
     * -    viscredits
     **************************************************************************/
            if ($iOrk2->ImportCredits(100000) == 0) {
                if ($iOrk2->ImportVisitorCredits(50000) == 0) {
    /***************************************************************************
     * Awards
     * -    awards
     *      -   Assumes awardtypes has added column ork3_award_id
     * -    companyawards
     * -    knightsbelts
     * -    squires
     * -    masters
     * -    companyawards
     **************************************************************************/
                    if ($iOrk2->ImportAwards(25000) == 0) {
                        if ($iOrk2->ImportOtherAwards() == 0) {
    /***************************************************************************
     * Notes
     * -    titles
     * -    positions
     * -    misc
     * -    knights blobs
     * -    knightaccomplish
     * -    groups
     * 
     * Structure
     * -    Note
     * -    Description
     * -    GivenBy
     * -    Date
     * -    DateComplete
     **************************************************************************/
                            if ($iOrk2->ImportNotes() == 0){
                                die("<h3>Import Complete.</h3>");
                            }
                        }
                    }
                }
            }
        }
    }
}
echo "<h3>Timeout complete.  Run script again.</h3>";

?>