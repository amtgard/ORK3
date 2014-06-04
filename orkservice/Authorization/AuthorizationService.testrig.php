<pre>

<?php


$TESTS = 1;

$DONOTWEBSERVICE = true;
include_once('AuthorizationService.php');

$request = array(
		'Username' => 'admin',
		'Password' => 'password'
	);


$Auth = new APIModel("Authorization");
$r = $Auth->Authorize($request);

print_r($r);

die();

if (APP_STAGE != 'DEV') die('Running testrigs on a non-Dev system will delete your data! ' . APP_STAGE);

/*******************************************
 * Test Authorization Checks
 ******************************************/


/*******************************************
 * NO USER
 ******************************************/
 
echo "</pre><h2>Authorization: no user</h2><pre>"; 
$authp = array();
$authp[] = array(AUTH_ADMIN,0,AUTH_ADMIN,false);
$authp[] = array(AUTH_KINGDOM,1,AUTH_EDIT,false);
$authp[] = array(AUTH_KINGDOM,1,AUTH_CREATE,false);
$authp[] = array(AUTH_PARK,3,AUTH_EDIT,false);
$authp[] = array(AUTH_PARK,3,AUTH_CREATE,false);
$authp[] = array(AUTH_EVENT,1,AUTH_EDIT,false);
$authp[] = array(AUTH_EVENT,1,AUTH_CREATE,false);
$authp[] = array(AUTH_UNIT,1,AUTH_EDIT,false);
$authp[] = array(AUTH_UNIT,1,AUTH_CREATE,false);

TestHasAuthority('12345678912345678912345678912345', $authp);

/*******************************************
 * ADMIN
 ******************************************/
 
echo "</pre><h2>Authorization: admin</h2><pre>"; 
$authp = array();
$authp[] = array(AUTH_ADMIN,0,AUTH_ADMIN,true);
$authp[] = array(AUTH_KINGDOM,1,AUTH_EDIT,true);
$authp[] = array(AUTH_KINGDOM,1,AUTH_CREATE,true);
$authp[] = array(AUTH_KINGDOM,0,AUTH_EDIT,true);
$authp[] = array(AUTH_KINGDOM,0,AUTH_CREATE,true);
$authp[] = array(AUTH_PARK,0,AUTH_EDIT,true);
$authp[] = array(AUTH_PARK,0,AUTH_CREATE,true);
$authp[] = array(AUTH_PARK,3,AUTH_EDIT,true);
$authp[] = array(AUTH_PARK,3,AUTH_CREATE,true);
$authp[] = array(AUTH_EVENT,1,AUTH_EDIT,true);
$authp[] = array(AUTH_EVENT,1,AUTH_CREATE,true);
$authp[] = array(AUTH_EVENT,4,AUTH_EDIT,true);
$authp[] = array(AUTH_EVENT,4,AUTH_CREATE,true);
$authp[] = array(AUTH_UNIT,1,AUTH_EDIT,true);
$authp[] = array(AUTH_UNIT,1,AUTH_CREATE,true);

$request = array (
	'UserName' => 'admin',
	'Password' => 'p455w0rd'
);

$r = Authorize($request);

TestHasAuthority($r['Token'], $authp);

/*******************************************
 * KPMONE
 ******************************************/

echo "</pre><h2>Authorization: kpmone</h2><pre>"; 
$authp = array();
$authp[] = array(AUTH_ADMIN,0,AUTH_ADMIN,false);
$authp[] = array(AUTH_KINGDOM,1,AUTH_EDIT,true);
$authp[] = array(AUTH_KINGDOM,1,AUTH_CREATE,true);
$authp[] = array(AUTH_KINGDOM,2,AUTH_EDIT,false);
$authp[] = array(AUTH_KINGDOM,2,AUTH_CREATE,false);
$authp[] = array(AUTH_KINGDOM,0,AUTH_EDIT,false);
$authp[] = array(AUTH_KINGDOM,0,AUTH_CREATE,false);
$authp[] = array(AUTH_PARK,0,AUTH_EDIT,false);
$authp[] = array(AUTH_PARK,0,AUTH_CREATE,false);
$authp[] = array(AUTH_PARK,2,AUTH_EDIT,true);
$authp[] = array(AUTH_PARK,2,AUTH_CREATE,true);
$authp[] = array(AUTH_PARK,3,AUTH_EDIT,false);
$authp[] = array(AUTH_PARK,3,AUTH_CREATE,false);
$authp[] = array(AUTH_EVENT,1,AUTH_EDIT,true);
$authp[] = array(AUTH_EVENT,1,AUTH_CREATE,true);
$authp[] = array(AUTH_EVENT,2,AUTH_EDIT,true);
$authp[] = array(AUTH_EVENT,2,AUTH_CREATE,true);
$authp[] = array(AUTH_EVENT,3,AUTH_EDIT,false);
$authp[] = array(AUTH_EVENT,3,AUTH_CREATE,false);
$authp[] = array(AUTH_UNIT,1,AUTH_EDIT,false);
$authp[] = array(AUTH_UNIT,1,AUTH_CREATE,false);

