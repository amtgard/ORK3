<?php
use setasign\Fpdi\Fpdi;

include_once(DIR_SYSTEM . 'tools/src/fpdf/fpdf.php');
include_once(DIR_SYSTEM . 'tools/src/fpdi/src/autoload.php');

function ptsToMm($pts) {
	return $pts / 72 * 25.4;
}

function super_duper_encryption($string, $key) {
    // Our plaintext/ciphertext
    $text = $string;

    // Our output text
    $outText = '';

    // Iterate through each character
    for($i=0; $i<strlen($text); )
    {
        for($j=0; ($j<strlen($key) && $i<strlen($text)); $j++,$i++)
        {
            $outText .= $text{$i} ^ $key{$j};
        }
    }
    return substr(bin2hex($outText), 15, 32);
}

class Tools extends Ork3
{

	public function __construct()
	{
		parent::__construct();
		$this->park = new yapo( $this->db, DB_PREFIX . 'park' );
  }
  
  public function HasRole($request) {
    return (( $mundane_id = Ork3::$Lib->authorization->IsAuthorized( $request[ 'Token' ] ) ) > 0)
            && (Ork3::$Lib->unit->IsMember((array('Name'=>$request['Role'], 'MundaneId'=>$mundane_id)))['Detail']['IsMember']);
  }
  
	public function AuthorizeContract($request) {
		$this->park->clear();
		$this->park->park_id = $request['ParkId'];
		$this->park->find();
		
		return Success($this->_AuthorizeContract($request['Key'], $this->park));
	}
	
	public function _AuthorizeContract($token, & $park) {
		if (strlen($park->access_key) == 32 && $park->active == 'Retired' 
				&& $park->kingdom_id == 26 && defined('PARK_ACCESS_KEY')) {
			return $token == super_duper_encryption($park->access_key, PARK_ACCESS_KEY);
		}
		return false;
	}
	
	public function SetContractParkDetails($request) {
		
	}
	
  public function GenerateContract($request) {
		/*
			Page 1
				Day: 271, 100
				Month: 326, 100
				YY: 417, 100
				Licensee: 322, 114
			Page 2
				Chapter: 196, 450
			Page 3
				Licensee: 104, 595
				Chapter: 399, 571
				City, State: 313, 684
		*/
		
		$pdf = new FPDI();

		// get the page count
		$pageCount = $pdf->setSourceFile(DIR_ASSETS . '/contracts/src/AI-Group-Contract-V1-unlocked.pdf');
		// iterate through all pages
		for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
				// import a page
				$templateId = $pdf->importPage($pageNo);
				// get the size of the imported page
				$size = $pdf->getTemplateSize($templateId);
			
				$pdf->AddPage($size['orientation'], array(215.9, 279.4));
			
				$pdf->useTemplate($templateId, 0, 0, 215.9);
			
				$pdf->SetTextColor(20, 80, 240);
				$pdf->SetFont('Times', 'b');
				switch ($pageNo) {
					case 1:
						
						$pdf->SetXY(ptsToMm(271), ptsToMm(93.3));
						$pdf->Write(0, date("j"));
						$pdf->SetXY(ptsToMm(326), ptsToMm(93.3));
						$pdf->Write(0, date("F"));
						$pdf->SetXY(ptsToMm(417), ptsToMm(93.3));
						$pdf->Write(0, date("y"));
						$pdf->SetXY(ptsToMm(322), ptsToMm(107.3));
						$pdf->Write(0, $request['Licensee']);
						break;
					case 2:
						$pdf->SetXY(ptsToMm(194), ptsToMm(444.5));
						$pdf->Write(0, $request['Chapter']);
						break;
					case 5:
						$pdf->SetXY(ptsToMm(312), ptsToMm(612));
						$pdf->Write(0, $request['Licensee'] . ", " . $request['Title']);
						$pdf->SetFont('Times', 'bi', 10);
						$pdf->SetXY(ptsToMm(396), ptsToMm(565.4));
						$pdf->Write(0, $request['Chapter']);
						$pdf->SetFont('Times', 'b', 12);
						$pdf->SetXY(ptsToMm(313), ptsToMm(681.4));
						$pdf->Write(0, $request['City'] . ", " . $request['State']);
						break;
				}
		}
		// Output the new PDF
		$pdf->Output("D", "AIBOD Contract with " . $request['Chapter'] . " " . date("Y-m-d") . ".pdf");  
	}
  
}