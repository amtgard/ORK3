<?php

/*******************************************************************************

	TPO Parts Barcode Service
	
	Given an input string, returns a barcode

*******************************************************************************/

	require_once("../svcutil.php");

	$namespace = HTTP_SERVICE.'BarcodeService.wsdl';
	$server = new soap_server();
	$server->debug_flag = false;
	$server->configureWSDL('BarcodeService', $namespace);
	$server->wsdl->schemaTargetNamespace = $namespace;
	

/*
 *
 *  BagOrderItem service definitions
 *
 */
 
$server->wsdl->addComplexType(
		'BarcodeRequest',
		'complextType',
		'struct',
		'all',
		'',
		array(
				'SecureToken'=>array('name'=>'SecureToken','type'=>'xsd:string'),
				'BarcodeText'=>array('name'=>'BarcodeText','type'=>'xsd:string'),
				'Type'=>array('name'=>'Type','type'=>'xsd:string')
			)
	);

$server->wsdl->addComplexType(
		'BarcodeResponse',
		'complextType',
		'struct',
		'all',
		'',
		array(
				'StatusId'=>array('name'=>'StatusId','type'=>'xsd:int'),
				'ErrorMessage'=>array('name'=>'ErrorMessage','type'=>'xsd:string'),
				'Barcode'=>array('name'=>'Barcode','type'=>'xsd:string')
			)
	);

$server->register(
		'Barcode',
		array('BarcodeRequest'=>'tns:BarcodeRequest'),
		array('return' => 'tns:BarcodeResponse'),
		$namespace
	);