$request = array (
	'UserName' => 'kpmone',
	'Password' => 'p455w0rd'
);

$r = Authorize($request);

TestHasAuthority($r['Token'], $authp);

/*******************************************
 * LPMTWO
 ******************************************/

echo "</pre><h2>Authorization: lpmone</h2><pre>"; 
$authp = array();
$authp[] = array(AUTH_ADMIN,0,AUTH_ADMIN,false);
$authp[] = array(AUTH_KINGDOM,1,AUTH_EDIT,false);
$authp[] = array(AUTH_KINGDOM,1,AUTH_CREATE,false);
$authp[] = array(AUTH_KINGDOM,2,AUTH_EDIT,false);
$authp[] = array(AUTH_KINGDOM,2,AUTH_CREATE,false);
$authp[] = array(AUTH_PARK,0,AUTH_EDIT,false);
$authp[] = array(AUTH_PARK,0,AUTH_CREATE,false);
$authp[] = array(AUTH_PARK,2,AUTH_EDIT,false);
$authp[] = array(AUTH_PARK,2,AUTH_CREATE,false);
$authp[] = array(AUTH_PARK,3,AUTH_EDIT,true);
$authp[] = array(AUTH_PARK,3,AUTH_CREATE,true);
$authp[] = array(AUTH_EVENT,1,AUTH_EDIT,false);
$authp[] = array(AUTH_EVENT,1,AUTH_CREATE,false);
$authp[] = array(AUTH_EVENT,2,AUTH_EDIT,false);
$authp[] = array(AUTH_EVENT,2,AUTH_CREATE,false);
$authp[] = array(AUTH_EVENT,3,AUTH_EDIT,true);
$authp[] = array(AUTH_EVENT,3,AUTH_CREATE,true);
$authp[] = array(AUTH_UNIT,1,AUTH_EDIT,false);
$authp[] = array(AUTH_UNIT,1,AUTH_CREATE,false);

$request = array (
	'UserName' => 'lpmtwo',
	'Password' => 'p455w0rd'
);

$r = Authorize($request);

TestHasAuthority($r['Token'], $authp);

/*******************************************
 * MUNDANETHREE
 ******************************************/

echo "</pre><h2>Authorization: mundanethree</h2><pre>"; 
$authp = array();
$authp[] = array(AUTH_ADMIN,0,AUTH_ADMIN,false);
$authp[] = array(AUTH_KINGDOM,1,AUTH_EDIT,false);
$authp[] = array(AUTH_KINGDOM,1,AUTH_CREATE,false);
$authp[] = array(AUTH_KINGDOM,2,AUTH_EDIT,false);
$authp[] = array(AUTH_KINGDOM,2,AUTH_CREATE,false);
$authp[] = array(AUTH_PARK,0,AUTH_EDIT,false);
$authp[] = array(AUTH_PARK,0,AUTH_CREATE,false);
$authp[] = array(AUTH_PARK,2,AUTH_EDIT,false);
$authp[] = array(AUTH_PARK,2,AUTH_CREATE,false);
$authp[] = array(AUTH_PARK,3,AUTH_EDIT,false);
$authp[] = array(AUTH_PARK,3,AUTH_CREATE,false);
$authp[] = array(AUTH_EVENT,1,AUTH_EDIT,false);
$authp[] = array(AUTH_EVENT,1,AUTH_CREATE,false);
$authp[] = array(AUTH_EVENT,2,AUTH_EDIT,false);
$authp[] = array(AUTH_EVENT,2,AUTH_CREATE,false);
$authp[] = array(AUTH_EVENT,3,AUTH_EDIT,false);
$authp[] = array(AUTH_EVENT,3,AUTH_CREATE,false);
$authp[] = array(AUTH_EVENT,4,AUTH_EDIT,true);
$authp[] = array(AUTH_EVENT,4,AUTH_CREATE,true);
$authp[] = array(AUTH_UNIT,1,AUTH_EDIT,true);
$authp[] = array(AUTH_UNIT,1,AUTH_CREATE,true);

$request = array (
	'UserName' => 'mundanethree',
	'Password' => 'p455w0rd'
);

$r = Authorize($request);

TestHasAuthority($r['Token'], $authp);

/*******************************************
 * Test Add/Remove Authorizations
 ******************************************/


