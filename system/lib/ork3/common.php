<?php

/***

Common 

Being a record of common shit.  Including the Common Class, which is mostly just a convenience
	wrapper for the Config table.  Why?  Because every codebase should have *some* mystery --
	even for the author.
	
	-- J.R.R. Fuckien
	
This is the unprotected sex class.  There is probably no authority checking in these classes.
	You must do that on your own time.

***/

define('CFG_SERVICE','Service');
define('CFG_APP','Application');
define('CFG_KINGDOM','Kingdom');
define('CFG_PARK','Park');
define('CFG_EVENT','Event');
define('CFG_TOURNAMENT','Tournament');

define('CFG_ADD','Add');
define('CFG_REMOVE','Remove');
define('CFG_EDIT','Edit');

function html_encode($string) {
    return htmlentities($string, ENT_QUOTES | ENT_HTML5, "ISO-8859-1", false);
}

function html_decode($string) {
    return html_entity_decode ($string, ENT_QUOTES | ENT_HTML5, "ISO-8859-1");
}

function trimlen( $text ) {
	return strlen(trim( $text )) > 0;
}

function valid_id( $id ) {
	return is_numeric($id) && $id > 0;
}

function push_stack($a, $e) {
		array_push($a, $e);
		return $a;
}

function strip_tags_r($val) {
	return is_array($val) ?
		array_map('strip_tags_r', $val) :
		strip_tags($val);
}
	
// Encode a string to URL-safe base64
function encodeBase64UrlSafe($value)
{
  return str_replace(array('+', '/'), array('-', '_'),
    base64_encode($value));
}

// Decode a string from URL-safe base64
function decodeBase64UrlSafe($value)
{
  return base64_decode(str_replace(array('-', '_'), array('+', '/'),
    $value));
}

// Sign a URL with a given crypto key
// Note that this URL must be properly URL-encoded
function signUrl($myUrlToSign, $privateKey)
{
  return $myUrlToSign;
  // parse the url
  $url = parse_url($myUrlToSign);

  $urlPartToSign = $url['path'] . "?" . $url['query'];

  // Decode the private key into its binary format
  $decodedKey = decodeBase64UrlSafe($privateKey);

  // Create a signature using the private key and the URL-encoded
  // string using HMAC SHA1. This signature will be binary.
  $signature = hash_hmac("sha1",$urlPartToSign, $decodedKey,  true);

  $encodedSignature = encodeBase64UrlSafe($signature);

  return $myUrlToSign."&signature=".$encodedSignature;
}

class Common {

	public function __construct() {
		global $DB;
		global $LOG;
		$this->log = $LOG;
		$this->db = $DB;
		$this->config = new yapo($this->db, DB_PREFIX . 'configuration');
		$this->officer = new yapo($this->db, DB_PREFIX . 'officer');
		$this->authorization = new yapo($this->db, DB_PREFIX . 'authorization');
	}
	
	public static function Geocode($address, $city, $state, $postal_code, $geocode = null) {
        logtrace("Geocode", array($address, $city, $state, $postal_code, $geocode));
        if (strlen($geocode) > 0 ) {
        	$latlng = urlencode(str_replace(' ', '', $geocode));
    		$geocodeURL = signUrl("http://maps.googleapis.com/maps/api/geocode/json?latlng=$latlng&sensor=false", GOOGLE_MAPS_API_KEY);
        } else {
        	$address = urlencode($address . ', ' . $city . ', ' . $state . ', ' . $postal_code);
    		$geocodeURL = signUrl("http://maps.googleapis.com/maps/api/geocode/json?address=$address&sensor=false", GOOGLE_MAPS_API_KEY);
        }
		$ch = curl_init($geocodeURL);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		$result = curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		$details = array();
		logtrace("Geocode: Processing.", null);
		if ($httpCode == 200) {
			$geocode = json_decode($result);
			logtrace("Geocode: Processing.", $geocode);
			$lat = $geocode->results[0]->geometry->location->lat;
			$lng = $geocode->results[0]->geometry->location->lng; 
			$formatted_address = $geocode->results[0]->formatted_address;
			$details['Address'] = $formatted_address;
			$geo_status = $geocode->status;
			$location_type = $geocode->results[0]->geometry->location_type;
			$details['Geocode'] = $result;
			$details['Location'] = json_encode($geocode->results[0]->geometry);
			if (is_array($geocode->results[0]->address_components)) {
				foreach ($geocode->results[0]->address_components as $k => $component) {
					switch ($component->types[0]) {
						case 'locality':
							if ($component->types[1] == 'political') 
								$details['City'] = $component->long_name;
							break;
						case 'administrative_area_level_1':
							if ($component->types[1] == 'political') 
								$details['Province'] = $component->long_name;
							break;
						case 'postal_code':
								$details['PostalCode'] = $component->long_name;
							break;
					}
				}
			}
			logtrace("Geocode: Details.", $details);
			return $details;
		} else {
			logtrace("Geocode: failed.", array());
			return false;
		}
	}
	
