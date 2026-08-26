<?php

/***
 *
 * Common
 *
 * Being a record of common shit.  Including the Common Class, which is mostly just a convenience
 * wrapper for the Config table.  Why?  Because every codebase should have *some* mystery --
 * even for the author.
 *
 * -- J.R.R. Fuckien
 *
 * This is the unprotected sex class.  There is probably no authority checking in these classes.
 * You must do that on your own time.
 ***/

define('CFG_SERVICE', 'Service');
define('CFG_APP', 'Application');
define('CFG_KINGDOM', 'Kingdom');
define('CFG_PARK', 'Park');
define('CFG_EVENT', 'Event');
define('CFG_TOURNAMENT', 'Tournament');

define('CFG_ADD', 'Add');
define('CFG_REMOVE', 'Remove');
define('CFG_EDIT', 'Edit');

function html_encode($string)
{
    return htmlentities($string, ENT_QUOTES | ENT_HTML5, "UTF-8", false);
}

function html_decode($string)
{
    return html_entity_decode($string, ENT_QUOTES | ENT_HTML5, "UTF-8");
}

function trimlen($text)
{
    return strlen(trim($text)) > 0;
}

function valid_id($id)
{
    return is_numeric($id) && $id > 0;
}

function push_stack($a, $e)
{
    array_push($a, $e);
    return $a;
}

function strip_tags_r($val)
{
    return is_array($val) ?
        array_map('strip_tags_r', $val) :
        strip_tags($val);
}

// Encode a string to URL-safe base64
function encodeBase64UrlSafe($value)
{
    return str_replace(
        [ '+', '/' ],
        [ '-', '_' ],
        base64_encode($value)
    );
}

// Decode a string from URL-safe base64
function decodeBase64UrlSafe($value)
{
    return base64_decode(str_replace(
        [ '-', '_' ],
        [ '+', '/' ],
        $value
    ));
}

// Sign a URL with a given crypto key
// Note that this URL must be properly URL-encoded
/**
 * Render the OpenGraph <meta> block for the page head. Link-preview crawlers
 * (Discord, Facebook, Slack, iMessage) read ONLY these tags — never the page
 * body — so per-page values here are what turn a pasted ORK link into a card
 * that says what it links to instead of the site-wide generic.
 *
 * $og overrides the defaults per page: ['title' => ..., 'description' => ...,
 * 'image' => URL, 'image:width' => n, 'image:height' => n, 'url' => canonical].
 * Values are escaped here; pass raw text.
 */
function ork_og_meta_tags($og = array())
{
    $defaults = array(
        'type'         => 'website',
        'site_name'    => 'ORK 3 - Amtgard Online Record Keeper',
        'title'        => 'ORK 3 - Amtgard Online Record Keeper',
        'description'  => 'The Online Record Keeper for the Amtgard International LARP.',
        'image'        => (defined('HTTP_ASSETS') ? HTTP_ASSETS : '/assets/') . 'images/clippy_large.png',
        'image:width'  => '1075',
        'image:height' => '1075',
    );
    $og = array_merge($defaults, is_array($og) ? $og : array());
    $out = '';
    foreach ($og as $prop => $value) {
        $value = trim((string)$value);
        if ($value === '') {
            continue;
        }
        // Collapse whitespace/newlines — description text often comes from
        // user-authored fields.
        $value = preg_replace('/\s+/', ' ', $value);
        $out .= "\t\t<meta property=\"og:" . htmlspecialchars($prop, ENT_QUOTES) . '" content="'
            . htmlspecialchars($value, ENT_QUOTES) . "\">\n";
    }
    return $out;
}

/**
 * Next occurrence dates for a park-day row, mirroring the recurrence
 * predicates in Weather::parks_playing_on(). $pd uses the GetParkDays keys:
 * Recurrence, WeekDay, WeekOfMonth, MonthDay, StartDate, WeekInterval.
 * Returns up to $count 'Y-m-d' dates within $horizon_days of $from
 * (default today). Pure date math — no DB.
 */
function ork_parkday_next_occurrences($pd, $from = null, $count = 2, $horizon_days = 70)
{
    $ts = strtotime($from !== null ? (string)$from : date('Y-m-d'));
    if ($ts === false) {
        return array();
    }
    $recurrence = (string)($pd['Recurrence'] ?? '');
    $week_day   = (string)($pd['WeekDay'] ?? '');
    $wom        = (int)($pd['WeekOfMonth'] ?? 0);
    $month_day  = (int)($pd['MonthDay'] ?? 0);
    $start_date = (string)($pd['StartDate'] ?? '');
    $interval   = (int)($pd['WeekInterval'] ?? 0);

    $out = array();
    for ($i = 0; $i < $horizon_days && count($out) < $count; $i++) {
        $day = strtotime("+$i days", $ts);
        $dow = date('l', $day);
        $dom = (int)date('j', $day);
        $ymd = date('Y-m-d', $day);
        $nth = (int)ceil($dom / 7);

        $match = false;
        switch ($recurrence) {
            case 'weekly':
                $match = ($dow === $week_day);
                break;
            case 'week-of-month':
                $match = ($dow === $week_day && $nth === $wom);
                break;
            case 'monthly':
                $match = ($dom === $month_day);
                break;
            case 'every-x-weeks':
                $sd = strtotime($start_date);
                // round(), not floor(): DST transitions make midnight-to-midnight
                // diffs 23 or 25 hours; round() recovers the calendar-day count
                // (SQL-side this is DATEDIFF, which counts calendar days).
                $match = ($dow === $week_day && $interval > 0 && $sd !== false && $day >= $sd
                    && ((int)round(($day - $sd) / 86400)) % ($interval * 7) === 0);
                break;
        }
        if ($match) {
            $out[] = $ymd;
        }
    }
    return $out;
}

