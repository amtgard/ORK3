<?php
$server->register(
		'Pronoun.GetPronounList',
		array('GetPronounListRequest'=>'tns:GetPronounListRequest'),
		array('return' => 'tns:GetPronounListResponse'),
		$namespace
	);
	
?>