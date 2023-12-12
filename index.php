<?php

if (getenv('ENVIRONMENT') == 'DEV') {
	include_once('config.dev.php');
	header("HTTP/1.1 302 Moved Temporarily");
	header("Location: https://ork.amtgard.com/orkui");
} else {
	include_once('config.php');
	header("HTTP/1.1 302 Moved Temporarily");
	header("Location: https:" . HTTP_UI);
}

?>