	public static function replace_links( $text ) {   
		 
		return preg_replace('@(https?://([-\w\.]+[-\w])+(:\d+)?(/([\w/_\.#-]*(\?\S+)?[^\.\s])?)?)@', '<a href="$1" target="_blank">$1</a>', $text );
 
	}

	public static function url_exists($url) {
		// Version 4.x supported
		$handle   = curl_init($url);
		if (false === $handle)
		{
			return false;
		}
		curl_setopt($handle, CURLOPT_HEADER, false);
		curl_setopt($handle, CURLOPT_FAILONERROR, true);  // this works
		curl_setopt($handle, CURLOPT_HTTPHEADER, Array("User-Agent: Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1.15) Gecko/20080623 Firefox/2.0.0.15") ); // request as if Firefox    
		curl_setopt($handle, CURLOPT_NOBODY, true);
		curl_setopt($handle, CURLOPT_RETURNTRANSFER, false);
		$connectable = curl_exec($handle);
		curl_close($handle);   
		return $connectable;
	}

	public static function exif_to_mime($type, $filename = null) {
		switch($type) {
			case IMAGETYPE_GIF: return 'image/gif';
			case IMAGETYPE_JPEG: return 'image/jpeg';
			case IMAGETYPE_PNG: return 'image/png';
		}
        if (!is_null($filename)) {
            $pi = pathinfo($filename);
            switch (strtoupper($pi['extension'])) {
        		case 'GIF': return 'image/gif';
        		case 'JPEG':
    			case 'JPG': return 'image/jpeg';
    			case 'PNG': return 'image/png';
            }
        }
        return 'image/fuckyou';
	}

	public static function is_pdf_mime_type($type) {
		switch (strtoupper($type)) {
			case 'APPLICATION/PDF': 
			case 'APPLICATION/X-PDF': return true;
		}
		return false;
	}
	
	public static function supported_mime_types($type) {
		switch (strtoupper($type)) {
			case 'IMAGE/JPEG': 
			case 'IMAGE/GIF': 
			case 'IMAGE/PNG': return true;
			case 'APPLICATION/PDF': 
			case 'APPLICATION/X-PDF': return true;
		}
		return false;
	}
	
	public static function make_safe_html($text) {
        /*
	    $text = preg_replace( 
        array( 
          // Remove invisible content 
            '@<head[^>]*?>.*?</head>@siu', 
            '@<style[^>]*?>.*?</style>@siu', 
            '@<script[^>]*?.*?</script>@siu', 
            '@<object[^>]*?.*?</object>@siu', 
            '@<embed[^>]*?.*?</embed>@siu', 
            '@<applet[^>]*?.*?</applet>@siu', 
            '@<noframes[^>]*?.*?</noframes>@siu', 
            '@<noscript[^>]*?.*?</noscript>@siu', 
            '@<noembed[^>]*?.*?</noembed>@siu', 
          // Add line breaks before and after blocks 
            '@</?((address)|(blockquote)|(center)|(del))@iu', 
            '@</?((div)|(h[1-9])|(ins)|(isindex)|(p)|(pre))@iu', 
            '@</?((dir)|(dl)|(dt)|(dd)|(li)|(menu)|(ol)|(ul))@iu', 
            '@</?((table)|(th)|(td)|(caption))@iu', 
            '@</?((form)|(button)|(fieldset)|(legend)|(input))@iu', 
            '@</?((label)|(select)|(optgroup)|(option)|(textarea))@iu', 
            '@</?((frameset)|(frame)|(iframe))@iu', 
        ), 
        array( 
            ' ', ' ', ' ', ' ', ' ', ' ', ' ', ' ', ' ',"$0", "$0", "$0", "$0", "$0", "$0","$0", "$0",), $text ); 
      echo $text;
      */
		$tags = '<p><br><h1><h2><h3><h4><li><ol><ul><b><i><blockquote>';
		$text = Common::replace_links( Common::strip_attributes( strip_tags( $text , $tags ), $tags ) );
        return $text;
	}
	