/**
 * Render a sitemap <urlset> document. $urls = list of ['loc' => URL,
 * 'lastmod' => 'Y-m-d' (optional)]. Escaping handled here.
 */
function ork_sitemap_xml($urls)
{
    $out = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
        . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    foreach ((array)$urls as $u) {
        $loc = trim((string)($u['loc'] ?? ''));
        if ($loc === '') {
            continue;
        }
        $out .= "  <url><loc>" . htmlspecialchars($loc, ENT_QUOTES) . '</loc>';
        $lastmod = trim((string)($u['lastmod'] ?? ''));
        if ($lastmod !== '' && ($ts = strtotime($lastmod)) !== false) {
            $out .= '<lastmod>' . date('Y-m-d', $ts) . '</lastmod>';
        }
        $out .= "</url>\n";
    }
    return $out . "</urlset>\n";
}

/**
 * Render a sitemap index document pointing at the per-section sitemaps.
 */
function ork_sitemap_index_xml($sitemaps)
{
    $out = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
        . '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    foreach ((array)$sitemaps as $loc) {
        $loc = trim((string)$loc);
        if ($loc !== '') {
            $out .= '  <sitemap><loc>' . htmlspecialchars($loc, ENT_QUOTES) . "</loc></sitemap>\n";
        }
    }
    return $out . "</sitemapindex>\n";
}

/**
 * Assemble a schema.org Event JSON-LD structure for an event occurrence.
 * Google reads this for rich event results (date chips, venue, "events near
 * me" surfaces) instead of scraping dates out of the page text.
 *
 * Datetimes are emitted WITHOUT a timezone offset on purpose: the ORK stores
 * venue-local naive datetimes, and Google's documented behavior for
 * offset-less event times is to interpret them as local to the venue address
 * supplied in `location` — which is exactly what the data means. Do not
 * fabricate an offset here.
 *
 * Empty fields are omitted rather than emitted blank.
 */
function ork_event_jsonld($args)
{
    $iso = function ($dt) {
        $ts = strtotime((string)$dt);
        return $ts === false ? '' : date('Y-m-d\TH:i:s', $ts);
    };

    $ld = array(
        '@context'            => 'https://schema.org',
        '@type'               => 'Event',
        'name'                => trim((string)($args['name'] ?? '')),
        'eventStatus'         => 'https://schema.org/EventScheduled',
        'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
    );
    if ($ld['name'] === '') {
        return array();
    }
    // all_day: emit a bare date (schema.org Date) — a park day with no
    // stored time must not claim to start at midnight.
    $allDay = !empty($args['all_day']);
    $start = $iso($args['start'] ?? '');
    if ($start === '') {
        // startDate is required for rich results; without it, emit nothing.
        return array();
    }
    $ld['startDate'] = $allDay ? substr($start, 0, 10) : $start;
    $end = $iso($args['end'] ?? '');
    if ($end !== '' && $end !== $start) {
        $ld['endDate'] = $allDay ? substr($end, 0, 10) : $end;
    }
    $desc = trim((string)($args['description'] ?? ''));
    if ($desc !== '') {
        $ld['description'] = $desc;
    }
    $image = trim((string)($args['image'] ?? ''));
    if ($image !== '') {
        $ld['image'] = $image;
    }

    $address = array('@type' => 'PostalAddress');
    foreach (array(
        'streetAddress'   => 'street',
        'addressLocality' => 'city',
        'addressRegion'   => 'province',
        'postalCode'      => 'postal',
        'addressCountry'  => 'country',
    ) as $ldKey => $argKey) {
        $v = trim((string)($args[$argKey] ?? ''));
        if ($v !== '') {
            $address[$ldKey] = $v;
        }
    }
    // Google requires location (with an address) on Event items — an item
    // without a locatable address is flagged critical ("Missing field
    // location") and can never be a rich result, so emit nothing at all.
    // Online events land here too (no address, and no data flag to emit a
    // VirtualLocation instead — better silent than claiming Offline mode).
    if (empty($address['streetAddress']) && empty($address['addressLocality'])) {
        return array();
    }
    $place = array('@type' => 'Place', 'address' => $address);
    $venue = trim((string)($args['venue'] ?? ''));
    if ($venue !== '') {
        $place['name'] = $venue;
    }
    $ld['location'] = $place;

    $organizer = trim((string)($args['organizer'] ?? ''));
    if ($organizer !== '') {
        $ld['organizer'] = array('@type' => 'Organization', 'name' => $organizer);
        $orgUrl = trim((string)($args['organizer_url'] ?? ''));
        if ($orgUrl !== '') {
            $ld['organizer']['url'] = $orgUrl;
        }
    }
    $url = trim((string)($args['url'] ?? ''));
    if ($url !== '') {
        $ld['url'] = $url;
    }
    return $ld;
}

/**
 * Bucket a session user_agent / client label into a short display label
 * ("Chrome on Mac", "jsork", "mORK", ...). Single source of truth for the
 * anonymous sign-in tally (Authorization::CreateSession), the Release
 * Feature Utilization report, and mirrored by nav_session_client_label()
 * in the theme for the account-menu session list.
 */
