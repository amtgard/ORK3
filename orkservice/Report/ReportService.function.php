<?php

function GetActiveKingdomsSummary($request) {
	$R = new Report();
	return $R->GetActiveKingdomsSummary($request);
}

function GetActivePlayers($request) {
	$R = new Report();
	return $R->GetActivePlayers($request);
}

function GetKingdomParkAverages($request) {
	$R = new Report();
	return $R->GetKingdomParkAverages($request);
}

function GetKingdomParkMonthlyAverages($request) {
	$R = new Report();
	return $R->GetKingdomParkMonthlyAverages($request);
}

function GetTopParksByAttendance($request) {
	$R = new Report();
	return $R->GetTopParksByAttendance($request);
}

function GetPlayerRoster($request) {
	$R = new Report();
	return $R->GetPlayerRoster($request);
}

/*
 * NOTE ON AUTH: this wrapper is the SOAP path only. The JSON gateway does NOT
 * come through this file -- JsonServer::call_endpoint() does `new Report()` and
 * invokes the lib method directly. A token check here would therefore guard
 * SOAP while leaving JSON wide open, which is worse than no check at all, so
 * this stays a bare pass-through like its siblings.
 *
 * The endpoint is consequently unauthenticated, which matches every other
 * registered Report op AND matches the web: Parknew_index.tpl emits the same
 * list into the public park profile for anonymous visitors. If that should
 * change, the gate belongs in Report::RecentParkAttendees() (covering both
 * transports), and the two web callers must then pass a session token.
 */
function RecentParkAttendees($request) {
	$R = new Report();
	return $R->RecentParkAttendees($request);
}

?>