	public static function strip_attributes($text, $tags){
		
		preg_match_all("/<([^>]+)>/i",$tags,$allTags,PREG_PATTERN_ORDER);

		foreach ($allTags[1] as $tag){
			$text = preg_replace("/<".$tag."[^>]*>/i","<".$tag.">",$text);
		}

		return $text;
	}

	
	public function create_events($kingdom_id, $park_id) {
		$this->event = new yapo($this->db, DB_PREFIX . 'event');
		$this->create_event($kingdom_id, $park_id, 'Summer Crown Qualifications');
		$this->create_event($kingdom_id, $park_id, 'Summer Coronation');
		$this->create_event($kingdom_id, $park_id, 'Summer Weaponmaster');
		$this->create_event($kingdom_id, $park_id, 'Summer Dragonmaster');
		$this->create_event($kingdom_id, $park_id, 'Summer Relic Quest');
		$this->create_event($kingdom_id, $park_id, 'Summer Collegium');
		$this->create_event($kingdom_id, $park_id, 'Summer Midreign');
		
		$this->create_event($kingdom_id, $park_id, 'Winter Crown Qualifications');
		$this->create_event($kingdom_id, $park_id, 'Winter Coronation');
		$this->create_event($kingdom_id, $park_id, 'Winter Weaponmaster');
		$this->create_event($kingdom_id, $park_id, 'Winter Dragonmaster');
		$this->create_event($kingdom_id, $park_id, 'Winter Relic Quest');
		$this->create_event($kingdom_id, $park_id, 'Winter Collegium');
		$this->create_event($kingdom_id, $park_id, 'Winter Midreign');
	}
	
	public function create_event($kingdom_id, $park_id, $name) {
		$this->event->clear();
		$this->event->kingdom_id = $kingdom_id;
		$this->event->park_id = $park_id;
		$this->event->name = $name;
		$this->event->modified = date('Y-m-d H:i:s');
		$this->event->save();
	}
	
	public function create_park_titles($kingdom_id) {
		$this->parktitle = new yapo($this->db, DB_PREFIX . 'parktitle');
		$titles = array(
				array( 'Outpost',		10,	5,	1,	'month',	6 ),
				array( 'Shire',			20,	5,	1,	'month',	6 ),
				array( 'Barony',		30,	15,	13,	'month',	6 ),
				array( 'Duchy',			40,	30,	28,	'month',	6 ),
				array( 'Grand Duchy',	50,	60,	56,	'month',	6 ),
			);
		foreach ($titles as $t => $detail) {
			$this->create_park_title($kingdom_id, $detail);
		}
	}
	
	public function create_park_title($kingdom_id, $detail) {
		$this->parktitle->clear();
		$this->parktitle->kingdom_id = $kingdom_id;
		$this->parktitle->title = $detail[0];
		$this->parktitle->class = $detail[1];
		$this->parktitle->minimumattendance = $detail[2];
		$this->parktitle->minimumcutoff = $detail[3];
		$this->parktitle->period = $detail[4];
		$this->parktitle->period_length = $detail[5];
		$this->parktitle->save();
	}
	
	public function set_officer($kingdom_id, $park_id, $new_officer_id, $role, $system=0) {
		$this->officer->clear();
		$this->officer->kingdom_id = $kingdom_id;
		$this->officer->park_id = $park_id;
		$this->officer->role = $role;
		$this->officer->system = $system;
		$this->authorization->clear();
		if ($this->officer->find()) {
			if ('Champion' == $role) {
				$this->officer->mundane_id = $new_officer_id;
				$this->officer->modified = time();
				$this->officer->save();
			} else {
				$this->authorization->clear();
				$this->authorization->authorization_id = $this->officer->authorization_id;
				if ($this->authorization->find()) {
					$this->officer->mundane_id = $new_officer_id;
					$this->authorization->mundane_id = $new_officer_id;
					$this->officer->modified = time();
					$this->officer->save();
					$this->authorization->save();
				}
			}
		}
	}
	
