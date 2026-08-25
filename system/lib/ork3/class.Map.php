<?php

class Map extends Ork3
{
    public function __construct()
    {
        parent::__construct();
        $this->park = new yapo($this->db, DB_PREFIX . 'park');
    }

    public function Geocode($request)
    {
        return $this->_geocode_thunk(Common::Geocode($request['address'], $request['$city'], $request['$state'], $request['$postal_code']));

    }

    private function _geocode_thunk($geocode)
    {
        $geocode[ 'Geocode' ] = json_decode($geocode[ 'Geocode' ]);
        $geocode[ 'Location' ] = json_decode($geocode[ 'Location' ]);
        return $geocode;
    }

    public function GetParkLocations($request)
    {
        $key = Ork3::$Lib->ghettocache->key($request);
        if (($cache = Ork3::$Lib->ghettocache->get(__CLASS__ . '.' . __FUNCTION__, $key, 300)) !== false) {
            return $cache;
        }

        $this->park->clear();
        $this->park->active = 'Active';
        $locations = array();
        if (valid_id($request['KingdomId'])) {
            $this->park->kingdom_id = $request['KingdomId'];
        }
        $kingdoms = Ork3::$Lib->kingdom->GetKingdoms(array());
        if ($this->park->find()) {
            do {
                $locations[] = array(
                        'Location' => $this->park->location,
                        'ParkId' => $this->park->park_id,
                        'Directions' => $this->park->directions,
                        'Description' => $this->park->description,
                        'HasHeraldry' => $this->park->has_heraldry,
                        'Name' => $this->park->name,
                        'KingdomId' => $this->park->kingdom_id,
                        'KingdomName' => $kingdoms['Kingdoms'][$this->park->kingdom_id]['KingdomName'],
                        'KingdomColor' => $kingdoms['Kingdoms'][$this->park->kingdom_id]['KingdomColor'],
                        'ParentKingdomId' => (int)($kingdoms['Kingdoms'][$this->park->kingdom_id]['ParentKingdomId'] ?? 0),
                        'City' => $this->park->city,
                        'Province' => $this->park->province,
                    );
            } while ($this->park->next());
        }
        return Ork3::$Lib->ghettocache->cache(__CLASS__ . '.' . __FUNCTION__, $key, array('Parks' => $locations));
    }

    /**
     * Park rows for a PUBLIC map of one kingdom, ready to json_encode.
     *
     * Owns the decisions a caller would otherwise have to re-make for itself:
     *  - which parks are plottable (an Active park whose stored Location blob
     *    yields numeric coordinates — the geocoded point, or the bounding
     *    box's northeast corner when geocoding only matched a region);
     *  - how officer-authored Directions/Description are sanitized, which is
     *    CmsSanitizer's call, not the caller's;
     *  - where a park's heraldry image lives.
     * Text that public callers inject as markup is escaped here too, so the
     * array can be embedded verbatim.
     *
     * @param array $request { KingdomId: int }
     * @return array { Parks: array of { name, lat, lng, id, color, city, province, heraldry, dir, desc } }
     */
    public function GetPublicParkMapLocations($request)
    {
        $parks  = array();
        $source = $this->GetParkLocations(array('KingdomId' => isset($request['KingdomId']) ? (int)$request['KingdomId'] : 0));
        foreach ((array)($source['Parks'] ?? array()) as $details) {
            $parkId = (int)($details['ParkId'] ?? 0);
            if ($parkId <= 0) {
                continue;
            }
            $point = self::_map_point($details['Location'] ?? '');
            if ($point === null) {
                continue;
            }
            $heraldry = '';
            if (!empty($details['HasHeraldry']) && isset(Ork3::$Lib->heraldry)) {
                $h = Ork3::$Lib->heraldry->GetHeraldryUrl(array('Type' => 'Park', 'Id' => $parkId));
                if (is_array($h) && !empty($h['Url'])) {
                    $heraldry = (string)$h['Url'];
                }
            }
            $parks[] = array(
                'name'     => htmlspecialchars(ucwords((string)($details['Name'] ?? '')), ENT_QUOTES),
                'lat'      => $point['lat'],
                'lng'      => $point['lng'],
                'id'       => $parkId,
                'color'    => ltrim((string)($details['KingdomColor'] ?? '718096'), '#'),
                'city'     => htmlspecialchars(trim((string)($details['City'] ?? ''))),
                'province' => htmlspecialchars(trim((string)($details['Province'] ?? ''))),
                'heraldry' => $heraldry,
                'dir'      => CmsSanitizer::SafeMarkdown($details['Directions'] ?? ''),
                'desc'     => CmsSanitizer::SafeMarkdown($details['Description'] ?? ''),
            );
        }
        return array('Parks' => $parks);
    }

    /**
     * The plottable coordinate inside a park's stored Location blob, or null
     * when the park has none — the eligibility test for the public map.
     */
    private static function _map_point($locationJson)
    {
        $loc = @json_decode(stripslashes((string)$locationJson));
        if (!$loc) {
            return null;
        }
        $point = isset($loc->location) ? $loc->location
            : (isset($loc->bounds->northeast) ? $loc->bounds->northeast : null);
        if (!$point || !isset($point->lat, $point->lng)
            || !is_numeric($point->lat) || !is_numeric($point->lng)) {
            return null;
        }
        return array('lat' => (float)$point->lat, 'lng' => (float)$point->lng);
    }
}
