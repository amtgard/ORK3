<?php

function GetPlayers($request) {
	$P = new Player();
	return $P->GetPlayers($request);
}

function ReconcileAward($request) {
	$P = new Player();
	return $P->ReconcileAward($request);
}

function AutoAssignRanks($request) {
	$P = new Player();
	return $P->AutoAssignRanks($request);
}


?>