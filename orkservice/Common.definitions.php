<?php

/***

Common Classes

***/

// Authorization Definitions

function Success($detail=null) {
	return array (
		'Status' => ServiceErrorIds::Success,
		'Error' => ServiceErrorMessages::Success,
		'Detail' => $detail
		);
}

function Warning($detail=null) {
	return array (
		'Status' => ServiceErrorIds::Success,
		'Error' => ServiceErrorMessages::Warning,
		'Detail' => $detail
		);
}

function Unimplemented($detail=null, $error=null) {
	return array (
		'Status' => ServiceErrorIds::FunctionUnimplemented,
		'Error' => is_null($error)?ServiceErrorMessages::FunctionUnimplemented:$error,
		'Detail' => $detail
		);
}

function BadToken($detail=null, $error=null) {
	return array (
		'Status' => ServiceErrorIds::SecureTokenFailure,
		'Error' => is_null($error)?ServiceErrorMessages::SecureTokenFailure:$error,
		'Detail' => $detail
		);
}

function NoAuthorization($detail=null, $error=null) {
	return array (
		'Status' => ServiceErrorIds::NoAuthorization,
		'Error' => is_null($error)?ServiceErrorMessages::NoAuthorization:$error,
		'Detail' => $detail
		);
}

function InvalidParameter($detail=null, $error=null) {
	return array (
		'Status' => ServiceErrorIds::InvalidParameter,
		'Error' => is_null($error)?ServiceErrorMessages::InvalidParameter:$error,
		'Detail' => $detail
		);
}

function ProcessingError($detail=null, $error=null) {
	return array (
		'Status' => ServiceErrorIds::ProcessingError,
		'Error' => is_null($error)?ServiceErrorMessages::ProcessingError:$error,
		'Detail' => $detail
		);
}

function Deprecated($detail=null, $error=null) {
	return array (
		'Status' => ServiceErrorIds::Deprecated,
		'Error' => is_null($error)?ServiceErrorMessages::Deprecated:$error,
		'Detail' => $detail
		);
}

abstract class ServiceErrorIds {
	const Success = 0;
	const FunctionUnimplemented = 1;
	const SecureTokenFailure = 2;
	const ProcessingError = 3;
	const InvalidParameter = 4;
	const NoAuthorization = 5;
	const Deprecated = 6;
}

abstract class ServiceErrorMessages {
	const Success = "Success";
	const Warning = "Warning";
	const FunctionUnimplemented = "Service is unimplemented or error unknown.";
	const SecureTokenFailure = "The secure token cannot be verified.";
	const ProcessingError = "There was an error processing your request with the parameters provided.";
	const InvalidParameter = "You have set a parameter incorrectly.";
	const NoAuthorization = "You do not privileges to perform this action.";
	const Deprecated = "This method is no longer valid.";
}

?>