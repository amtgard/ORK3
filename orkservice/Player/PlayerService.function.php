<?php

function GetPlayers($request) {
	$P = new Player();
	return $P->GetPlayers($request);
}


function ReconcileAward($request) {
	$P = new Player();
	return $P->ReconcileAward($request);
}


?>