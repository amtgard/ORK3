<?php

function GetKingdomShortInfo($request) {
	$K = new Kingdom();
	return $K->GetKingdomShortInfo($request);
}

function GetKingdomDetails($request) {
	$K = new Kingdom();
	return $K->GetKingdomDetails($request);
}

function GetKingdomAuthorizations($request) {
	$K = new Kingdom();
	return $K->GetKingdomAuthorizations($request);
}

function CreateKingdom($request) {
	$K = new Kingdom();
	return $K->CreateKingdom($request);
}

function SetKingdomDetails($request) {
	$K = new Kingdom();
	return $K->SetKingdomDetails($request);
}

function RetireKingdom($request) {
	$K = new Kingdom();
	return $K->RetireKingdom($request);
}
	
function RestoreKingdom($request) {
	$K = new Kingdom();
	return $K->RestoreKingdom($request);
}
	


?>