/*******************************************
 * NO USER
 ******************************************/
 
echo "</pre><h2>Add/Remove Authorization: No User</h2><pre>"; 
$addauths = array();
$addauths[] = array( AUTH_ADMIN, 0, 10, AUTH_ADMIN, false );
$addauths[] = array( AUTH_KINGDOM, 1, 10, AUTH_CREATE, false );
$addauths[] = array( AUTH_PARK, 1, 10, AUTH_CREATE, false );
$addauths[] = array( AUTH_KINGDOM, 2, 10, AUTH_CREATE, false );
$addauths[] = array( AUTH_PARK, 3, 10, AUTH_CREATE, false );
$addauths[] = array( AUTH_EVENT, 1, 10, AUTH_CREATE, false );
$addauths[] = array( AUTH_EVENT, 3, 10, AUTH_CREATE, false );
$addauths[] = array( AUTH_EVENT, 4, 10, AUTH_CREATE, false );
$addauths[] = array( AUTH_EVENT, 5, 10, AUTH_CREATE, false );
$addauths[] = array( AUTH_UNIT, 1, 10, AUTH_CREATE, false );

TestAddRemAuthorization('', $addauths);

/*******************************************
 * ADMIN
 ******************************************/
 
echo "</pre><h2>Add/Remove Authorization: Admin</h2><pre>"; 
$addauths = array();
$addauths[] = array( AUTH_ADMIN, 0, 10, AUTH_CREATE, true );
$addauths[] = array( AUTH_KINGDOM, 1, 10, AUTH_CREATE, true );
$addauths[] = array( AUTH_PARK, 1, 10, AUTH_CREATE, true );
$addauths[] = array( AUTH_UNIT, 1, 10, AUTH_CREATE, true );
$addauths[] = array( AUTH_EVENT, 1, 10, AUTH_CREATE, true );
$addauths[] = array( AUTH_ADMIN, 0, 10, AUTH_EDIT, true );
$addauths[] = array( AUTH_KINGDOM, 1, 10, AUTH_EDIT, true );
$addauths[] = array( AUTH_PARK, 1, 10, AUTH_EDIT, true );
$addauths[] = array( AUTH_UNIT, 1, 10, AUTH_EDIT, true );
$addauths[] = array( AUTH_EVENT, 1, 10, AUTH_EDIT, true );

$request = array (
	'UserName' => 'admin',
	'Password' => 'p455w0rd'
);

$r = Authorize($request);

TestAddRemAuthorization($r['Token'], $addauths);

/*******************************************
 * KPMONE
 ******************************************/
 
echo "</pre><h2>Add/Remove Authorization: kpmone</h2><pre>"; 
$addauths = array();
$addauths[] = array( AUTH_ADMIN, 0, 10, AUTH_CREATE, false );
$addauths[] = array( AUTH_KINGDOM, 1, 10, AUTH_CREATE, true );
$addauths[] = array( AUTH_PARK, 1, 10, AUTH_CREATE, true );
$addauths[] = array( AUTH_KINGDOM, 2, 10, AUTH_CREATE, false );
$addauths[] = array( AUTH_PARK, 3, 10, AUTH_CREATE, false );
$addauths[] = array( AUTH_EVENT, 1, 10, AUTH_CREATE, true );
$addauths[] = array( AUTH_EVENT, 3, 10, AUTH_CREATE, false );
$addauths[] = array( AUTH_EVENT, 4, 10, AUTH_CREATE, false );
$addauths[] = array( AUTH_UNIT, 1, 10, AUTH_CREATE, false );

$request = array (
	'UserName' => 'kpmone',
	'Password' => 'p455w0rd'
);

$r = Authorize($request);

TestAddRemAuthorization($r['Token'], $addauths);

/*******************************************
 * LPMTWO
 ******************************************/
 
echo "</pre><h2>Add/Remove Authorization: lpmtwo</h2><pre>"; 
$addauths = array();
$addauths[] = array( AUTH_ADMIN, 0, 10, AUTH_CREATE, false );
$addauths[] = array( AUTH_KINGDOM, 1, 10, AUTH_CREATE, false );
$addauths[] = array( AUTH_PARK, 1, 10, AUTH_CREATE, false );
$addauths[] = array( AUTH_KINGDOM, 2, 10, AUTH_CREATE, false );
$addauths[] = array( AUTH_PARK, 3, 10, AUTH_CREATE, true );
$addauths[] = array( AUTH_EVENT, 1, 10, AUTH_CREATE, false );
$addauths[] = array( AUTH_EVENT, 3, 10, AUTH_CREATE, true );
$addauths[] = array( AUTH_EVENT, 4, 10, AUTH_CREATE, false );
$addauths[] = array( AUTH_EVENT, 5, 10, AUTH_CREATE, false );
$addauths[] = array( AUTH_UNIT, 1, 10, AUTH_CREATE, false );

