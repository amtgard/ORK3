<?php

$pinColor = $_REQUEST['pin'];
$pinFile = "http://chart.apis.google.com/chart?chst=d_map_pin_letter&chld=%E2%80%A2|$pinColor";
//$pinFile = "http://webpop.github.io/jquery.pin/images/pin.png";

header('Cache-Control: no-cache');
header('Pragma: no-cache');
header('Content-Type: image/png');
echo file_get_contents($pinFile);
exit;

?>