function ork_session_client_label($ua)
{
    $ua = (string)$ua;
    if ($ua === '') {
        return 'Unknown client';
    }
    if (stripos($ua, 'mork') === 0) {
        // Keep the platform (durable iOS-vs-Android adoption trend in the
        // tally) but collapse versions — "mORK/2.5 (iOS)" -> "mORK on iOS".
        if (stripos($ua, 'ios') !== false || stripos($ua, 'iphone') !== false || stripos($ua, 'ipad') !== false) {
            return 'mORK on iOS';
        }
        if (stripos($ua, 'android') !== false) {
            return 'mORK on Android';
        }
        return 'mORK';
    }
    if (stripos($ua, 'jsork') !== false) {
        return 'jsork';
    }
    if (stripos($ua, 'curl') === 0) {
        return 'API client (curl)';
    }
    if (stripos($ua, 'Mozilla') === false) {
        return substr($ua, 0, 40);
    }
    // iOS browsers use distinct UA tokens (CriOS/FxiOS/EdgiOS) AND append a
    // compat "Safari/" token — check them before the desktop names so iOS
    // Chrome/Firefox/Edge don't all mislabel as "Safari on iOS".
    $browser = 'Browser';
    if (stripos($ua, 'CriOS/') !== false) {
        $browser = 'Chrome';
    } elseif (stripos($ua, 'FxiOS/') !== false) {
        $browser = 'Firefox';
    } elseif (stripos($ua, 'EdgiOS/') !== false) {
        $browser = 'Edge';
    } elseif (stripos($ua, 'Edg/') !== false) {
        $browser = 'Edge';
    } elseif (stripos($ua, 'OPR/') !== false) {
        $browser = 'Opera';
    } elseif (stripos($ua, 'Chrome/') !== false) {
        $browser = 'Chrome';
    } elseif (stripos($ua, 'Firefox/') !== false) {
        $browser = 'Firefox';
    } elseif (stripos($ua, 'Safari/') !== false) {
        $browser = 'Safari';
    }
    $os = '';
    if (stripos($ua, 'iPhone') !== false || stripos($ua, 'iPad') !== false) {
        $os = 'iOS';
    } elseif (stripos($ua, 'Android') !== false) {
        $os = 'Android';
    } elseif (stripos($ua, 'Macintosh') !== false) {
        $os = 'Mac';
    } elseif (stripos($ua, 'Windows') !== false) {
        $os = 'Windows';
    } elseif (stripos($ua, 'Linux') !== false) {
        $os = 'Linux';
    }
    // In-app webviews: iOS WebViews omit the "Safari/" token; Android's ships
    // a "; wv)" marker. These are ORK links opened inside Discord/Facebook/etc.
    if ($browser === 'Browser' && $os === 'iOS') {
        $browser = 'In-app browser';
    } elseif (stripos($ua, '; wv)') !== false) {
        $browser = 'In-app browser';
    }
    return $os !== '' ? "$browser on $os" : $browser;
}

function signUrl($myUrlToSign, $privateKey)
{
    return $myUrlToSign;
    // parse the url
    $url = parse_url($myUrlToSign);

    $urlPartToSign = $url[ 'path' ] . "?" . $url[ 'query' ];

    // Decode the private key into its binary format
    $decodedKey = decodeBase64UrlSafe($privateKey);

    // Create a signature using the private key and the URL-encoded
    // string using HMAC SHA1. This signature will be binary.
    $signature = hash_hmac("sha1", $urlPartToSign, $decodedKey, true);

    $encodedSignature = encodeBase64UrlSafe($signature);

    return $myUrlToSign . "&signature=" . $encodedSignature;
}

class Common
{
    public static $rate_limit;
    public static $init;

    public function __construct()
    {
        global $DB;
        global $LOG;
        $this->log = $LOG;
        $this->db = $DB;
        $this->config = new yapo($this->db, DB_PREFIX . 'configuration');
        $this->officer = new yapo($this->db, DB_PREFIX . 'officer');
        $this->authorization = new yapo($this->db, DB_PREFIX . 'authorization');
        if (Common::$init != 'init') {
            Common::$rate_limit = new yapo($this->db, DB_PREFIX . 'rate_limit');
            Common::$init = 'init';
        }
    }

    public static function RateLimit($service, $limit = 20, $per = "+1 week")
    {
        Common::$rate_limit->clear();
        Common::$rate_limit->ip_address = $_SERVER['REMOTE_ADDR'];
        Common::$rate_limit->service = $service;
        if (Common::$rate_limit->find()) {
            if (strtotime(Common::$rate_limit->expires) > time()) {
                if (Common::$rate_limit->count > $limit) {
                    return false;
                }
                Common::$rate_limit->count = Common::$rate_limit->count + 1;
                Common::$rate_limit->save();
                return true;
            } else {
                Common::$rate_limit->delete();
            }
        }
        Common::$rate_limit->clear();
        Common::$rate_limit->ip_address = $_SERVER['REMOTE_ADDR'];
        Common::$rate_limit->count = 1;
        Common::$rate_limit->service = $service;
        Common::$rate_limit->expires = date("Y-m-d H:i:s", strtotime($per));
        Common::$rate_limit->save();
        return true;
    }

