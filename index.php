<?php

if (getenv('ENVIRONMENT') == 'DEV') {
	include_once('config.dev.php');
} else {
	include_once('config.php');
	header("HTTP/1.1 302 Moved Temporarily");
	header("Location: " . HTTP_UI);
}

?>