function Barcode($BarcodeRequest) {
	$COMMAND = 'BARCODE';
	switch ($BarcodeRequest['Type']) {
		case 'BARCODE': $COMMAND='BARCODE'; break;
		case 'QRCODE':  $COMMAND='QRCODE'; break;
		default:  $COMMAND='QRCODE'; break;
	}
	$url = HTTPS_SERVER."tools/b4ckoffice/svc/BarcodeService.php?$COMMAND=".$BarcodeRequest['BarcodeText'];
	$url = str_replace("http:/","http://",$url);

	$ch = curl_init();
	$timeout = 0;
	curl_setopt ($ch, CURLOPT_URL, $url);
	curl_setopt ($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
	
	// Getting binary data
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($ch, CURLOPT_BINARYTRANSFER, 1);
	
	$image = curl_exec($ch);
	curl_close($ch);
	// output to browser
	
	$base64image = base64_encode($image);
	
	$BarcodeResponse = array (
			'StatusId' => 0,
			'ErrorMessage' => $BarcodeRequest['BarcodeText'],
			'Barcode' => $base64image
		);
	
	return $BarcodeResponse;
}

if (isset($_REQUEST['TEST'])) {
	$url = HTTPS_SERVER.'tools/b4ckoffice/svc/BarcodeService.php?BARCODE='.$_REQUEST['BARCODE'];
	$url = str_replace("http:/","http://",$url);

	$ch = curl_init();
	$timeout = 0;
	curl_setopt ($ch, CURLOPT_URL, $url);
	curl_setopt ($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
	
	// Getting binary data
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($ch, CURLOPT_BINARYTRANSFER, 1);
	
	$image = curl_exec($ch);
	curl_close($ch);
	// output to browser
	
	//echo base64_encode($image);

	$im = "iVBORw0KGgoAAAANSUhEUgAAAhwAAABuCAIAAADj6V3IAAAFi0lEQVR4nO3dP2hTXR/A8dOXILUoloIOWgKKOmQSB5EOUnH2Dx1KB+moKCLSqahjhSIigquDOHRwUBFHUeggQQS1dHAITqG12kFFg0Mwz3AhlPa1z/Xt77nJ6/P5TPHek3vO0XK/KWljT6vVSgAQ4T+dXgAAfw5RASCMqAAQRlQACCMqAIQRFQDCiAoAYUQFgDCiAkAYUQEgjKgAEKbU6QWsp6enZ9WR9ieVtU+t/eyydU791hTrDP6tMXmWutbap6+zwu6fYp3BXfsXtcFldMku/tQp1hm8wX+LDV4nz83ht+b6rSm6ge9UAAgjKgCEERUAwogKAGFEBYAwogJAGFEBIIyoABBGVAAIIyoAhBEVAMKICgBhRAWAMKICQBhRASCMqAAQRlQACCMqAIQRFQDCiAoAYUQFgDCiAkAYUQEgjKgAEEZUAAgjKgCEERUAwogKAGFEBYAwogJAGFEBIIyoABBGVAAIIyoAhBEVAMKICgBhRAWAMKICQBhRASCMqAAQRlQACCMqAIQRFQDCiAoAYUQFgDCiAkAYUQEgjKgAEEZUAAgjKgCEERUAwogKAGFEBYAwogJAGFEBIIyoABBGVAAIIyoAhBEVAMKICgBhRAWAMKICQBhRASCMqAAQRlQACCMqAIQRFQDCiAoAYUQFgDCiAkAYUQEgjKgAEEZUAAgjKgCEERUAwvS0Wq1OrwGAP4TvVAAIIyoAhBEVAMKICgBhRAWAMKVOL4B/r7dv31ar1f966uzZsyv/uLS0dP/+/Xfv3pVKpYMHD46Ojm7evHnVU/KMSSl9+fLl7t273759GxoaOnr06NoBOa/zt6LWHLUvKEgLOmR6evpXX5bNZrM9bGZmZsuWLSvPlsvl+fn5lZfKM6bRaFy/fn1gYCAbMDk5uXZJea6TR9Sao/YFhREVOubx48djY2NjY2OVSiW7IY6MjGRH2mNevHhRKpVSSpVK5caNGxMTE729vSmlPXv2NBqN/GPu3bu3c+fOlXfntTffPNfJI2rNUfuCIokKnXf16tXshvj58+dVp4aHh7OX5+1TMzMz2eA7d+7kHzM5OZlS2rFjx82bN391881znTyi1hy1LyiSN+rpXouLi7OzsymlCxcubNu2LTtYqVSy1+8PHjzIOSaldObMmcuXL9dqtfPnz//PcxW55qh9QcFEhe716tWrnz9/ppSy1+wppdu3bx8+fLjZbKaU5ubmco5JKe3evfvatWtbt27dyFxFrjlqX1AwUaF7LSwsZA/K5fKnT5+OHz9+8eLFHz9+ZO8ifPz4MeeYqLmKXHPUeqBgokL3ajQa2YPZ2dkDBw48efJkYGDg0aNH4+PjKaXshXyeMVFzFbnmqPVAwUSF7tXX15c9GB0dXVhYGB4enpubO3ny5NevX1NK/f39OcdEzVXkmqPWAwUTFbpXuVzOHpRKpampqefPn+/atSulND8/n1Lav39/zjFRcxW55qj1QMFEhe516NCh7IedTp8+feXKlezg0tJS9nv42TvYecZEzbXSw4cPjx07du7cue/fv/8Ta47aFxSt0z/TzL9Xo9FYXl5eXl6emJjIvhrfv3+fHWmPGRkZSSn19vY+e/Yse8qJEydSSps2barVavnHfPjwoVqtVqvV7Od0U0rj4+PZkXq9nv86mXq9nt3xU0qXLl1ata+oNUftC4okKnRMno9pqdVq7fcP9u7d2348PT3dvk6eMbdu3frVXFNTU/mvk3n58mX76adOnVp1NmrNUfuCIokKHZPzs7/evHkzNDTUPjU4OLj299v/dkzOm2+euVqtVrPZPHLkSEqpr6/v6dOnaweErDlwX1AY/0c9/x8WFxfr9Xp/f/++ffs2MiZqrpTS69evBwcHt2/f/k+vOWpfUABRASDMXyOvuPQ4kiOPAAAAAElFTkSuQmCC";
	$im = "iVBORw0KGgoAAAANSUhEUgAAAhwAAABuCAIAAADj6V3IAAABrUlEQVR4nO3VwQkAIBDAMHX/nc8lCoIkE/TXPTMLAArndQAA/zAVADKmAkDGVADImAoAGVMBIGMqAGRMBYCMqQCQMRUAMqYCQMZUAMiYCgAZUwEgYyoAZEwFgIypAJAxFQAypgJAxlQAyJgKABlTASBjKgBkTAWAjKkAkDEVADKmAkDGVADImAoAGVMBIGMqAGRMBYCMqQCQMRUAMqYCQMZUAMiYCgAZUwEgYyoAZEwFgIypAJAxFQAypgJAxlQAyJgKABlTASBjKgBkTAWAjKkAkDEVADKmAkDGVADImAoAGVMBIGMqAGRMBYCMqQCQMRUAMqYCQMZUAMiYCgAZUwEgYyoAZEwFgIypAJAxFQAypgJAxlQAyJgKABlTASBjKgBkTAWAjKkAkDEVADKmAkDGVADImAoAGVMBIGMqAGRMBYCMqQCQMRUAMqYCQMZUAMiYCgAZUwEgYyoAZEwFgIypAJAxFQAypgJAxlQAyJgKABlTASBjKgBkTAWAjKkAkDEVADKmAkDGVADImAoAGVMBIGMqAGRMBYCMqQCQMRUAMqYCQMZUAMiYCgCZC/AvA9n4Jx/UAAAAAElFTkSuQmCC";
	$im = @imagecreatefromstring(base64_decode($im));
  header('Content-type: image/gif'); 
  imagegif($im); 
  imagedestroy($im); 

} else if (isset($_REQUEST['BARCODE'])) {
	require_once(DIR_LIB.'Barcode.php');
	if ($_REQUEST['TARGET'] == 'web') {
		$fontSize = 8;   // GD1 in px ; GD2 in point 
		$marge    = 5;   // between barcode and hri in pixel 
		$x        = 90;  // barcode center 
		$y        = 20;  // barcode center 
		$height   = 30;   // barcode height in 1D ; module size in 2D 
		$width    = 1;    // barcode height in 1D ; not use in 2D
		$imgw     = 180;
		$imgh     = 50;
	} else {
		$fontSize = 16;   // GD1 in px ; GD2 in point 
		$marge    = 15;   // between barcode and hri in pixel 
		$x        = 270;  // barcode center 
		$y        = 50;  // barcode center 
		$height   = 75;   // barcode height in 1D ; module size in 2D 
		$width    = 3;    // barcode height in 1D ; not use in 2D
		$imgw     = 540;
		$imgh     = 100;
  }
  $angle    = 0;   // rotation in degrees : nb : non horizontable barcode might not be usable because of pixelisation
  $font     = DIR_LIB.'CONSOLAB.TTF';
   
  $code     = strtoupper($_REQUEST['BARCODE']); // barcode, of course ;) 
  $type     = 'code128'; 
   
  // -------------------------------------------------- // 
  //            ALLOCATE GD RESSOURCE 
  // -------------------------------------------------- // 
  $im     = imagecreatetruecolor($imgw, $imgh); 
  $black  = ImageColorAllocate($im,0x00,0x00,0x00); 
  $white  = ImageColorAllocate($im,0xff,0xff,0xff); 
  $red    = ImageColorAllocate($im,0xff,0x00,0x00); 
  $blue   = ImageColorAllocate($im,0x00,0x00,0xff); 
  imagefilledrectangle($im, 0, 0, $imgw, $imgh, $white); 
   
  // -------------------------------------------------- // 
  //                      BARCODE 
  // -------------------------------------------------- // 
  $data = Barcode::gd($im, $black, $x, $y, $angle, $type, array('code'=>$code), $width, $height); 
   
  // -------------------------------------------------- // 
  //                        HRI 
  // -------------------------------------------------- // 
  if ( isset($font) ){ 
    $box = imagettfbbox($fontSize, 0, $font, $data['hri']); 
    $len = $box[2] - $box[0]; 
    Barcode::rotate(-$len / 2, ($data['height'] / 2) + $fontSize + $marge, $angle, $xt, $yt); 
    imagettftext($im, $fontSize, $angle, $x + $xt, $y + $yt, $black, $font, $data['hri']); 
  } 
  // -------------------------------------------------- // 
  //                     ROTATE 
  // -------------------------------------------------- // 
  // Beware ! the rotate function should be use only with right angle 
  // Remove the comment below to see a non right rotation 
  /** / 
  $rot = imagerotate($im, 45, $white); 
  imagedestroy($im); 
  $im     = imagecreatetruecolor(900, 300); 
  $black  = ImageColorAllocate($im,0x00,0x00,0x00); 
  $white  = ImageColorAllocate($im,0xff,0xff,0xff); 
  $red    = ImageColorAllocate($im,0xff,0x00,0x00); 
  $blue   = ImageColorAllocate($im,0x00,0x00,0xff); 
  imagefilledrectangle($im, 0, 0, 900, 300, $white); 
   
  // Barcode rotation : 90° 
  $angle = 90; 
  $data = Barcode::gd($im, $black, $x, $y, $angle, $type, array('code'=>$code), $width, $height); 
  Barcode::rotate(-$len / 2, ($data['height'] / 2) + $fontSize + $marge, $angle, $xt, $yt); 
  imagettftext($im, $fontSize, $angle, $x + $xt, $y + $yt, $blue, $font, $data['hri']); 
  imagettftext($im, 10, 0, 60, 290, $black, $font, 'BARCODE ROTATION : 90°'); 
   
  // barcode rotation : 135 
  $angle = 135; 
  Barcode::gd($im, $black, $x+300, $y, $angle, $type, array('code'=>$code), $width, $height); 
  Barcode::rotate(-$len / 2, ($data['height'] / 2) + $fontSize + $marge, $angle, $xt, $yt); 
  imagettftext($im, $fontSize, $angle, $x + 300 + $xt, $y + $yt, $blue, $font, $data['hri']); 
  imagettftext($im, 10, 0, 360, 290, $black, $font, 'BARCODE ROTATION : 135°'); 
   
  // last one : image rotation 
  imagecopy($im, $rot, 580, -50, 0, 0, 300, 300); 
  imagerectangle($im, 0, 0, 299, 299, $black); 
  imagerectangle($im, 299, 0, 599, 299, $black); 
  imagerectangle($im, 599, 0, 899, 299, $black); 
  imagettftext($im, 10, 0, 690, 290, $black, $font, 'IMAGE ROTATION'); 
  /**/ 
  
  // -------------------------------------------------- // 
  //                    MIDDLE AXE 
  // -------------------------------------------------- // 
  //imageline($im, $x, 0, $x, 250, $red); 
  //imageline($im, 0, $y, 250, $y, $red); 
   
  // -------------------------------------------------- // 
  //                  BARCODE BOUNDARIES 
  // -------------------------------------------------- // 
  //for($i=1; $i<5; $i++){ 
  //  drawCross($im, $blue, $data['p'.$i]['x'], $data['p'.$i]['y']); 
  //} 
   
  // -------------------------------------------------- // 
  //                    GENERATE 
  // -------------------------------------------------- // 
  header('Content-type: image/gif'); 
  imagepng($im); 
  imagedestroy($im); 

} else if (isset($_REQUEST['QRCODE'])) {
	require_once(DIR_LIB."phpqrcode/qrlib.php");
	QRCode::png($_REQUEST['QRCODE']);
} else {
	$HTTP_RAW_POST_DATA = isset($GLOBALS['HTTP_RAW_POST_DATA'])
		? $GLOBALS['HTTP_RAW_POST_DATA'] : '';
	$server->service($HTTP_RAW_POST_DATA);
	exit();
}
?>
