<?php
$server->register(
		'Calendar.Next',
		array('NextRequest'=>'tns:NextRequest'),
		array('return' => 'tns:NextResponse'),
		$namespace
	);

?>