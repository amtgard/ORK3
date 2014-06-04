<?php
$server->register(
		'Heraldry.GetHeraldry',
		array('GetHeraldryRequest'=>'tns:GetHeraldryRequest'),
		array('return' => 'tns:GetHeraldryResponse'),
		$namespace
	);

$server->register(
		'Heraldry.GetHeraldryUrl',
		array('GetHeraldryUrlRequest'=>'tns:GetHeraldryUrlRequest'),
		array('return' => 'tns:GetHeraldryUrlResponse'),
		$namespace
	);
$server->register(
		'HeraldrySetHeraldry',
		array('SetHeraldryRequest'=>'tns:SetHeraldryRequest'),
		array('return' => 'tns:StatusType'),
		$namespace
	);

?>