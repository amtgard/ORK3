<?php

function GetParkShortInfo($request) {
	$K = new Park();
	return $K->GetParkShortInfo($request);
}

function GetParkAuthorizations($request) {
	$K = new Park();
	return $K->GetParkAuthorizations($request);
}

function CreatePark($request) {
	$K = new Park();
	return $K->CreatePark($request);
}

function SetParkDetails($request) {
	$K = new Park();
	return $K->SetParkDetails($request);
}

function RetirePark($request) {
	$K = new Park();
	return $K->RetirePark($request);
}
	
function RestorePark($request) {
	$K = new Park();
	return $K->RestorePark($request);
}

?>