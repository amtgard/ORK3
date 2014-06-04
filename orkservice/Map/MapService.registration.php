<?php
$server->register(
		'AddVendor',
		array('AddVendorRequest'=>'tns:AddVendorRequest'),
		array('return' => 'tns:AddVendorResponse'),
		$namespace
	);

?>