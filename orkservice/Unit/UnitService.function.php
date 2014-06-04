<?php


function GetAttributeList($request) {
	$response = array(
		'Status' => ServiceErrorIds::FunctionUnimplemented,
		'Error' => Unimplemented(),
		'ComponentAttributeList' => array()
		);
	if (!TokenIsSecure($request['SecureToken'])) {
		$response['Error'] = BadToken();
		$response['Status'] = $response['Error']['Code'];
		return $response;
	}
	$m = new yapo_mysql(DB_HOSTNAME, DB_DATABASE, DB_USERNAME, DB_PASSWORD);
	$y = new yapo($m, DB_PREFIX.'cattribute');
	if ($y->find()) {
		do {
			$response['AttributeList'][] = array(
					'AttributeId' => $y->cattribute_id,
					'Name' => $y->attribute_name
				);
		} while ($y->next());
	}
	return $response;
}

?>