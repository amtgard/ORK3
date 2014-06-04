<?php

function Authorize($request) {
	$A = new Authorization();
	return $A->Authorize($request);
}

function XSiteAuthorize($request) {
	$A = new Authorization();
	return $A->XSiteAuthorize($request);
}

function AddAuthorization($request) {
	$A = new Authorization();
	return $A->AddAuthorization($request);
}

function RemoveAuthorization($request) {
	$A = new Authorization();
	return $A->RemoveAuthorization($request);
}

function ResetPassword($request) {
	$A = new Authorization();
	return $A->ResetPassword($request);
}

function GetAuthorizations($request) {
	$A = new Authorization();
	return $A->GetAuthorizations($request);
}

?>