	public function create_officers($kingdom_id, $park_id, $principality_id = 0) {
		$this->create_officer($kingdom_id, $park_id, 'Monarch', 'create');
		$this->create_officer($kingdom_id, $park_id, 'Regent', 'create');
		$this->create_officer($kingdom_id, $park_id, 'Prime Minister', 'create');
		$this->create_officer($kingdom_id, $park_id, 'Champion', null);
		if (valid_id($for_principality)) {
			$this->create_officer($kingdom_id, $park_id, 'Monarch', 'create', 1, $principality_id);
			$this->create_officer($kingdom_id, $park_id, 'Regent', 'create', 1, $principality_id);
			$this->create_officer($kingdom_id, $park_id, 'Prime Minister', 'create', 1, $principality_id);
			$this->create_officer($kingdom_id, $park_id, 'Champion', null, 1, $principality_id);
		}
	}

	private function create_officer($kingdom_id, $park_id, $role, $authorization, $system=0, $principality_id = 0) {
		$this->officer->clear();
		$this->officer->kingdom_id = $kingdom_id;
		$this->officer->park_id = $park_id;
		$this->officer->role = $role;
		$this->officer->system = $system;
		$this->officer->modified = time();
		if (strlen($authorization) > 0) {
			$A = new Authorization();
			$r = $A->add_auth_h(array (
					'MundaneId' => 0, 
					'Type' => $park_id>0?'Park':'Kingdom', 
					'Role' => $authorization,
					'Id' => $park_id==0?$kingdom_id:$park_id
				));
			if ($r['Status'] == 0) {
				$this->officer->authorization_id = $r['Detail'];
			}
		}
		$this->officer->save();
	}
	
	public static function get_configs($id, $type = CFG_KINGDOM) {
		global $DB;
		$config = new yapo($DB, DB_PREFIX . 'configuration');
		$config->clear();
		$config->type = $type;
		$config->id = $id;
		$response = array();
		if ($config->find()) {
			do {
				$response[$config->key] = array(
						'ConfigurationId' => $config->configuration_id,
    					'Type' => $config->var_type,
						'Key' => $config->key,
						'Value' => json_decode(stripslashes($config->value)),
						'UserSetting' => $config->user_setting,
						'AllowedValues' => json_decode(stripslashes($config->allowed_values))
					);
			} while ($config->next());
		}
		return $response;
	}
	
	public function add_config($requester_id, $type, $var_type, $id, $key, $value, $user_setting=1, $allowed_values=null) {
		$this->log->Write('Configuration', $requester_id, LOG_ADD, array($type, $id, $key, $value));
		$this->config->clear();
		$this->config->type = $type;
    	$this->config->var_type = $type;
		$this->config->id = $id;
		$this->config->key = $key;
		$this->config->value = json_encode($value);
		$this->config->user_setting = $user_setting?1:0;
		$this->config->allowed_values = json_encode($allowed_values);
		$this->config->modified = date("Y-m-d H:i:s", time());
		$this->config->save();
	}
	
	public function remove_config($requester_id, $config_id, $type, $id, $key) {
		$this->log->Write('Configuration', $requester_id, LOG_REMOVE, $config_id);
		$this->config->clear();
		$this->config->configuration_id = $config_id;
		/* Why, because I like you!  If the caller is careful, we don't have to perform
		 *	another layer of authentication here ... just hard code the caller's authority
		 *	context via the appropriate $type, $id, and $key, and we won't be susceptible to cross-calling on configs
		 * I mean, you didn't let the user specify ALL of this, did you?  Right?  You did the right
		 *	thing and looked it up based on context?
		 * I bet you didn't.  Christ, I can only do so much.
		 */
		$this->config->type = $type;
		$this->config->id = $id;
		$this->config->key = $key;
		if ($this->config->find()) $this->config->delete();
	}
	
	public function update_config($requester_id, $config_id, $type, $id, $key, $value) {
		$this->log->Write('Configuration', $requester_id, LOG_EDIT, array($config_id, $type, $id, $key, $value));
		$this->config->clear();
		$this->config->configuration_id = $config_id;
		// Ditto, above
		$this->config->type = strlen($type)>0?$type:$this->config->type;
		$this->config->id = strlen($id)>0?$id:$this->config->id;
		$this->config->key = strlen($key)>0?$key:$this->config->key;
		if ($this->config->find()) {
			if ($value != null) {
				$allowed = json_decode($this->config->allowed_values);
				if (is_array($allowed)) {
					$allow = true;
					foreach ($value as $v_key => $v_value) {
						foreach ($allowed as $a_key => $a_value) {
							if ($a_key == $v_key) {
								$allow = false;
								foreach ($v_key as $k => $allowance) {
									if ($allowance == $v_value) $allow = true;
								}
							}
							if (!$allow) return false;
						}
					}
				}
				$this->config->value = json_encode($value);
			}
			$this->config->modified = date("Y-m-d H:i:s", time());
			$this->config->save();
		}
	}

}