$request = array (
	'UserName' => 'lpmtwo',
	'Password' => 'p455w0rd'
);

$r = Authorize($request);

TestAddRemAuthorization($r['Token'], $addauths);

/*******************************************
 * MUNDANETHREE
 ******************************************/
 
echo "</pre><h2>Add/Remove Authorization: mundanethree</h2><pre>"; 
$addauths = array();
$addauths[] = array( AUTH_ADMIN, 0, 10, AUTH_CREATE, false );
$addauths[] = array( AUTH_KINGDOM, 1, 10, AUTH_CREATE, false );
$addauths[] = array( AUTH_PARK, 1, 10, AUTH_CREATE, false );
$addauths[] = array( AUTH_KINGDOM, 2, 10, AUTH_CREATE, false );
$addauths[] = array( AUTH_PARK, 3, 10, AUTH_CREATE, false );
$addauths[] = array( AUTH_EVENT, 1, 10, AUTH_CREATE, false );
$addauths[] = array( AUTH_EVENT, 3, 10, AUTH_CREATE, false );
$addauths[] = array( AUTH_EVENT, 4, 10, AUTH_CREATE, true );
$addauths[] = array( AUTH_EVENT, 5, 10, AUTH_CREATE, false );
$addauths[] = array( AUTH_UNIT, 1, 10, AUTH_CREATE, true );

$request = array (
	'UserName' => 'mundanethree',
	'Password' => 'p455w0rd'
);

$r = Authorize($request);

TestAddRemAuthorization($r['Token'], $addauths);
function TestAddRemAuthorization($token, $auths) {
	global $TESTS;
	$test = 1;
	
	$A = new Authorization(); 
	
	$mundane_id = $A->IsAuthorized($token);
	
	foreach ($auths as $k => $authadd) {
		$r = AddAuthorization( array( 'Token' => $token, 'Type' => $authadd[0], 'Id' => $authadd[1], 'MundaneId' => $authadd[2], 'Role' => $authadd[3] ) );
		if (($r['Status'] == 0) == $authadd[4]) {
			echo "$TESTS.$test Passed AddAuthorization ( array( 'Token' => $token, 'Type' => $authadd[0], 'Id' => $authadd[1], 'MundaneId' => $authadd[2], 'Role' => $authadd[3] ) ) : $r[Detail]\n";
			if ($authadd[4]) {
				if ($r['Detail'] > 0) {
					$rm = RemoveAuthorization( array( 'Token' => $token, 'AuthorizationId' => $r['Detail'] ) );
					if (($r['Status'] == 0) == $authadd[4]) {
						echo "$TESTS.$test Passed RemoveAuthorization ( array( 'Token' => $token, 'AuthorizationId' => $r[Detail] ) )\n";
					} else {
						print_r($r);
						print_r($rm);
						die("$TESTS.$test Failed RemoveAuthorization 1: ( array( 'Token' => $token, 'AuthorizationId' => $r[Detail] ) ) $r[Error] $r[Detail]\n");
					}
				} else {
					print_r($r);
					die("$TESTS.$test Failed AddAuthorization 2: ( array( 'Token' => $token, 'Type' => $authadd[0], 'Id' => $authadd[1], 'MundaneId' => $authadd[2], 'Role' => $authadd[3] ) ) $r[Error] $r[Detail]\n");
				}
			}
		} else {
			print_r($r);
			die("$TESTS.$test Failed AddAuthorization 3: ( array( 'Token' => $token, 'Type' => $authadd[0], 'Id' => $authadd[1], 'MundaneId' => $authadd[2], 'Role' => $authadd[3] ) ) $r[Status] $r[Error] $r[Detail]\n");
		}
		$TESTS++;
		$test++;
	}
}

function TestHasAuthority($token, $auths) {
	global $TESTS;
	$test = 1;
	
	$A = new Authorization();

	$mundane_id = $A->IsAuthorized($token);
	
	print_r($A->GetAuthorizations($mundane_id));
	
	foreach ($auths as $k => $params) {
		if ($A->HasAuthority($mundane_id, $params[0], $params[1], $params[2]) == $params[3]) {
			echo "$TESTS.$test Passed HasAuthority params: ($mundane_id, $params[0], $params[1], $params[2]) == $params[3]\n";
		} else {
			die("$TESTS.$test Failed HasAuthority params: ($mundane_id, $params[0], $params[1], $params[2]) == $params[3]\n");
		}
		$TESTS++;
		$test++;
	}
	echo "\n\n";
}

?>
</pre>
<h1>All tests complete.</h1>