    public static function Geocode($address, $city, $state, $postal_code, $geocode = null)
    {
        $c = new Common();

        if (!Common::RateLimit('geocode', 1000, $per = "+1 day")) {
            return array( "You have exceeded your rate limit" );
        }

        logtrace("Geocode", [ $address, $city, $state, $postal_code, $geocode ]);
        if (strlen($geocode) > 0) {
            $latlng = urlencode(str_replace(' ', '', $geocode));
            $geocodeURL = signUrl("https://maps.googleapis.com/maps/api/geocode/json?latlng=$latlng&sensor=false&key=" . GOOGLE_MAPS_ACCESS_API_KEY, GOOGLE_MAPS_API_KEY);
        } else {
            $address = urlencode($address . ', ' . $city . ', ' . $state . ', ' . $postal_code);
            $geocodeURL = signUrl("https://maps.googleapis.com/maps/api/geocode/json?address=$address&sensor=false&key=" . GOOGLE_MAPS_ACCESS_API_KEY, GOOGLE_MAPS_API_KEY);
        }
        $ch = curl_init($geocodeURL);
        curl_setopt($ch, CURLOPT_REFERER, 'https://amtgard.com/ork');
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $details = [ ];
        logtrace("Geocode: Processing.", array( $httpCode, $result ));
        if ($httpCode == 200) {
            $geocode = json_decode($result);
            logtrace("Geocode: Processing.", $geocode);
            $lat = $geocode->results[ 0 ]->geometry->location->lat;
            $lng = $geocode->results[ 0 ]->geometry->location->lng;
            $formatted_address = $geocode->results[ 0 ]->formatted_address;
            $details[ 'Address' ] = $formatted_address;
            $geo_status = $geocode->status;
            $location_type = $geocode->results[ 0 ]->geometry->location_type;
            $details[ 'Geocode' ] = $result;
            $details[ 'Location' ] = json_encode($geocode->results[ 0 ]->geometry);
            if (is_array($geocode->results[ 0 ]->address_components)) {
                foreach ($geocode->results[ 0 ]->address_components as $k => $component) {
                    switch ($component->types[ 0 ]) {
                        case 'locality':
                            if ($component->types[ 1 ] == 'political') {
                                $details[ 'City' ] = $component->long_name;
                            }
                            break;
                        case 'administrative_area_level_1':
                            if ($component->types[ 1 ] == 'political') {
                                $details[ 'Province' ] = $component->long_name;
                            }
                            break;
                        case 'postal_code':
                            $details[ 'PostalCode' ] = $component->long_name;
                            break;
                    }
                }
            }
            logtrace("Geocode: Details.", $details);
            return $details;
        } else {
            logtrace("Geocode: failed.", [ ]);
            return false;
        }
    }

    public static function replace_links($text)
    {

        return preg_replace('@(https?://([-\w\.]+[-\w])+(:\d+)?(/([\w/_\.#-]*(\?\S+)?[^\.\s])?)?)@', '<a href="$1" target="_blank">$1</a>', $text);

    }

    public static function url_exists($url)
    {
        // Version 4.x supported
        $handle = curl_init($url);
        if (false === $handle) {
            return false;
        }
        curl_setopt($handle, CURLOPT_HEADER, false);
        curl_setopt($handle, CURLOPT_FAILONERROR, true);  // this works
        curl_setopt($handle, CURLOPT_HTTPHEADER, [ "User-Agent: Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1.15) Gecko/20080623 Firefox/2.0.0.15" ]); // request as if Firefox
        curl_setopt($handle, CURLOPT_NOBODY, true);
        curl_setopt($handle, CURLOPT_RETURNTRANSFER, false);
        $connectable = curl_exec($handle);
        curl_close($handle);
        return $connectable;
    }

    public static function exif_to_mime($type, $filename = null)
    {
        switch ($type) {
            case IMAGETYPE_GIF:
                return 'image/gif';
            case IMAGETYPE_JPEG:
                return 'image/jpeg';
            case IMAGETYPE_PNG:
                return 'image/png';
        }
        if (!is_null($filename)) {
            $pi = pathinfo($filename);
            switch (strtoupper($pi[ 'extension' ])) {
                case 'GIF':
                    return 'image/gif';
                case 'JPEG':
                case 'JPG':
                    return 'image/jpeg';
                case 'PNG':
                    return 'image/png';
            }
        }
        return 'image/fuckyou';
    }

    public static function is_pdf_mime_type($type)
    {
        switch (strtoupper($type)) {
            case 'APPLICATION/PDF':
            case 'APPLICATION/X-PDF':
                return true;
        }
        return false;
    }