//http://www.codingforums.com/archive/index.php/t-180473.html
class shortScale {
	// Source: Wikipedia (http://en.wikipedia.org/wiki/Names_of_large_numbers)
	private static $scale = array('', 'thousand', 'million', 'billion', 'trillion', 'quadrillion', 'quintillion', 'sextillion', 'octillion', 'nonillion', 'decillion', 'undecillion', 'duodecillion', 'tredecillion', 'quattuordecillion', 'quindecillion', 'sexdecillion', 'septendecillion', 'octodecillion', 'noverndecillion', 'vigintillion');
	private static $digit = array('', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen');
	private static $digith = array('', 'first', 'second', 'third', 'fourth', 'fifth', 'sixth', 'seventh', 'eighth', 'ninth', 'tenth', 'eleventh', 'twelfth', 'thirteenth', 'fourteenth', 'fiftheenth', 'sixteenth', 'seventeenth', 'eighteenth', 'nineteenth');
	private static $ten = array('', '', 'twenty', 'thirty', 'fourty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety');
	private static $tenth = array('', '', 'twentieth', 'thirtieth', 'fortieth', 'fiftieth', 'sixtieth', 'seventieth', 'eightieth', 'ninetieth');

	private static function floatToArray($number, &$int, &$frac) {
		// Forced $number as (string), effectively to avoid (float) inprecision
		@list(, $frac) = explode('.', $number);
		if ($frac || !is_numeric($number) || (strlen($number) > 60)) throw new Exception('Not a number or not a supported number type');
		// $int = explode(',', number_format(ltrim($number, '0'), 0, '', ',')); -- Buggy
		$int = str_split(str_pad($number, ceil(strlen($number)/3)*3, '0', STR_PAD_LEFT), 3);
	}

	/* in retrospect ... this function was pretty easy */
	public static function toDigith($number) {
		if ($number < 20) {
			return $number . substr(self::$digith[$number],-2);
		} else {
			self::floatToArray($number, $int, $frac);
			return $number . substr(self::$digith[substr($number,-1)],-2);
		}
	}
	
	private static function thousandToEnglish($number) {
		// Gets numbers from 0 to 999 and returns the cardinal English
		$hundreds = floor($number / 100);
		$tens = $number % 100;
		$pre = ($hundreds ? self::$digit[$hundreds].' hundred' : '');
		if ($tens < 20)
			$post = self::$digit[$tens];
		else
			$post = trim(self::$ten[floor($tens / 10)].' '.self::$digit[$tens % 10]);
		if ($pre && $post) return $pre.' and '.$post;
		return $pre.$post;
	}

	private static function cardinalToOrdinal($cardinal) {
		// Finds the last word in the cardinal arrays and replaces it with
		// the entry from the ordinal arrays, or appends "th"
		$words = explode(' ', $cardinal);
		$last = &$words[count($words)-1];
		if (in_array($last, self::$digit)) {
			$last = self::$digith[array_search($last, self::$digit)];
		} elseif (in_array($last, self::$ten)) {
			$last = self::$tenth[array_search($last, self::$ten)];
		} elseif (substr($last, -2) != 'th') {
			$last .= 'th';
		}
		return implode(' ', $words);
	}

	public static function toOrdinal($number) {
		// Converts a xth format number to English. e.g. 22nd to twenty-second.
		return trim(self::cardinalToOrdinal(self::toCardinal($number)));
	}

	public static function toCardinal($number) {
		// Converts a number to English. e.g. 22 to twenty-two.
		self::floatToArray($number, $int, $frac);
		$int = array_reverse($int);
		$english = array();
		for($i=count($int)-1; $i>-1; $i--) {
			$englishnumber = self::thousandToEnglish($int[$i]);
			if ($englishnumber) 
				$english[] = $englishnumber.' '.self::$scale[$i];
		}
		$post = array_pop($english);
		$pre = implode(', ', $english);
		if ($pre && $post) return trim($pre.' and '.$post);
		return trim($pre.$post);
	}
}


?>