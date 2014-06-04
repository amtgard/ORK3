<?php

function HeraldrySetHeraldry($request) {
	$h = new Heraldry();
	switch($request['Type']) {
		case 'Player': return $h->SetPlayerHeraldry($request);
		case 'Park': $request['ParkId'] = $request['Id']; return $h->SetParkHeraldry($request);
		case 'Kingdom': $request['KingdomId'] = $request['Id']; return $h->SetKingdomHeraldry($request);
		case 'Unit': $request['UnitId'] = $request['Id']; return $h->SetUnitHeraldry($request);
		case 'Event': $request['EventId'] = $request['Id']; return $h->SetEventHeraldry($request);
	}
}

?>