    public static function gd_has_transparency($gd)
    {
        // Check palette-based transparency (GIF)
        if (imagecolortransparent($gd) >= 0) {
            return true;
        }
        // Sample a grid of pixels for alpha channel transparency (PNG)
        $w = imagesx($gd);
        $h = imagesy($gd);
        $step_x = max(1, (int)($w / 16));
        $step_y = max(1, (int)($h / 16));
        for ($x = 0; $x < $w; $x += $step_x) {
            for ($y = 0; $y < $h; $y += $step_y) {
                $rgba = imagecolorsforindex($gd, imagecolorat($gd, $x, $y));
                if ($rgba['alpha'] > 0) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Crop fully-transparent rows/columns from the edges of a GD image.
     * Pixel-accurate (imagecrop copies verbatim — no resampling, no aspect
     * distortion). Returns the original resource untouched if there is no
     * transparent border to trim, the image is fully transparent, or any
     * step fails. Alpha channel is preserved on the returned resource.
     *
     * $alpha_threshold: GD alpha is 0 (opaque) to 127 (transparent). A pixel
     * counts as "content" if its alpha is LESS than this value. Default 120
     * tolerates faint anti-alias fringe without eating real strokes.
     */
    public static function gd_trim_transparent($gd, $alpha_threshold = 120)
    {
        if (! $gd) {
            return $gd;
        }
        $w = imagesx($gd);
        $h = imagesy($gd);
        if ($w < 2 || $h < 2) {
            return $gd;
        }

        $top = -1;
        $bottom = -1;
        $left = $w;
        $right = -1;

        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $rgba = imagecolorsforindex($gd, imagecolorat($gd, $x, $y));
                if ($rgba['alpha'] < $alpha_threshold) {
                    if ($top === -1) {
                        $top = $y;
                    }
                    $bottom = $y;
                    if ($x < $left) {
                        $left  = $x;
                    }
                    if ($x > $right) {
                        $right = $x;
                    }
                }
            }
        }

        // Fully transparent or nothing found — leave image alone.
        if ($top === -1 || $right === -1) {
            return $gd;
        }

        // Already tight — no trim needed.
        if ($top === 0 && $left === 0 && $bottom === $h - 1 && $right === $w - 1) {
            return $gd;
        }

        $rect = array(
            'x'      => $left,
            'y'      => $top,
            'width'  => $right - $left + 1,
            'height' => $bottom - $top + 1,
        );

        $cropped = imagecrop($gd, $rect);
        if ($cropped === false) {
            return $gd;
        }

        imagealphablending($cropped, false);
        imagesavealpha($cropped, true);
        return $cropped;
    }

    public static function resolve_image_ext($dir_base, $name)
    {
        if (file_exists($dir_base . $name . '.png')) {
            return $name . '.png';
        }
        return $name . '.jpg';
    }

    public static function supported_mime_types($type)
    {
        switch (strtoupper($type)) {
            case 'IMAGE/JPEG':
            case 'IMAGE/GIF':
            case 'IMAGE/PNG':
                return true;
            case 'APPLICATION/PDF':
            case 'APPLICATION/X-PDF':
                return true;
        }
        return false;
    }

    public static function make_safe_html($text)
    {
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
        $text = Common::replace_links(Common::strip_attributes(strip_tags($text, $tags), $tags));
        return $text;
    }

    public static function strip_attributes($text, $tags)
    {

        preg_match_all("/<([^>]+)>/i", $tags, $allTags, PREG_PATTERN_ORDER);

        foreach ($allTags[ 1 ] as $tag) {
            $text = preg_replace("/<" . $tag . "[^>]*>/i", "<" . $tag . ">", $text);
        }

        return $text;
    }


    public function create_events($kingdom_id, $park_id)
    {
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

    public function create_event($kingdom_id, $park_id, $name)
    {
        $this->event->clear();
        $this->event->kingdom_id = $kingdom_id;
        $this->event->park_id = $park_id;
        $this->event->name = $name;
        $this->event->modified = date('Y-m-d H:i:s');
        $this->event->save();
    }

    public function create_park_titles($kingdom_id)
    {
        $this->parktitle = new yapo($this->db, DB_PREFIX . 'parktitle');
        $titles = [
            [ 'Outpost', 10, 5, 1, 'month', 6 ],
            [ 'Shire', 20, 5, 1, 'month', 6 ],
            [ 'Barony', 30, 15, 13, 'month', 6 ],
            [ 'Duchy', 40, 30, 28, 'month', 6 ],
            [ 'Grand Duchy', 50, 60, 56, 'month', 6 ],
        ];
        foreach ($titles as $t => $detail) {
            $this->create_park_title($kingdom_id, $detail);
        }
    }

    public function create_park_title($kingdom_id, $detail)
    {
        $this->parktitle->clear();
        $this->parktitle->kingdom_id = $kingdom_id;
        $this->parktitle->title = $detail[ 0 ];
        $this->parktitle->class = $detail[ 1 ];
        $this->parktitle->minimumattendance = $detail[ 2 ];
        $this->parktitle->minimumcutoff = $detail[ 3 ];
        $this->parktitle->period = $detail[ 4 ];
        $this->parktitle->period_length = $detail[ 5 ];
        $this->parktitle->save();
    }

    public function find_matching_officer_award($kingdom_id, $park_id, $role)
    {
        $monarch = array( 92, 70, 71, 72, 73, 74, 75, 76, 227, 234 );
        $regent = array( 90, 77, 78, 79, 80, 228, 235 );
        $prime_minister = array( 91, 81, 82, 83, 84, 205, 237 );
        $champion = array( 89, 85, 86, 87, 88, 236 );

        $sql = "select ka.award_id, ka.kingdom_award_id, a.name as award_name, ka.name as kingdom_award_name
              from " . DB_PREFIX . "award a left join " . DB_PREFIX . "kingdomaward ka on ka.award_id = a.award_id
            where ka.award_id in ";
    }

    public function set_officer($kingdom_id, $park_id, $new_officer_id, $role, $system = 0, $changed_by = 0, $position_id = 0, $display_label = '')
    {
        global $DB;
        // Resolve whether this position bypasses the ork_authorization path.
        // Prefer the registry has_auth_role flag; fall back to the legacy string check.
        $_role_key = PermissionRegistry::CanonicalOfficerRole($role);
        $bypass_auth = ('champion' === $_role_key || 'gmr' === $_role_key);
        if ((int)$position_id > 0) {
            $DB->Clear();
            $DB->pid = (int)$position_id;
            $_har = $DB->DataSet("SELECT has_auth_role FROM " . DB_PREFIX . "officer_position WHERE position_id = :pid LIMIT 1");
            if ($_har !== false && $_har->size() > 0 && $_har->Next()) {
                $bypass_auth = ((int)$_har->has_auth_role === 0);
            }
        }

        $this->officer->clear();
        if (isset($kingdom_id)) {
            $this->officer->kingdom_id = $kingdom_id;
        }
        $this->officer->park_id = $park_id;
        if ((int)$position_id > 0) {
            $this->officer->position_id = (int)$position_id;
        } else {
            $this->officer->role = $role;
        }
        $this->officer->system = $system;
        if ($this->officer->find()) {
            $old_mundane_id = $this->officer->mundane_id;
            $officer_changed = false;
            // Keep both the position_id and the canonical-key role cache in sync (dual-write).
            if ((int)$position_id > 0) {
                $this->officer->position_id = (int)$position_id;
                $this->officer->role = $role;
            }

            if ($bypass_auth) {
                $this->officer->mundane_id = $new_officer_id;
                $this->officer->save();
                $officer_changed = true;
            } else {
                $this->authorization->clear();
                $this->authorization->authorization_id = $this->officer->authorization_id;
                if ($this->authorization->find()) {
                    $this->officer->mundane_id = $new_officer_id;
                    $this->authorization->mundane_id = $new_officer_id;
                    $this->officer->save();
                    $this->authorization->save();
                    $officer_changed = true;
                }
            }

            // C3: observability — a real change was requested ($new differs from $old)
            // but $officer_changed never flipped (e.g. has_auth_role crown position whose
            // ork_authorization row is missing, so find() failed). Leave a diagnosable
            // breadcrumb rather than silently returning as if nothing was wrong.
            if (!$officer_changed && (int)$new_officer_id !== (int)$old_mundane_id) {
                logtrace('set_officer: officer NOT changed (missing authorization row?)', [
                    'kingdom_id'   => (int)$kingdom_id,
                    'park_id'      => (int)$park_id,
                    'position_id'  => (int)$position_id,
                    'role'         => $role,
                    'old_mundane'  => (int)$old_mundane_id,
                    'new_mundane'  => (int)$new_officer_id,
                    'bypass_auth'  => $bypass_auth ? 1 : 0,
                    'authorization_id' => (int)$this->officer->authorization_id,
                ]);
            }

            // Record officer history only if the officer was actually changed
            if ($officer_changed && (int)$old_mundane_id !== (int)$new_officer_id) {
                $this->record_officer_history($kingdom_id, $park_id, $old_mundane_id, $new_officer_id, $role, $changed_by, $position_id, $display_label);

                // RBAC dual-write: prefer position-id sync, fall back to legacy string path.
                if (isset(Ork3::$Lib->rbacservice)) {
                    try {
                        if ((int)$position_id > 0) {
                            Ork3::$Lib->rbacservice->SyncOfficerRoleByPositionId($old_mundane_id, $new_officer_id, $position_id, $kingdom_id, $park_id, $changed_by);
                        } else {
                            Ork3::$Lib->rbacservice->SyncOfficerRole($kingdom_id, $park_id, $old_mundane_id, $new_officer_id, $role, $changed_by);
                        }
                    } catch (Exception $e) {
                        logtrace('RBAC sync officer failed', $e->getMessage());
                    }
                }
            }
        }
    }

    /**
     * Record officer change in the ork_officer_history table.
     * Closes the previous officer's open record and opens a new one (unless vacating).
     */
    private function record_officer_history($kingdom_id, $park_id, $old_mundane_id, $new_mundane_id, $role, $changed_by = 0, $position_id = 0, $display_label = '')
    {
        global $DB;
        $kid  = (int)$kingdom_id;
        $pid  = (int)$park_id;
        $posid = (int)$position_id;
        $cb   = (int)$changed_by;
        $today = date('Y-m-d');

        // Resolve the snapshot DisplayTitle for this position at write time (requirement 7).
        // IF(alias != '', alias, title) — never COALESCE.
        // P3: callers that already resolved the DisplayTitle (e.g. SetOfficerByPosition
        // via GetPosition) pass it in $display_label, letting us SKIP the inner SELECT.
        // Backward compatible: when '' is passed (default, as legacy callers do), fall
        // back to $role and re-run the snapshot SELECT exactly as before.
        $passed_label = trim((string) $display_label);
        if ($passed_label !== '') {
            $display_label = $passed_label;
        } else {
            $display_label = $role;
            if ($posid > 0) {
                $DB->Clear();
                $DB->dl_pid = $posid;
                $DB->dl_kid = $kid;
                $dl = $DB->DataSet(
                    "SELECT " . OfficerPosition::DisplayTitleSql('p', 'a') . " AS display_title
					 FROM " . DB_PREFIX . "officer_position p
					 LEFT JOIN " . DB_PREFIX . "officer_position_alias a
					   ON a.kingdom_id = :dl_kid AND a.canonical_key = p.canonical_key
					 WHERE p.position_id = :dl_pid LIMIT 1"
                );
                if ($dl !== false && $dl->size() > 0 && $dl->Next()) {
                    $display_label = (string)$dl->display_title;
                }
            }
        }

        // Close any open history record for this role (where end_date IS NULL)
        if ((int)$old_mundane_id > 0) {
            $DB->Clear();
            $DB->h_today = $today;
            $DB->h_kid = $kid;
            $DB->h_pid = $pid;
            $DB->h_role = $role;
            $DB->h_posid = $posid;
            $DB->Execute(
                "UPDATE " . DB_PREFIX . "officer_history
				 SET end_date = :h_today
				 WHERE kingdom_id = :h_kid
				   AND park_id = :h_pid
				   AND role = :h_role
				   AND ( :h_posid = 0 OR position_id = :h_posid )
				   AND end_date IS NULL"
            );
        }

        // Open a new history record for the incoming officer (skip if vacating)
        if ((int)$new_mundane_id > 0) {
            $mid = (int)$new_mundane_id;
            $DB->Clear();
            $DB->i_kid = $kid;
            $DB->i_pid = $pid;
            $DB->i_mid = $mid;
            $DB->i_role = $role;
            $DB->i_posid = $posid;
            $DB->i_label = $display_label;
            $DB->i_today = $today;
            $DB->i_cb = ($cb > 0 ? $cb : null);
            $DB->Execute(
                "INSERT INTO " . DB_PREFIX . "officer_history
				 (kingdom_id, park_id, mundane_id, role, position_id, display_label, start_date, end_date, changed_by, created_at)
				 VALUES (:i_kid, :i_pid, :i_mid, :i_role, :i_posid, :i_label, :i_today, NULL, :i_cb, NOW())"
            );
        }
    }

    public function create_officers($kingdom_id, $park_id, $principality_id = 0)
    {
        $this->create_officer($kingdom_id, $park_id, 'monarch', 'create');
        $this->create_officer($kingdom_id, $park_id, 'regent', 'create');
        $this->create_officer($kingdom_id, $park_id, 'prime_minister', 'create');
        $this->create_officer($kingdom_id, $park_id, 'champion', null);
        $this->create_officer($kingdom_id, $park_id, 'gmr', null);
        if (valid_id($principality_id)) {
            $this->create_officer($kingdom_id, $park_id, 'monarch', 'create', 1, $principality_id);
            $this->create_officer($kingdom_id, $park_id, 'regent', 'create', 1, $principality_id);
            $this->create_officer($kingdom_id, $park_id, 'prime_minister', 'create', 1, $principality_id);
            $this->create_officer($kingdom_id, $park_id, 'champion', null, 1, $principality_id);
            $this->create_officer($kingdom_id, $park_id, 'gmr', null, 1, $principality_id);
        }
    }

    private function create_officer($kingdom_id, $park_id, $role, $authorization, $system = 0, $principality_id = 0)
    {
        // Resolve the system seed position_id for this canonical key (BUG-1).
        global $DB;
        $DB->Clear();
        $DB->ck_role = $role;
        $_posrow = $DB->DataSet("SELECT position_id FROM " . DB_PREFIX . "officer_position WHERE kingdom_id = 0 AND canonical_key = :ck_role LIMIT 1");
        $position_id = ($_posrow !== false && $_posrow->size() > 0 && $_posrow->Next()) ? (int)$_posrow->position_id : 0;

        $this->officer->clear();
        $this->officer->kingdom_id = $kingdom_id;
        $this->officer->park_id = $park_id;
        $this->officer->role = $role;
        $this->officer->position_id = $position_id;
        $this->officer->system = $system;
        $this->officer->modified = time();
        if (strlen($authorization) > 0) {
            $A = new Authorization();
            $r = $A->add_auth_h([
                'MundaneId' => 0,
                'Type'      => $park_id > 0 ? 'Park' : 'Kingdom',
                'Role'      => $authorization,
                'Id'        => $park_id == 0 ? $kingdom_id : $park_id,
            ]);
            if ($r[ 'Status' ] == 0) {
                $this->officer->authorization_id = $r[ 'Detail' ];
            }
        }
        $this->officer->save();

        // RBAC dual-write: notify that a new officer slot was created
        if (isset(Ork3::$Lib->rbacservice)) {
            Ork3::$Lib->rbacservice->SyncNewOfficerSlot($kingdom_id, $park_id, $role, $position_id);
        }
    }

    public static function get_configs($id, $type = CFG_KINGDOM)
    {
        global $DB;
        $config = new yapo($DB, DB_PREFIX . 'configuration');
        $config->clear();
        $config->type = $type;
        $config->id = $id;
        $response = [ ];
        if ($config->find()) {
            do {
                $response[ $config->key ] = [
                    'ConfigurationId' => $config->configuration_id,
                    'Type'            => $config->var_type,
                    'Key'             => $config->key,
                    'Value'           => json_decode(stripslashes($config->value)),
                    'UserSetting'     => $config->user_setting,
                    'AllowedValues'   => json_decode(stripslashes($config->allowed_values)),
                ];
            } while ($config->next());
        }
        return $response;
    }

    // Configuration changes -- dues amounts, attendance minimums, feature flags --
    // are officer-visible policy and were written with no audit row at all.
    // Auditing centrally here covers every caller, so no config path can be
    // added later that quietly skips the trail.
    private function audit_config($fn, $type, $id, $key, $prior_state, $post_state)
    {
        $entity = ($type === CFG_KINGDOM) ? 'Kingdom' : (($type === CFG_PARK) ? 'Park' : 'Configuration');
        Ork3::$Lib->dangeraudit->audit(
            'Common::' . $fn,
            ['Type' => $type, 'Id' => $id, 'Key' => $key],
            $entity,
            (int)$id,
            $prior_state,
            $post_state
        );
    }

    public function add_config($requester_id, $type, $var_type, $id, $key, $value, $user_setting = 1, $allowed_values = null)
    {
        $this->log->Write('Configuration', $requester_id, LOG_ADD, [ $type, $id, $key, $value ]);
        $this->config->clear();
        $this->config->type = $type;
        $this->config->var_type = $type;
        $this->config->id = $id;
        $this->config->key = $key;
        $this->config->value = json_encode($value);
        $this->config->user_setting = $user_setting ? 1 : 0;
        $this->config->allowed_values = json_encode($allowed_values);
        $this->config->modified = date("Y-m-d H:i:s", time());
        $this->config->save();
        $this->audit_config(__FUNCTION__, $type, $id, $key, null, ['key' => $key, 'value' => $value]);
    }

    public function remove_config($requester_id, $config_id, $type, $id, $key)
    {
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
        if ($this->config->find()) {
            $prior = ['configuration_id' => (int)$this->config->configuration_id, 'key' => $this->config->key, 'value' => $this->config->value];
            $this->config->delete();
            $this->audit_config(__FUNCTION__, $type, $id, $key, $prior, null);
        }
    }

    public function update_config($requester_id, $config_id, $type, $id, $key, $value)
    {
        $this->log->Write('Configuration', $requester_id, LOG_EDIT, [ $config_id, $type, $id, $key, $value ]);
        $this->config->clear();
        $this->config->configuration_id = $config_id;
        // Ditto, above
        $this->config->type = strlen($type) > 0 ? $type : $this->config->type;
        $this->config->id = strlen($id) > 0 ? $id : $this->config->id;
        if (strlen($key) > 0) {
            $this->config->key = $key;
        }
        if ($this->config->find()) {
            $prior = ['configuration_id' => (int)$this->config->configuration_id, 'key' => $this->config->key, 'value' => $this->config->value];
            if ($value !== null) {
                $allowed = json_decode($this->config->allowed_values);
                if (is_array($allowed)) {
                    $allow = true;
                    foreach ($value as $v_key => $v_value) {
                        foreach ($allowed as $a_key => $a_value) {
                            if ($a_key == $v_key) {
                                $allow = false;
                                foreach ($v_key as $k => $allowance) {
                                    if ($allowance == $v_value) {
                                        $allow = true;
                                    }
                                }
                            }
                            if (!$allow) {
                                return false;
                            }
                        }
                    }
                }
                $this->config->value = json_encode($value);
            } else {
                $this->config->value = json_encode(null);
            }
            $this->config->modified = date("Y-m-d H:i:s", time());
            $this->config->save();
            $this->audit_config(__FUNCTION__, $type, $id, $key, $prior, ['key' => $this->config->key, 'value' => $this->config->value]);
        }
    }

}

//http://www.codingforums.com/archive/index.php/t-180473.html
class shortScale
{
    // Source: Wikipedia (http://en.wikipedia.org/wiki/Names_of_large_numbers)
    private static $scale = [ '', 'thousand', 'million', 'billion', 'trillion', 'quadrillion', 'quintillion', 'sextillion', 'octillion', 'nonillion', 'decillion', 'undecillion', 'duodecillion', 'tredecillion', 'quattuordecillion', 'quindecillion', 'sexdecillion', 'septendecillion', 'octodecillion', 'noverndecillion', 'vigintillion' ];
    private static $digit = [ '', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen' ];
    private static $digith = [ '', 'first', 'second', 'third', 'fourth', 'fifth', 'sixth', 'seventh', 'eighth', 'ninth', 'tenth', 'eleventh', 'twelfth', 'thirteenth', 'fourteenth', 'fiftheenth', 'sixteenth', 'seventeenth', 'eighteenth', 'nineteenth' ];
    private static $ten = [ '', '', 'twenty', 'thirty', 'fourty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety' ];
    private static $tenth = [ '', '', 'twentieth', 'thirtieth', 'fortieth', 'fiftieth', 'sixtieth', 'seventieth', 'eightieth', 'ninetieth' ];

    private static function floatToArray($number, &$int, &$frac)
    {
        // Forced $number as (string), effectively to avoid (float) inprecision
        @list(, $frac) = explode('.', $number);
        if ($frac || !is_numeric($number) || (strlen($number) > 60)) {
            throw new Exception('Not a number or not a supported number type');
        }
        // $int = explode(',', number_format(ltrim($number, '0'), 0, '', ',')); -- Buggy
        $int = str_split(str_pad($number, ceil(strlen($number) / 3) * 3, '0', STR_PAD_LEFT), 3);
    }

    /* in retrospect ... this function was pretty easy */
    public static function toDigith($number)
    {
        if ($number < 20) {
            return $number . substr(self::$digith[ $number ], -2);
        } else {
            self::floatToArray($number, $int, $frac);
            return $number . substr(self::$digith[ substr($number, -1) ], -2);
        }
    }

    private static function thousandToEnglish($number)
    {
        // Gets numbers from 0 to 999 and returns the cardinal English
        $hundreds = floor($number / 100);
        $tens = $number % 100;
        $pre = ($hundreds ? self::$digit[ $hundreds ] . ' hundred' : '');
        if ($tens < 20) {
            $post = self::$digit[ $tens ];
        } else {
            $post = trim(self::$ten[ floor($tens / 10) ] . ' ' . self::$digit[ $tens % 10 ]);
        }
        if ($pre && $post) {
            return $pre . ' and ' . $post;
        }
        return $pre . $post;
    }

    private static function cardinalToOrdinal($cardinal)
    {
        // Finds the last word in the cardinal arrays and replaces it with
        // the entry from the ordinal arrays, or appends "th"
        $words = explode(' ', $cardinal);
        $last = &$words[ count($words) - 1 ];
        if (in_array($last, self::$digit)) {
            $last = self::$digith[ array_search($last, self::$digit) ];
        } elseif (in_array($last, self::$ten)) {
            $last = self::$tenth[ array_search($last, self::$ten) ];
        } elseif (substr($last, -2) != 'th') {
            $last .= 'th';
        }
        return implode(' ', $words);
    }

    public static function toOrdinal($number)
    {
        // Converts a xth format number to English. e.g. 22nd to twenty-second.
        return trim(self::cardinalToOrdinal(self::toCardinal($number)));
    }

    public static function toCardinal($number)
    {
        // Converts a number to English. e.g. 22 to twenty-two.
        self::floatToArray($number, $int, $frac);
        $int = array_reverse($int);
        $english = [ ];
        for ($i = count($int) - 1; $i > -1; $i--) {
            $englishnumber = self::thousandToEnglish($int[ $i ]);
            if ($englishnumber) {
                $english[] = $englishnumber . ' ' . self::$scale[ $i ];
            }
        }
        $post = array_pop($english);
        $pre = implode(', ', $english);
        if ($pre && $post) {
            return trim($pre . ' and ' . $post);
        }
        return trim($pre . $post);
    }
}
