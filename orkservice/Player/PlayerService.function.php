<?php

function GetPlayers($request) {
	$P = new Player();
	return $P->GetPlayers($request);
}


?>