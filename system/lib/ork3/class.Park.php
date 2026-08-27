<?php

class Park extends Ork3
{
    /**
     * Fallback park title.
     *
     * Every park must carry a park title -- ork_park.parktitle_id is NOT NULL with a
     * schema DEFAULT of 1 -- so an operation that touches the title and cannot honour
     * what it was given has to land on something. That something is this constant, on
     * purpose, and never "whatever title happens to sort first", which is how parks
     * used to be silently reassigned to an unrelated title.
     */
    public const DEFAULT_PARKTITLE_ID = 1;

    /**
     * Park active states. ork_park.active is enum('Active','Retired').
     */
    public const ACTIVE_ACTIVE = 'Active';
    public const ACTIVE_RETIRED = 'Retired';

    public function __construct()
    {
        parent::__construct();
        $this->park = new yapo($this->db, DB_PREFIX . 'park');
        $this->parkday = new yapo($this->db, DB_PREFIX . 'parkday');
    }

    public function GetParkByAbbreviation($request)
    {
        if (trimlen($request['Abbreviation']) < 2 || trimlen($request['Abbreviation']) > 3) {
            return null;
        }

        $this->park->clear();
        $this->park->abbreviation = strtoupper(trim($request['Abbreviation']));
        if ($this->park->find()) {
            return $this->park->park_id;
        }
        return null;
    }

    public function GetParkInKingdomByAbbreviation($request, $kingdom_id)
    {
        if (trimlen($request['Abbreviation']) < 2 || trimlen($request['Abbreviation']) > 3) {
            return null;
        }

        $this->park->clear();
        $this->park->abbreviation = strtoupper(trim($request['Abbreviation']));
        $this->park->kingdom_id = $kingdom_id;

        if ($this->park->find()) {
            return $this->park->park_id;
        }
        return null;
    }

    // Migrate every member of one park into another, and clear the source park's
    // officer roster and park-scoped permission grants.
    //
    // This deliberately migrates MEMBERSHIP ONLY. It is not a park merge:
    // attendance, awards, event details, parkdays, tournaments and glicko2
    // ratings stay with the source park, which also stays active. The function
    // used to carry a large block of commented-out statements for those tables,
    // which read as an unfinished merge; they are gone, because the half that
    // was live (move players + destroy the officer roster) is the whole of what
    // the UI offers and promises. Anyone wanting a true merge should build it
    // deliberately rather than uncomment that block.
    //
    // Hardened here: the whole migration runs in one transaction, source and
    // destination must differ and must both exist, and every officer and
    // authorization row about to be deleted is captured into the audit
    // prior_state first -- previously they were destroyed with no record of
    // what had been there.
    public function MergeParks($request)
    {
        logtrace("MergeParks", $request);

        $from_park_id = (int)($request['FromParkId'] ?? 0);
        $to_park_id   = (int)($request['ToParkId'] ?? 0);

        if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) <= 0
            || !Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_ADMIN, $from_park_id, AUTH_CREATE)
            || !Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_ADMIN, $to_park_id, AUTH_CREATE)
        ) {
            logtrace("Parks NOT Merged", null);
            return NoAuthorization();
        }

        if (!valid_id($from_park_id) || !valid_id($to_park_id)) {
            return InvalidParameter('Both a source and a destination park are required.');
        }
        if ($from_park_id === $to_park_id) {
            return InvalidParameter('Cannot migrate a park into itself.');
        }

        $from_kingdom_id = $this->GetParkKingdomId($from_park_id);
        $to_kingdom_id   = $this->GetParkKingdomId($to_park_id);
        if (!valid_id($from_kingdom_id) || !valid_id($to_kingdom_id)) {
            return InvalidParameter('Source or destination park could not be found.');
        }

        // Snapshot what is about to be destroyed or moved, before touching it.
        $prior_state = [
            'from_park_id'    => $from_park_id,
            'from_kingdom_id' => (int)$from_kingdom_id,
            'to_park_id'      => $to_park_id,
            'to_kingdom_id'   => (int)$to_kingdom_id,
            'members'         => $this->snapshot_rows(
                "SELECT mundane_id, park_id, kingdom_id FROM " . DB_PREFIX . "mundane WHERE park_id = " . $from_park_id
            ),
            'officers'        => $this->snapshot_rows(
                "SELECT * FROM " . DB_PREFIX . "officer WHERE park_id = " . $from_park_id
            ),
            'authorizations'  => $this->snapshot_rows(
                "SELECT * FROM `" . DB_PREFIX . "authorization` WHERE park_id = " . $from_park_id
                . " OR authorization_id IN (SELECT authorization_id FROM `" . DB_PREFIX . "officer` WHERE park_id = " . $from_park_id . ")"
            ),
        ];

        $this->db->BeginTrans();
        try {
            $sql = "delete from `" . DB_PREFIX . "authorization` where authorization_id in (select authorization_id from `" . DB_PREFIX . "officer` where park_id = " . $from_park_id . ")";
            $this->db->Clear();
            if (!$this->db->ExecuteChecked($sql)) {
                throw new Exception('statement failed: ' . $sql);
            }

            $sql = "delete from " . DB_PREFIX . "officer where park_id = " . $from_park_id;
            $this->db->Clear();
            if (!$this->db->ExecuteChecked($sql)) {
                throw new Exception('statement failed: ' . $sql);
            }

            $sql = "delete from `" . DB_PREFIX . "authorization` where park_id = " . $from_park_id;
            $this->db->Clear();
            if (!$this->db->ExecuteChecked($sql)) {
                throw new Exception('statement failed: ' . $sql);
            }

            $sql = "update " . DB_PREFIX . "mundane set park_id = " . $to_park_id . ", kingdom_id = " . (int)$to_kingdom_id . " where park_id = " . $from_park_id;
            $this->db->Clear();
            if (!$this->db->ExecuteChecked($sql)) {
                throw new Exception('statement failed: ' . $sql);
            }

            $this->db->CommitTrans();
        } catch (Exception $e) {
            $this->db->RollbackTrans();
            logtrace("Parks NOT Merged: " . $e->getMessage(), null);
            // Persist the pre-merge snapshot on the FAILURE path too. This is the
            // one scenario where the snapshot is needed for recovery — a partial
            // merge may have destroyed officer/authorization rows — and it was
            // previously discarded here (only the success path audited).
            Ork3::$Lib->dangeraudit->audit(
                __CLASS__ . "::" . __FUNCTION__,
                $request,
                'Park',
                $from_park_id,
                $prior_state,
                [
                    'outcome' => 'FAILED — rolled back',
                    'error'   => $e->getMessage(),
                ]
            );
            return ProcessingError('The migration failed and was rolled back: ' . $e->getMessage());
        }

        logtrace("Parks Merged", null);
        Ork3::$Lib->dangeraudit->audit(
            __CLASS__ . "::" . __FUNCTION__,
            $request,
            'Park',
            $from_park_id,
            $prior_state,
            [
                'from_park_id'      => $from_park_id,
                'to_park_id'        => $to_park_id,
                'to_kingdom_id'     => (int)$to_kingdom_id,
                'members_moved'     => count($prior_state['members']),
                'officers_removed'  => count($prior_state['officers']),
                'grants_removed'    => count($prior_state['authorizations']),
            ]
        );
        return Success();
    }

    // Read a result set fully into an array of associative rows. Used to capture
    // audit snapshots before a destructive statement runs.
    private function snapshot_rows($sql)
    {
        $rows = array();
        $this->db->Clear();
        $rs = $this->db->DataSet($sql);
        if ($rs) {
            while ($rs->Next()) {
                $rows[] = $rs->CurrentFieldSet();
            }
        }
        $this->db->Clear();
        return $rows;
    }

    public function TransferPark($request)
    {
        if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request[ 'Token' ])) > 0
            && Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_ADMIN, $request[ 'KingdomId' ], AUTH_CREATE)
        ) {
            $this->park->clear();
            $this->park->park_id = $request[ 'ParkId' ];
            if ($this->park->find() && $this->park->park_id == $request[ 'ParkId' ]) {
                $old_kingdom_id = (int)$this->park->kingdom_id;
                $new_kingdom_id = (int)$request[ 'KingdomId' ];
                $this->park->kingdom_id = $request[ 'KingdomId' ];
                if (!empty($request[ 'Abbreviation' ])) {
                    $this->park->abbreviation = strtoupper($request[ 'Abbreviation' ]);
                }
                $this->park->save();
                // Move all players in the park to the new kingdom
                $sql = "update " . DB_PREFIX . "mundane set kingdom_id = '" . mysql_real_escape_string($request[ 'KingdomId' ]) . "' where park_id = '" . mysql_real_escape_string($request[ 'ParkId' ]) . "'";
                $this->db->query($sql);
                // Move all officers in the park to the new kingdom
                $sql = "update " . DB_PREFIX . "officer set kingdom_id = '" . mysql_real_escape_string($request[ 'KingdomId' ]) . "' where park_id = '" . mysql_real_escape_string($request[ 'ParkId' ]) . "'";
                $this->db->query($sql);
                // Bust park averages cache for both old and new kingdoms
                $report = Ork3::$Lib->report;
                foreach ([ $old_kingdom_id, $new_kingdom_id ] as $_kid) {
                    $report->bustKingdomParkAverageCaches((int) $_kid);
                }
                Ork3::$Lib->dangeraudit->audit(__CLASS__ . '::' . __FUNCTION__, $request, 'Park', (int)$request['ParkId'], [
                    'park_id'        => (int)$request['ParkId'],
                    'old_kingdom_id' => $old_kingdom_id,
                    'new_kingdom_id' => $new_kingdom_id,
                ]);
                return Success();
            } else {
                return InvalidParameter(null, 'There was an issue accessing the park.');
            }
        } else {
            return NoAuthorization();
        }
    }

    public function AddParkDay($request)
    {
        if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request[ 'Token' ])) > 0
            && Ork3::$Lib->authorizationgate->checkPermissionOrAuthority($mundane_id, 'park.parkday.manage', 'park', $request[ 'ParkId' ], AUTH_EDIT)
        ) {
            $this->parkday->clear();
            $this->parkday->park_id = $request[ 'ParkId' ];
            $this->parkday->recurrence = $request[ 'Recurrence' ];
            $this->parkday->week_of_month = $request[ 'WeekOfMonth' ];
            $this->parkday->week_day = $request[ 'WeekDay' ];
            $this->parkday->month_day = $request[ 'MonthDay' ];
            $this->parkday->start_date = (!empty($request[ 'StartDate' ])) ? substr($request[ 'StartDate' ], 0, 10) : '1000-01-01';
            $this->parkday->week_interval = (int)($request[ 'WeekInterval' ] ?? 0);
            if ($request[ 'Recurrence' ] === 'every-x-weeks' && !empty($request[ 'StartDate' ])) {
                $this->parkday->week_day = date('l', strtotime(substr($request[ 'StartDate' ], 0, 10)));
            }
            $this->parkday->time = $request[ 'Time' ];
            $this->parkday->purpose = $request[ 'Purpose' ];
            $this->parkday->description = $request[ 'Description' ];
            $this->parkday->alternate_location = $request[ 'AlternateLocation' ];
            $this->parkday->online = (int)($request[ 'Online' ] ?? 0);

            if (!empty($request[ 'Online' ])) {
                logtrace('AddParkDay.Online', null);
                // Online/virtual park day — no physical location
                $this->parkday->address = '';
                $this->parkday->city = '';
                $this->parkday->province = '';
                $this->parkday->postal_code = '';
                $this->parkday->latitude = 0;
                $this->parkday->longitude = 0;
                $this->parkday->google_geocode = '';
                $this->parkday->location = '';
                $this->parkday->map_url = '';
            } elseif ($request[ 'AlternateLocation' ] > 0) {
                logtrace('AddParkDay.AlternateLocation', null);
                $this->parkday->address = $request[ 'Address' ];
                $this->parkday->city = $request[ 'City' ];
                $this->parkday->province = $request[ 'Province' ];
                $this->parkday->postal_code = $request[ 'PostalCode' ];
                $this->parkday->map_url = $request[ 'MapUrl' ];
                logtrace('AddParkDay', array( $this->parkday, $request ));
                $this->park_geocode_h(null, $this->parkday);
            } else {
                logtrace('AddParkDay.NormalLocation', null);
                $this->park->clear();
                $this->park->park_id = $request[ 'ParkId' ];
                $this->park->find();
                $this->parkday->address = $this->park->address;
                $this->parkday->city = $this->park->city;
                $this->parkday->province = $this->park->province;
                $this->parkday->postal_code = $this->park->postal_code;
                $this->parkday->latitude = $this->park->latitude;
                $this->parkday->longitude = $this->park->longitude;
                $this->parkday->google_geocode = $this->park->google_geocode;
                $this->parkday->location = $this->park->location;
                $this->parkday->map_url = $this->park->map_url;
            }
            $this->parkday->location_url = $request[ 'LocationUrl' ];
            $this->parkday->save();
            $_audit_req = $request;
            unset($_audit_req[ 'Token' ]);
            Ork3::$Lib->dangeraudit->audit(__CLASS__ . '::' . __FUNCTION__, $_audit_req, 'Park', (int)$request[ 'ParkId' ], null, [
                'parkday_id'         => (int)$this->parkday->parkday_id,
                'park_id'            => (int)$this->parkday->park_id,
                'recurrence'         => $this->parkday->recurrence,
                'week_of_month'      => $this->parkday->week_of_month,
                'week_day'           => $this->parkday->week_day,
                'month_day'          => $this->parkday->month_day,
                'time'               => $this->parkday->time,
                'purpose'            => $this->parkday->purpose,
                'description'        => $this->parkday->description,
                'alternate_location' => (int)$this->parkday->alternate_location,
                'online'             => (int)$this->parkday->online,
                'address'            => $this->parkday->address,
                'city'               => $this->parkday->city,
            ]);
        } else {
            return NoAuthorization();
        }
    }

    public function EditParkDay($request)
    {
        $this->parkday->clear();
        $this->parkday->parkday_id = $request[ 'ParkDayId' ];
        if (!valid_id($request[ 'ParkDayId' ]) || !$this->parkday->find()) {
            return InvalidParameter();
        }
        $park_id = $this->parkday->park_id;
        if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request[ 'Token' ])) > 0
            && Ork3::$Lib->authorizationgate->checkPermissionOrAuthority($mundane_id, 'park.parkday.manage', 'park', $park_id, AUTH_EDIT)
        ) {
            // Snapshot the parkday before mutations so the audit log can show a diff.
            $_audit_prior = [
                'parkday_id'         => (int)$this->parkday->parkday_id,
                'park_id'            => (int)$this->parkday->park_id,
                'recurrence'         => $this->parkday->recurrence,
                'week_of_month'      => $this->parkday->week_of_month,
                'week_day'           => $this->parkday->week_day,
                'month_day'          => $this->parkday->month_day,
                'time'               => $this->parkday->time,
                'purpose'            => $this->parkday->purpose,
                'description'        => $this->parkday->description,
                'alternate_location' => (int)$this->parkday->alternate_location,
                'online'             => (int)$this->parkday->online,
                'address'            => $this->parkday->address,
                'city'               => $this->parkday->city,
            ];
            $this->parkday->recurrence = $request[ 'Recurrence' ];
            $this->parkday->week_of_month = $request[ 'WeekOfMonth' ];
            $this->parkday->week_day = $request[ 'WeekDay' ];
            $this->parkday->month_day = $request[ 'MonthDay' ];
            $this->parkday->start_date = (!empty($request[ 'StartDate' ])) ? substr($request[ 'StartDate' ], 0, 10) : '1000-01-01';
            $this->parkday->week_interval = (int)($request[ 'WeekInterval' ] ?? 0);
            if ($request[ 'Recurrence' ] === 'every-x-weeks' && !empty($request[ 'StartDate' ])) {
                $this->parkday->week_day = date('l', strtotime(substr($request[ 'StartDate' ], 0, 10)));
            }
            $this->parkday->time = $request[ 'Time' ];
            $this->parkday->purpose = $request[ 'Purpose' ];
            $this->parkday->description = $request[ 'Description' ];
            $this->parkday->alternate_location = $request[ 'AlternateLocation' ];
            $this->parkday->online = (int)($request[ 'Online' ] ?? 0);

            if (!empty($request[ 'Online' ])) {
                $this->parkday->address = '';
                $this->parkday->city = '';
                $this->parkday->province = '';
                $this->parkday->postal_code = '';
                $this->parkday->latitude = 0;
                $this->parkday->longitude = 0;
                $this->parkday->google_geocode = '';
                $this->parkday->location = '';
                $this->parkday->map_url = '';
            } elseif ($request[ 'AlternateLocation' ] > 0) {
                $this->parkday->address = $request[ 'Address' ];
                $this->parkday->city = $request[ 'City' ];
                $this->parkday->province = $request[ 'Province' ];
                $this->parkday->postal_code = $request[ 'PostalCode' ];
                $this->parkday->map_url = $request[ 'MapUrl' ];
                $this->park_geocode_h(null, $this->parkday);
            } else {
                $this->park->clear();
                $this->park->park_id = $park_id;
                $this->park->find();
                $this->parkday->address = $this->park->address;
                $this->parkday->city = $this->park->city;
                $this->parkday->province = $this->park->province;
                $this->parkday->postal_code = $this->park->postal_code;
                $this->parkday->latitude = $this->park->latitude;
                $this->parkday->longitude = $this->park->longitude;
                $this->parkday->google_geocode = $this->park->google_geocode;
                $this->parkday->location = $this->park->location;
                $this->parkday->map_url = $this->park->map_url;
            }
            $this->parkday->location_url = $request[ 'LocationUrl' ];
            $this->parkday->save();
            $_audit_req = $request;
            unset($_audit_req[ 'Token' ]);
            Ork3::$Lib->dangeraudit->audit(__CLASS__ . '::' . __FUNCTION__, $_audit_req, 'Park', (int)$park_id, $_audit_prior, [
                'parkday_id'         => (int)$this->parkday->parkday_id,
                'park_id'            => (int)$this->parkday->park_id,
                'recurrence'         => $this->parkday->recurrence,
                'week_of_month'      => $this->parkday->week_of_month,
                'week_day'           => $this->parkday->week_day,
                'month_day'          => $this->parkday->month_day,
                'time'               => $this->parkday->time,
                'purpose'            => $this->parkday->purpose,
                'description'        => $this->parkday->description,
                'alternate_location' => (int)$this->parkday->alternate_location,
                'online'             => (int)$this->parkday->online,
                'address'            => $this->parkday->address,
                'city'               => $this->parkday->city,
            ]);
            return Success();
        } else {
            return NoAuthorization();
        }
    }

    public function RemoveParkDay($request)
    {
        $this->parkday->clear();
        $this->parkday->parkday_id = $request[ 'ParkDayId' ];
        if (!valid_id($request[ 'ParkDayId' ] && $this->parkday->find())) {
            $park_id = $this->parkday->park_id;
        } else {
            return InvalidParameter();
        }
        if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request[ 'Token' ])) > 0
            && Ork3::$Lib->authorizationgate->checkPermissionOrAuthority($mundane_id, 'park.parkday.manage', 'park', $park_id, AUTH_EDIT)
        ) {
            $_audit_prior = [
                'parkday_id'         => (int)$this->parkday->parkday_id,
                'park_id'            => (int)$this->parkday->park_id,
                'recurrence'         => $this->parkday->recurrence,
                'week_day'           => $this->parkday->week_day,
                'time'               => $this->parkday->time,
                'purpose'            => $this->parkday->purpose,
                'description'        => $this->parkday->description,
            ];
            $this->parkday->parkday_id = $request[ 'ParkDayId' ];
            $this->parkday->delete();
            $_audit_req = $request;
            unset($_audit_req[ 'Token' ]);
            Ork3::$Lib->dangeraudit->audit(__CLASS__ . '::' . __FUNCTION__, $_audit_req, 'Park', (int)$park_id, $_audit_prior, null);
            return Success();
        }
        return NoAuthorization();
    }

    public function GetParks($request)
    {
        $sql = "select *
					from " . DB_PREFIX . "park p
						left join " . DB_PREFIX . "parktitle pt on pt.parktitle_id = p.parktitle_id
					where p.park_id = '" . mysql_real_escape_string($request[ 'ParkId' ]) . "' and p.parent_park_id > 0
					order by pt.class desc, p.name asc";
        $r = $this->db->query($sql);
        if ($r !== false && $r->size() > 0) {
            $response = [ 'Status' => Success(), 'Parks' => [ ] ];
            while ($r->next()) {
                $response[ 'Parks' ][] = [
                    'ParkId'       => $r->park_id,
                    'KingdomId'    => $r->kingdom_id,
                    'ParentParkId' => $r->parent_park_id,
                    'Name'         => $r->name,
                    'Abbreviation' => $r->abbreviation,
                    'Url'          => $r->url,
                    'Directions'   => stripslashes(nl2br($r->directions)),
                    'Location'     => $r->location,
                    'ParkTitleId'  => $r->parktitle_id,
                    'Active'       => $r->active,
                    'Title'        => $r->title,
                    'Class'        => $r->class,
                    'ParentOf'     => ($r->is_principality == 1 && !array_search($r->park_id, $request[ 'Stack' ])) ? $this->GetParks([ 'ParkId' => $r->park_id, 'Stack' => push_stack($request[ 'Stack' ], $r->park_id) ]) : null,
                ];
            }
        } else {
            $response[ 'Status' ] = InvalidParameter();
        }
        return $response;
    }

    public function GetOfficers($request)
    {
        $park_id = mysql_real_escape_string($request['ParkId']);
        $mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token']);
        $is_authorized = Ork3::$Lib->authorizationgate->checkPermissionOrAuthority($mundane_id, 'park.officer.set', 'park', $park_id, AUTH_EDIT);

        // Park-level officers: scope to this park within a real kingdom, and
        // resolve title aliases against each row's own kingdom (al.kingdom_id = o.kingdom_id).
        $aliasKingdomExpr = "o.kingdom_id";
        $whereClause = "o.park_id = '" . $park_id . "' and o.kingdom_id > 0";

        return Kingdom::buildOfficerRows($this->db, $aliasKingdomExpr, $whereClause, $mundane_id, $is_authorized);
    }

    public function GetParkKingdomId($pid)
    {
        $this->park->clear();
        $this->park->park_id = $pid;
        if ($this->park->find()) {
            return $this->park->kingdom_id;
        }

        return false;
    }
    public function GetParkShortInfo($request)
    {
        $this->park->clear();
        $this->park->park_id = $request[ 'ParkId' ];
        $response = [ ];
        if ($this->park->find()) {
            $response[ 'Status' ] = Success();
            $response[ 'ParkInfo' ] = [ ];
            $response[ 'ParkInfo' ][ 'KingdomId' ] = $this->park->kingdom_id;
            $response[ 'ParkInfo' ][ 'ParkId' ] = $this->park->park_id;
            $response[ 'ParkInfo' ][ 'ParkName' ] = $this->park->name;
            $response[ 'ParkInfo' ][ 'Abbreviation' ] = $this->park->abbreviation;
            $response[ 'ParkInfo' ][ 'HasHeraldry' ] = $this->park->has_heraldry;
            $response[ 'ParkInfo' ][ 'Url' ] = $this->park->url;
            $response[ 'ParkInfo' ][ 'Location' ] = $this->park->location;
            $response[ 'ParkInfo' ][ 'Active' ] = $this->park->active;
            $k = Ork3::$Lib->kingdom->GetKingdomShortInfo([ 'KingdomId' => $this->park->kingdom_id ]);
            if (0 == $k[ 'Status' ][ 'Status' ]) {
                $response[ 'KingdomInfo' ] = $k[ 'KingdomInfo' ];
            }
        } else {
            $response[ 'Status' ] = InvalidParameter();
        }
        return $response;
    }

    public function GetParkDetails($request)
    {
        $this->park->clear();
        $this->park->park_id = $request[ 'ParkId' ];
        $response = [ ];
        if ($this->park->find()) {
            $response[ 'Status' ] = Success();
            $response[ 'KingdomId' ] = $this->park->kingdom_id;
            $response[ 'ParkId' ] = $this->park->park_id;
            $response[ 'ParkName' ] = $this->park->name;
            $response[ 'Abbreviation' ] = $this->park->abbreviation;
            $response[ 'HasHeraldry' ] = $this->park->has_heraldry;
            global $DB;
            $DB->Clear();
            $_bn = $DB->DataSet("SELECT has_banner, banner_show_logo, banner_vignette, banner_offset_x, banner_offset_y FROM ork_park WHERE park_id = " . (int)$request['ParkId']);
            if ($_bn && $_bn->Next()) {
                $response['HasBanner']      = (int)$_bn->has_banner;
                $response['BannerShowLogo'] = (int)$_bn->banner_show_logo;
                $response['BannerVignette'] = (int)$_bn->banner_vignette;
                $response['BannerOffsetX']  = (int)$_bn->banner_offset_x;
                $response['BannerOffsetY']  = (int)$_bn->banner_offset_y;
            }
            $response[ 'ParkTitleId' ] = $this->park->parktitle_id;
            $parktitle = new yapo($this->db, DB_PREFIX . 'parktitle');
            $parktitle->parktitle_id = $this->park->parktitle_id;
            $parktitle->find();
            $response[ 'ParkTitle' ] = $parktitle->title;
            $response[ 'Active' ] = $this->park->active;
            $response[ 'Address' ] = $this->park->address;
            $response[ 'City' ] = $this->park->city;
            $response[ 'Province' ] = $this->park->province;
            $response[ 'PostalCode' ] = $this->park->postal_code;
            $response[ 'Url' ] = $this->park->url;
            $response[ 'MapUrl' ] = $this->park->map_url;
            $response[ 'Directions' ] = stripslashes(nl2br($this->park->directions));
            $response[ 'Description' ] = stripslashes(nl2br($this->park->description));
            $response[ 'GoogleGeocode' ] = $this->park->google_geocode;
            $response[ 'Location' ] = $this->park->location;
        } else {
            $response[ 'Status' ] = InvalidParameter();
        }
        return $response;
    }

    public function GetParkConfiguration($request)
    {
        return Common::get_configs($request[ 'ParkId' ], CFG_PARK);
    }

    public static function ExpandEveryXWeeks($start_date, $week_interval, DateTime $range_start, DateTime $range_end)
    {
        // Returns Y-m-d occurrences in [range_start, range_end) on the
        // "every X weeks" cadence anchored at $start_date. Half-open interval:
        // includes range_start, excludes range_end.
        $out = [];
        $interval = (int)$week_interval;
        if ($interval < 1) {
            return $out;
        }
        $step   = $interval * 7;
        $anchor = DateTime::createFromFormat('Y-m-d', substr((string)$start_date, 0, 10));
        if (!$anchor) {
            return $out;
        }
        $anchor->setTime(0, 0, 0);
        $rs = clone $range_start;
        $rs->setTime(0, 0, 0);
        $re = clone $range_end;
        $re->setTime(0, 0, 0);
        $cur = clone $anchor;
        if ($cur < $rs) {
            $daysBehind  = (int)$cur->diff($rs)->days;
            $stepsToSkip = intdiv($daysBehind, $step) * $step;
            if ($stepsToSkip > 0) {
                $cur->modify("+{$stepsToSkip} days");
            }
            while ($cur < $rs) {
                $cur->modify("+{$step} days");
            }
        }
        while ($cur < $re) {
            $out[] = $cur->format('Y-m-d');
            $cur->modify("+{$step} days");
        }
        return $out;
    }

    public static function CalculateNextParkDay($recurrence, $week_of_month, $month_day, $week_day, $from_date = null, $start_date = null, $week_interval = 0)
    {
        if (is_null($from_date)) {
            $from_date = strtotime(date("Y-m-d"));
        }
        switch ($recurrence) {
            case 'weekly':
                return date("Y-m-d", strtotime("next $week_day", $from_date));
            case 'week-of-month':
                switch ($week_of_month) {
                    case 1:
                        return date("Y-m-d", strtotime("first $week_day of " . date("F Y", $from_date), $from_date));
                    case 2:
                        return date("Y-m-d", strtotime("second $week_day of " . date("F Y", $from_date), $from_date));
                    case 3:
                        return date("Y-m-d", strtotime("third $week_day of " . date("F Y", $from_date), $from_date));
                    case 4:
                        return date("Y-m-d", strtotime("fourth $week_day of " . date("F Y", $from_date), $from_date));
                    case 5:
                        return date("Y-m-d", strtotime("fifth $week_day of " . date("F Y", $from_date), $from_date));
                }
                // no break
            case 'monthly':
                return date("Y-m-d", strtotime(date("F $month_day, Y", $from_date), $from_date));
            case 'every-x-weeks':
                $interval = max(1, (int)$week_interval);
                $step     = $interval * 7;
                $anchor   = strtotime(substr((string)$start_date, 0, 10));
                if ($anchor === false) {
                    return date("Y-m-d", $from_date);
                }
                if ($anchor >= $from_date) {
                    return date("Y-m-d", $anchor);
                }
                $daysBehind = floor(($from_date - $anchor) / 86400);
                $cycles     = (int)ceil($daysBehind / $step);
                return date("Y-m-d", strtotime("+" . ($cycles * $step) . " days", $anchor));
        }
    }

    public function GetParkDays($request)
    {
        $parkday = new yapo($this->db, DB_PREFIX . 'parkday');
        $parkday->clear();
        $parkday->park_id = $request[ 'ParkId' ];
        $response = [ 'Status' => Success(), 'ParkDays' => [ ] ];
        if (valid_id($request[ 'ParkId' ]) && $parkday->find()) {
            do {
                $response[ 'ParkDays' ][] = [
                    'ParkDayId'         => $parkday->parkday_id,
                    'ParkId'            => $parkday->park_id,
                    'Recurrence'        => $parkday->recurrence,
                    'WeekOfMonth'       => $parkday->week_of_month,
                    'WeekDay'           => $parkday->week_day,
                    'MonthDay'          => $parkday->month_day,
                    'StartDate'         => $parkday->start_date,
                    'WeekInterval'      => (int)$parkday->week_interval,
                    'Time'              => $parkday->time,
                    'Purpose'           => $parkday->purpose,
                    'Description'       => $parkday->description,
                    'AlternateLocation' => $parkday->alternate_location,
                    'Address'           => $parkday->address,
                    'City'              => $parkday->city,
                    'Province'          => $parkday->province,
                    'PostalCode'        => $parkday->postal_code,
                    'GoogleGeocode'     => $parkday->google_geocode,
                    'Location'          => $parkday->location,
                    'MapUrl'            => $parkday->map_url,
                    'LocationUrl'       => $parkday->location_url,
                    'Online'            => (int)$parkday->online,
                ];
            } while ($parkday->next());
        } else {
            $response[ 'Status' ] = InvalidParameter();
        }
        return $response;
    }

    public function PlayAmtgard($request)
    {
        $key = Ork3::$Lib->ghettocache->key($request);
        if (false && ($cache = Ork3::$Lib->ghettocache->get(__CLASS__ . '.' . __FUNCTION__, $key, 60)) !== false) {
            return $cache;
        }

        $latitude = $request['latitude'];
        $longitude = $request['longitude'];
        $start = isset($request['start']) ? date("Y-m-d", strtotime($request['start'])) : date("Y-m-d");
        $end = date("Y-m-d", strtotime($request['end']));
        $distance = isset($request['distance']) ? $request['distance'] : 25;
        $limit = isset($request['limit']) ? $request['limit'] : 12;

        $sql = "select * 
              from (
                SELECT 
                  d.*, p.kingdom_id, p.name park_name, k.name kingdom_name,
                  '$start' + interval mod(((c.day - weekday('$start')) + 7), 7) day next_day,
                  ( 3959 * acos( cos( radians($latitude) ) * cos( radians( d.latitude_d ) ) * cos( radians( d.longitude_d ) - radians($longitude) ) + sin( radians($latitude) ) * sin(radians(d.latitude_d)) ) ) AS distance 
                from 
                  ( select 
                      case sd.latitude when 0 then sp.latitude else sd.latitude end latitude_d,
                      case sd.longitude when 0 then sp.longitude else sd.longitude end longitude_d,
                      sd.*
                    from ork_parkday sd left join ork_park sp on sd.park_id = sp.park_id ) d
                  left join ork_day_convert c on d.week_day = c.dayname 
                  left join ork_park p on d.park_id = p.park_id
                    left join ork_kingdom k on p.kingdom_id = k.kingdom_id
                where
                  p.active = 'Active'
                having
                  next_day < '$end' and distance < $distance
                order by next_day asc, distance asc limit $limit) date_src";

        $r = $this->db->query($sql);
        $response = array();
        if ($r !== false && $r->size() > 0) {
            $response['ParkDays'] = array();
            while ($r->next()) {
                $response['ParkDays'][] = array(
                        'ParkdayId' => $r->parkday_id,
                        'KingdomId' => $r->kingdom_id,
                        'ParkId' => $r->park_id,
                        'ParkName' => $r->park_name,
                        'KingdomName' => $r->kingdom_name,
                        'Recurrence' => $r->recurrence,
                        'WeekOfMonth' => $r->week_of_month,
                        'WeekDay' => $r->week_day,
                        'MonthDay' => $r->month_day,
                        'Time' => $r->time,
                        'Purpose' => $r->purpose,
                        'Description' => $r->description,
                        'AlternateLocation' => $r->alternate_location,
                        'Address' => $r->address,
                        'City' => $r->city,
                        'Province' => $r->province,
                        'PostalCode' => $r->postal_code,
                        'GoogleGeocode' => $r->google_geocode,
                        'Latitude' => $r->latitude_d,
                        'Longitude' => $r->longitude_d,
                        'Location' => $r->location,
                        'MapUrl' => $r->map_url,
                        'LocationUrl' => $r->location_url,
                        'Distance' => $r->distance,
                        'NextDay' => $r->next_day
                    );
            }
            $response['Status'] = Success();
        } else {
            $response['Status'] = InvalidParameter();
        }
        return Ork3::$Lib->ghettocache->cache(__CLASS__ . '.' . __FUNCTION__, $key, $response);
    }

    public function GetParkAuthorizations($request)
    {
        $sql = "select authorization_id, username, a.mundane_id, role from " . DB_PREFIX . "authorization a left join " . DB_PREFIX . "mundane m on a.mundane_id = m.mundane_id where a.park_id = '" . mysql_real_escape_string($request[ 'ParkId' ]) . "' and system=0";
        $r = $this->db->query($sql);
        $response = [ ];
        $response[ 'Authorizations' ] = [ ];
        if ($r !== false && $r->size() > 0) {
            $response[ 'Status' ] = Success();
            while ($r->next()) {
                $response[ 'Authorizations' ][] = [
                    'AuthorizationId' => $r->authorization_id,
                    'UserName'        => $r->username,
                    'MundaneId'       => $r->mundane_id,
                    'Role'            => $r->role,
                ];
            }
        } else {
            $response[ 'Status' ] = InvalidParameter(null, 'Problem processing request.');
        }
        return $response;
    }

    public function park_geocode_h($geocode = null, & $parkobject = null)
    {
        $parkobject = is_null($parkobject) ? $this->park : $parkobject;
        logtrace('park_geocode_h', $parkobject);

        if (trimlen($geocode) > 0) {
            $details = Common::Geocode(null, null, null, null, $geocode);
        } else {
            $details = Common::Geocode($parkobject->address, $parkobject->city, $parkobject->province, $parkobject->postal_code);
        }
        if ($details[ 'status' ] == 'OVER_QUERY_LIMIT') {
            return;
        }
        $geocode = json_decode($details[ 'Geocode' ]);
        $parkobject->latitude = $geocode->results[ 0 ]->geometry->location->lat;
        $parkobject->longitude = $geocode->results[ 0 ]->geometry->location->lng;
        $parkobject->google_geocode = $details[ 'Geocode' ];
        $parkobject->location = $details[ 'Location' ];
        $parkobject->address = $details[ 'Address' ];
        if (isset($details[ 'City' ])) {
            $parkobject->city = $details[ 'City' ];
        }
        if (isset($details[ 'Province' ])) {
            $parkobject->province = $details[ 'Province' ];
        }
        if (isset($details[ 'PostalCode' ])) {
            $parkobject->postal_code = $details[ 'PostalCode' ];
        }
    }

    public function ParkGeocode($park_id)
    {
        $parkobject = is_null($parkobject) ? $this->park : $parkobject;

        $parkobject->clear();
        $parkobject->park_id = $park_id;
        if ($parkobject->find()) {
            do {
                if ($parkobject->park_id == $park_id && $this->park_geocode_h(null, $parkobject)) {
                    $parkobject->save();
                }
            } while ($parkobject->next());
        }
    }

    public function unique_username($name)
    {
        $srcname = $name;
        $found = false;
        while (!$found) {
            $this->park->clear();
            $this->park->name = $name;
            if ($this->park->find()) {
                $name = $srcname . '-' . substr(md5(microtime()), 0, 3);
            } else {
                $found = true;
            }
        }
        return $name;
    }

    public function CreatePark($request)
    {
        // Park creation is GLOBAL ADMIN ONLY, by design. Kingdom monarchy does not
        // create parks; neither do park-level officers. Cross-kingdom park
        // operations (TransferPark, MergeParks) are likewise admin-only.
        //
        // The scope id is passed as 0 deliberately. HasAuthority ignores the id for
        // AUTH_ADMIN -- only all-zero-scope admin grants ever match it -- so passing
        // a KingdomId here (as this call previously did) reads as a per-kingdom
        // check that does not exist. The explicit 0 states the actual semantics.
        $kingdom_id = (int)($request['KingdomId'] ?? 0);
        $mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token']);
        if ($mundane_id > 0 && valid_id($kingdom_id)
            && Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_ADMIN, 0, AUTH_CREATE)
        ) {
            $this->log->Write('Park', $mundane_id, LOG_ADD, $request);
            $request[ 'Name' ] = $this->unique_username(trim($request[ 'Name' ]));
            $this->park->clear();
            $this->park->kingdom_id     = $request[ 'KingdomId' ];
            $this->park->name           = $request[ 'Name' ];
            $this->park->abbreviation   = strtoupper($request[ 'Abbreviation' ]);
            $this->park->active         = self::ACTIVE_ACTIVE;
            $this->park->modified       = date("Y-m-d H:i:s", time());
            // Same defaulting rule as SetParkDetails: a title that is missing, blank,
            // nonexistent, or owned by another kingdom lands on DEFAULT_PARKTITLE_ID
            // instead of being written through unchecked.
            $this->park->parktitle_id   = $this->ResolveParkTitleId($request[ 'ParkTitleId' ] ?? 0, $kingdom_id);
            $this->park->url            = '';
            $this->park->address        = '';
            $this->park->city           = '';
            $this->park->province       = '';
            $this->park->postal_code    = '';
            $this->park->google_geocode = '';
            $this->park->latitude       = 0;
            $this->park->longitude      = 0;
            $this->park->location       = '';
            $this->park->map_url        = '';
            $this->park->description    = '';
            $this->park->directions     = '';
            $this->park->save();
            $new_park_id = (int)$this->park->park_id;
            $t = new Treasury();
            $t->create_accounts($mundane_id, 'park', $new_park_id, $request[ 'KingdomId' ]);
            $c = new Common();
            // Auths for a pricipality's officers travel with the mundane record, so we have to handle that @ the SetOfficer level
            $c->create_officers($request[ 'KingdomId' ], $new_park_id, 0);
            $c->create_events($request[ 'KingdomId' ], $new_park_id);
            if (strlen($request[ 'Heraldry' ])) {
                Ork3::$Lib->heraldry->SetParkHeraldry($request);
            }
            Ork3::$Lib->dangeraudit->audit(__CLASS__ . '::' . __FUNCTION__, $request, 'Park', $new_park_id, null, [
                'park_id'      => $new_park_id,
                'kingdom_id'   => (int)$request['KingdomId'],
                'name'         => $request['Name'],
                'abbreviation' => strtoupper($request['Abbreviation']),
                'parktitle_id' => (int)$this->park->parktitle_id,
            ]);
            Ork3::$Lib->report->bustKingdomParkAverageCaches((int) $request['KingdomId']);
            $response = Success($new_park_id);
        } else {
            $response = NoAuthorization();
        }
        return $response;
    }

    /**
     * Resolve a requested park title id against the kingdom that owns the park.
     *
     * Park titles are per-kingdom rows in ork_parktitle, keyed on parktitle.kingdom_id.
     * Callers used to check only that the id EXISTED, which let a park be handed a title
     * defined by some other kingdom -- the park then rendered a title its own kingdom had
     * never created, and nothing in the UI explained where it came from.
     *
     * The rule, implemented once here so every write site in this class inherits it:
     *   - id exists AND belongs to $kingdom_id  -> use it
     *   - id is 0 / blank / non-numeric         -> self::DEFAULT_PARKTITLE_ID
     *   - id does not exist                     -> self::DEFAULT_PARKTITLE_ID
     *   - id belongs to a different kingdom     -> self::DEFAULT_PARKTITLE_ID
     *
     * @param  mixed $requested_id Raw ParkTitleId off a request; may be null, '' or 0.
     * @param  mixed $kingdom_id   The kingdom the park belongs to AFTER this operation.
     * @return int                 A parktitle_id safe to write to ork_park.
     */
    public function ResolveParkTitleId($requested_id, $kingdom_id)
    {
        $requested_id = (int)$requested_id;
        $kingdom_id   = (int)$kingdom_id;
        if (!valid_id($requested_id) || !valid_id($kingdom_id)) {
            return self::DEFAULT_PARKTITLE_ID;
        }
        $parktitle = new yapo($this->db, DB_PREFIX . 'parktitle');
        $parktitle->clear();
        $parktitle->parktitle_id = $requested_id;
        if (!$parktitle->find()) {
            return self::DEFAULT_PARKTITLE_ID;
        }
        if ((int)$parktitle->kingdom_id !== $kingdom_id) {
            return self::DEFAULT_PARKTITLE_ID;
        }
        return $requested_id;
    }

    public function SetParkDetails($request)
    {
        logtrace("SetParkDetails", $request);
        $this->park->clear();
        if (trimlen($request[ 'Name' ]) > 0) {
            $this->park->name = trim($request[ 'Name' ]);
            if ($this->park->find()) {
                if ($this->park->park_id != $request[ 'ParkId' ]) {
                    return InvalidParameter('This park name already exists.');
                }
            }
        }
        $this->park->clear();
        $this->park->park_id = $request[ 'ParkId' ];
        if ($this->park->find()) {
            if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request[ 'Token' ])) > 0
                && Ork3::$Lib->authorizationgate->checkPermissionOrAuthority($mundane_id, 'park.details.edit', 'park', $request[ 'ParkId' ], AUTH_EDIT)
            ) {
                // Snapshot prior park state for the audit log before any field changes.
                $_audit_prior = [
                    'name'         => $this->park->name,
                    'abbreviation' => $this->park->abbreviation,
                    'parktitle_id' => (int)$this->park->parktitle_id,
                    'kingdom_id'   => (int)$this->park->kingdom_id,
                    'active'       => $this->park->active,
                    'url'          => $this->park->url,
                    'address'      => $this->park->address,
                    'city'         => $this->park->city,
                    'province'     => $this->park->province,
                    'postal_code'  => $this->park->postal_code,
                    'directions'   => $this->park->directions,
                    'description'  => $this->park->description,
                    'map_url'      => $this->park->map_url,
                ];
                $this->log->Write('Park', $mundane_id, LOG_EDIT, $request);
                $this->park->modified = date("Y-m-d H:i:s", time());

                if (Ork3::$Lib->authorizationgate->checkPermissionOrAuthority($mundane_id, 'park.details.edit', 'kingdom', $this->park->kingdom_id, AUTH_EDIT)) {
                    $this->park->name = trimlen($request[ 'Name' ]) == 0 ? $this->park->name : $request[ 'Name' ];
                    $this->park->abbreviation = trimlen($request[ 'Abbreviation' ]) == 0 ? strtoupper($this->park->abbreviation) : strtoupper($request[ 'Abbreviation' ]);
                    // Park title. Only touched when the request actually carries the key:
                    // several callers (the heraldry-only POST from ParkAjax, the address
                    // form in Admin) legitimately edit a park without ever showing a title
                    // picker, and resetting those to the default would be exactly the
                    // silent reassignment this guard exists to stop.
                    //
                    // When the key IS present, ResolveParkTitleId owns the outcome: a title
                    // that does not exist, or belongs to another kingdom, or was submitted
                    // as 0/blank, lands on DEFAULT_PARKTITLE_ID rather than being written
                    // through or silently left as-is.
                    if (array_key_exists('ParkTitleId', $request)) {
                        $this->park->parktitle_id = $this->ResolveParkTitleId($request[ 'ParkTitleId' ], $this->park->kingdom_id);
                    }
                    // NOTE: 'Active' is deliberately NOT read here. Retiring or restoring a
                    // park is a first-class operation with its own permission
                    // ('kingdom.park.retire', which 'park.details.edit' does not imply) and
                    // its own danger-audit row. Honouring an Active field on a details edit
                    // let any park-details editor retire a park through a plain LOG_EDIT,
                    // bypassing both. Use RetirePark()/RestorePark() instead; any Active
                    // value in this request is ignored.
                }

                $address_change = false;

                if (isset($request[ 'Address' ]) && ($this->park->address != $request[ 'Address' ] || trimlen($this->park->location) == 0)) {
                    $address_change = true;
                }

                $this->park->url = isset($request[ 'Url' ]) ? ($request[ 'Url' ]) : $this->park->url;
                $this->park->address = isset($request[ 'Address' ]) ? ($request[ 'Address' ]) : $this->park->address;
                $this->park->city = isset($request[ 'City' ]) ? ($request[ 'City' ]) : $this->park->city;
                $this->park->province = isset($request[ 'Province' ]) ? ($request[ 'Province' ]) : $this->park->province;
                $this->park->postal_code = isset($request[ 'PostalCode' ]) ? ($request[ 'PostalCode' ]) : $this->park->postal_code;
                $this->park->directions = isset($request[ 'Directions' ]) ? ($request[ 'Directions' ]) : $this->park->directions;
                $this->park->description = isset($request[ 'Description' ]) ? ($request[ 'Description' ]) : $this->park->description;
                $this->park->map_url = isset($request[ 'MapUrl' ]) ? ($request[ 'MapUrl' ]) : $this->park->map_url;

                $this->park->save();
                $this->park->clear();
                $this->park->park_id = $request[ 'ParkId' ];
                if ($this->park->find()) {

                    if ($address_change) {
                        if (isset($request[ 'GeoCode' ]) && trimlen($request[ 'GeoCode' ]) > 0) {
                            $this->park_geocode_h($request[ 'GeoCode' ]);
                        } else {
                            $this->park_geocode_h();
                        }
                    }

                    if ($request[ 'KingdomId' ] > 0 && $this->park->kingdom_id != $request[ 'KingdomId' ]) {
                        // Seriously? You couldn't work it out somehow?
                        // AKA Blackspire Code, AKA Golden Plains Exception
                        if (Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_ADMIN, $request[ 'KingdomId' ], AUTH_ADMIN)) {
                            $this->park->kingdom_id = $request[ 'KingdomId' ];
                            // The park's title was defined by the kingdom it just left.
                            // Re-resolve against the destination so the park cannot go on
                            // displaying a title its new kingdom never created.
                            $this->park->parktitle_id = $this->ResolveParkTitleId(
                                $request[ 'ParkTitleId' ] ?? $this->park->parktitle_id,
                                $this->park->kingdom_id
                            );
                        } else {
                            $response = Warning('You do not have permissions to move this Park [' . $this->park->park_id . ', ' . $this->park->kingdom_id . '] to another Kingdom [' . $request[ 'KingdomId' ] . '].');
                        }
                    }

                    if (strlen($request[ 'Heraldry' ])) {
                        Ork3::$Lib->heraldry->SetParkHeraldry($request);
                    }

                    $this->park->save();
                    $_audit_post = [
                        'name'         => $this->park->name,
                        'abbreviation' => $this->park->abbreviation,
                        'parktitle_id' => (int)$this->park->parktitle_id,
                        'kingdom_id'   => (int)$this->park->kingdom_id,
                        'active'       => $this->park->active,
                        'url'          => $this->park->url,
                        'address'      => $this->park->address,
                        'city'         => $this->park->city,
                        'province'     => $this->park->province,
                        'postal_code'  => $this->park->postal_code,
                        'directions'   => $this->park->directions,
                        'description'  => $this->park->description,
                        'map_url'      => $this->park->map_url,
                    ];
                    $_audit_req = $request;
                    unset($_audit_req[ 'Token' ], $_audit_req[ 'GeoCode' ]);
                    $_heraldry_uploaded = !empty($request[ 'Heraldry' ]);
                    if ($_heraldry_uploaded) {
                        $_audit_req[ 'Heraldry' ] = [ 'uploaded' => true, 'bytes' => strlen((string)$request[ 'Heraldry' ]) ];
                    } else {
                        unset($_audit_req[ 'Heraldry' ]);
                    }
                    // Skip the audit row when nothing actually changed. The Kingdom Park
                    // configuration page POSTs SetParkDetails for every park on save,
                    // not just the one the user touched — without this suppression that
                    // produces N rows per click for N parks.
                    $_changed = $_heraldry_uploaded;
                    if (!$_changed) {
                        foreach ($_audit_prior as $_k => $_v) {
                            if ((string)($_audit_post[ $_k ] ?? '') !== (string)$_v) {
                                $_changed = true;
                                break;
                            }
                        }
                    }
                    if ($_changed) {
                        Ork3::$Lib->dangeraudit->audit(__CLASS__ . '::' . __FUNCTION__, $_audit_req, 'Park', (int)$this->park->park_id, $_audit_prior, $_audit_post);
                    }
                    $response = Success($this->park->park_id);
                } else {
                    $response = InvalidParameter('ParkId could not be found.');
                }
            } else {
                $response = NoAuthorization('You do not have permissions to perform this action: ' . $mundane_id);
            }
        } else {
            $response = InvalidParameter('ParkId could not be found.');
        }
        return $response;
    }

    public function SetOfficer($request)
    {
        // Check for Principality Details, and create auths for Principality concurrently
        $response = [ ];
        if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request[ 'Token' ])) > 0
            && Ork3::$Lib->authorizationgate->checkPermissionOrAuthority($mundane_id, 'park.officer.set', 'park', $request[ 'ParkId' ], AUTH_EDIT)
        ) {
            if (!isset($request['KingdomId'])) {
                if (!isset($request['ParkId'])) {
                    return InvalidParameter('Either ParkId or KingdomId must be set to update officers.');
                }
                $kingdomId = $this->GetParkKingdomId($request[ 'ParkId' ]);
            } else {
                $kingdomId = $request['KingdomId'];
            }
            // Look up the current officer first so we can suppress the audit when
            // the UI re-submits an unchanged assignment (the Bellhollow form fires
            // SetOfficer once per role on every save).
            $_positionId = Ork3::$Lib->officerposition->ResolvePositionId((int)$kingdomId, $request[ 'Role' ]);
            $_canonicalKey = Ork3::$Lib->officerposition->ResolveCanonicalKey((int)$kingdomId, $request[ 'Role' ]);

            $_priorOfficer = new yapo($this->db, DB_PREFIX . 'officer');
            $_priorOfficer->clear();
            $_priorOfficer->park_id = (int)$request[ 'ParkId' ];
            if ($_positionId > 0) {
                $_priorOfficer->position_id = $_positionId;
            } else {
                $_priorOfficer->role = $request[ 'Role' ];
            }
            $_priorMundaneId = $_priorOfficer->find() ? (int)$_priorOfficer->mundane_id : 0;

            $c = new Common();
            $c->set_officer($kingdomId, $request[ 'ParkId' ], $request[ 'MundaneId' ], $_canonicalKey, 0, $mundane_id, $_positionId);

            if ($_priorMundaneId !== (int)$request[ 'MundaneId' ]) {
                $_audit_req = $request;
                unset($_audit_req[ 'Token' ]);
                Ork3::$Lib->dangeraudit->audit(
                    __CLASS__ . '::' . __FUNCTION__,
                    $_audit_req,
                    'Park',
                    (int)$request[ 'ParkId' ],
                    [ 'MundaneId' => $_priorMundaneId, 'Role' => $request[ 'Role' ] ],
                    [
                        'ParkId'    => (int)$request[ 'ParkId' ],
                        'KingdomId' => (int)$kingdomId,
                        'MundaneId' => (int)$request[ 'MundaneId' ],
                        'Role'      => $request[ 'Role' ],
                    ]
                );
            }
        } else {
            $response = NoAuthorization();
        }
        return $response;
    }

    public function VacateOfficer($request)
    {
        $response = [ ];
        if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request[ 'Token' ])) > 0
            && Ork3::$Lib->authorizationgate->checkPermissionOrAuthority($mundane_id, 'park.officer.vacate', 'park', $request[ 'ParkId' ], AUTH_EDIT)
        ) {
            $kingdomId = $this->GetParkKingdomId($request[ 'ParkId' ]);
            $_positionId = Ork3::$Lib->officerposition->ResolvePositionId((int)$kingdomId, $request[ 'Role' ]);
            $_canonicalKey = Ork3::$Lib->officerposition->ResolveCanonicalKey((int)$kingdomId, $request[ 'Role' ]);

            $_priorOfficer = new yapo($this->db, DB_PREFIX . 'officer');
            $_priorOfficer->clear();
            $_priorOfficer->park_id = (int)$request[ 'ParkId' ];
            if ($_positionId > 0) {
                $_priorOfficer->position_id = $_positionId;
            } else {
                $_priorOfficer->role = $request[ 'Role' ];
            }
            $_priorMundaneId = $_priorOfficer->find() ? (int)$_priorOfficer->mundane_id : 0;

            $c = new Common();
            $c->set_officer($kingdomId, $request[ 'ParkId' ], 0, $_canonicalKey, 0, $mundane_id, $_positionId);

            if ($_priorMundaneId > 0) {
                $_audit_req = $request;
                unset($_audit_req[ 'Token' ]);
                Ork3::$Lib->dangeraudit->audit(
                    __CLASS__ . '::' . __FUNCTION__,
                    $_audit_req,
                    'Park',
                    (int)$request[ 'ParkId' ],
                    [ 'MundaneId' => $_priorMundaneId, 'Role' => $request[ 'Role' ] ],
                    [
                        'ParkId'    => (int)$request[ 'ParkId' ],
                        'KingdomId' => (int)$kingdomId,
                        'Role'      => $request[ 'Role' ],
                    ]
                );
            }
        } else {
            $response = NoAuthorization();
        }
        return $response;
    }

    public function GetOfficerHistory($request)
    {
        $park_id = (int)$request[ 'ParkId' ];
        $role_filter = isset($request[ 'Role' ]) && strlen(trim($request[ 'Role' ])) > 0 ? trim($request[ 'Role' ]) : null;

        // Look up the kingdom_id for this park
        $kingdom_id = (int)$this->GetParkKingdomId($park_id);

        $sql = "SELECT oh.officer_history_id, oh.kingdom_id, oh.park_id, oh.mundane_id, oh.role,
		                oh.start_date, oh.end_date, oh.changed_by, oh.notes, oh.created_at,
		                m.persona, m.username,
		                cb.persona AS changed_by_persona
		         FROM " . DB_PREFIX . "officer_history oh
		         LEFT JOIN " . DB_PREFIX . "mundane m ON m.mundane_id = oh.mundane_id
		         LEFT JOIN " . DB_PREFIX . "mundane cb ON cb.mundane_id = oh.changed_by
		         WHERE oh.park_id = " . $park_id . " AND oh.kingdom_id = " . $kingdom_id;

        if ($role_filter !== null) {
            // Bound, never interpolated -- mysql_real_escape_string() is a no-op shim here.
            $sql .= " AND oh.role = :oh_role";
        }

        $sql .= " ORDER BY oh.role, oh.start_date DESC, oh.officer_history_id DESC";

        global $DB;
        $DB->Clear();
        if ($role_filter !== null) {
            $DB->oh_role = $role_filter;
        }
        $r = $DB->DataSet($sql);
        $response = [ 'Status' => Success(), 'History' => [ ] ];
        if ($r !== false && $r->size() > 0) {
            while ($r->next()) {
                $response[ 'History' ][] = [
                    'OfficerHistoryId' => (int)$r->officer_history_id,
                    'KingdomId'        => (int)$r->kingdom_id,
                    'ParkId'           => (int)$r->park_id,
                    'MundaneId'        => (int)$r->mundane_id,
                    'Role'             => $r->role,
                    'StartDate'        => $r->start_date,
                    'EndDate'          => $r->end_date,
                    'ChangedBy'        => (int)$r->changed_by,
                    'ChangedByPersona' => $r->changed_by_persona ?? '',
                    'Notes'            => $r->notes ?? '',
                    'Persona'          => $r->persona ?? '',
                    'UserName'         => $r->username ?? '',
                ];
            }
        }
        return $response;
    }

    public function AddOfficerHistory($request)
    {
        $response = [ ];
        if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request[ 'Token' ])) > 0
            && Ork3::$Lib->authorizationgate->checkPermissionOrAuthority($mundane_id, 'park.officer_history.manage', 'park', $request[ 'ParkId' ], AUTH_EDIT)
        ) {
            $pid       = (int)$request[ 'ParkId' ];
            $kid       = (int)$this->GetParkKingdomId($pid);
            $mid       = (int)$request[ 'MundaneId' ];
            $role      = trim($request[ 'Role' ] ?? '');
            $start     = trim($request[ 'StartDate' ] ?? '');
            $end       = trim($request[ 'EndDate' ] ?? '');
            $notes_raw = isset($request[ 'Notes' ]) ? trim($request[ 'Notes' ]) : '';

            if ($mid <= 0 || strlen($role) === 0 || strlen($start) === 0) {
                return InvalidParameter(null, 'MundaneId, Role, and StartDate are required.');
            }

            // Bound, not concatenated -- mysql_real_escape_string() is a no-op shim in this
            // codebase (startup.php: `return $str;`), so the previous form was an injection.
            global $DB;
            $DB->Clear();
            $DB->oh_kid   = $kid;
            $DB->oh_pid   = $pid;
            $DB->oh_mid   = $mid;
            $DB->oh_role  = $role;
            $DB->oh_start = $start;
            $DB->oh_end   = strlen($end) > 0 ? $end : null;
            $DB->oh_cb    = (int)$mundane_id;
            $DB->oh_notes = strlen($notes_raw) > 0 ? $notes_raw : null;
            $DB->Execute(
                "INSERT INTO " . DB_PREFIX . "officer_history
				 (kingdom_id, park_id, mundane_id, role, start_date, end_date, changed_by, notes, created_at)
				 VALUES (:oh_kid, :oh_pid, :oh_mid, :oh_role, :oh_start, :oh_end, :oh_cb, :oh_notes, NOW())"
            );
            $response = Success();
        } else {
            $response = NoAuthorization();
        }
        return $response;
    }

    public function EditOfficerHistory($request)
    {
        $response = [ ];
        if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request[ 'Token' ])) > 0
            && Ork3::$Lib->authorizationgate->checkPermissionOrAuthority($mundane_id, 'park.officer_history.manage', 'park', $request[ 'ParkId' ], AUTH_EDIT)
        ) {
            $ohid      = (int)$request[ 'OfficerHistoryId' ];
            $pid       = (int)$request[ 'ParkId' ];
            $role      = trim($request[ 'Role' ] ?? '');
            $start     = trim($request[ 'StartDate' ] ?? '');
            $end       = trim($request[ 'EndDate' ] ?? '');
            $notes_raw = isset($request[ 'Notes' ]) ? trim($request[ 'Notes' ]) : '';

            if ($ohid <= 0 || strlen($role) === 0 || strlen($start) === 0) {
                return InvalidParameter(null, 'OfficerHistoryId, Role, and StartDate are required.');
            }

            // Bound, not concatenated -- see AddOfficerHistory.
            global $DB;
            $DB->Clear();
            $DB->oh_role  = $role;
            $DB->oh_start = $start;
            $DB->oh_end   = strlen($end) > 0 ? $end : null;
            $DB->oh_notes = strlen($notes_raw) > 0 ? $notes_raw : null;
            $DB->oh_id    = $ohid;
            $DB->oh_pid   = $pid;
            $DB->Execute(
                "UPDATE " . DB_PREFIX . "officer_history
				 SET role = :oh_role, start_date = :oh_start, end_date = :oh_end, notes = :oh_notes
				 WHERE officer_history_id = :oh_id
				   AND park_id = :oh_pid"
            );
            $response = Success();
        } else {
            $response = NoAuthorization();
        }
        return $response;
    }

    public function DeleteOfficerHistory($request)
    {
        $response = [ ];
        if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request[ 'Token' ])) > 0
            && Ork3::$Lib->authorizationgate->checkPermissionOrAuthority($mundane_id, 'park.officer_history.manage', 'park', $request[ 'ParkId' ], AUTH_EDIT)
        ) {
            $ohid = (int)$request[ 'OfficerHistoryId' ];
            $pid  = (int)$request[ 'ParkId' ];

            if ($ohid <= 0) {
                return InvalidParameter(null, 'OfficerHistoryId is required.');
            }

            global $DB;
            $DB->Clear();
            $DB->Execute(
                "DELETE FROM " . DB_PREFIX . "officer_history
				 WHERE officer_history_id = " . $ohid . "
				   AND park_id = " . $pid
            );
            $response = Success();
        } else {
            $response = NoAuthorization();
        }
        return $response;
    }

    /**
     * Retire one park. FIRST-CLASS ENTRY POINT -- this is the only supported way to
     * take a park out of service.
     *
     * Contract (shared with RestorePark, see WafflePark for the implementation):
     *   Request  : [ 'Token' => session token, 'ParkId' => int ]
     *   Permission: 'kingdom.park.retire' on the park's OWN kingdom (read off the park
     *               row, never off the request), AUTH_EDIT. Note that
     *               'park.details.edit' does NOT imply this -- retiring a park is not a
     *               details edit, which is why SetParkDetails no longer honours Active.
     *   Writes    : ork_park.active = 'Retired', a LOG_RETIRE entry, and a danger-audit
     *               row recorded under 'Park::RetirePark'.
     *   Returns   : Success($detail) where $detail is a sentence naming the park, so a
     *               caller running this per-park across a selection can surface a
     *               readable per-park result. Errors are NoAuthorization() or
     *               InvalidParameter() with a naming detail where one is known.
     *   Idempotent: retiring an already-retired park succeeds and writes nothing.
     *
     * @param  array $request
     * @return array Status array (Status / Error / Detail).
     */
    public function RetirePark($request)
    {
        return $this->WafflePark($request, self::ACTIVE_RETIRED);
    }

    /**
     * Restore one retired park to active service. FIRST-CLASS ENTRY POINT, and the exact
     * mirror of RetirePark -- same request shape, same 'kingdom.park.retire' permission,
     * same danger audit (recorded under 'Park::RestorePark'), a LOG_RESTORE entry, and a
     * Success() detail naming the park. Restoring an already-active park succeeds and
     * writes nothing.
     *
     * @param  array $request [ 'Token' => session token, 'ParkId' => int ]
     * @return array Status array (Status / Error / Detail).
     */
    public function RestorePark($request)
    {
        return $this->WafflePark($request, self::ACTIVE_ACTIVE);
    }

    /**
     * Shared implementation behind RetirePark() and RestorePark().
     *
     * Prefer calling RetirePark()/RestorePark(): they name the direction, they are what
     * the SOAP surface exposes, and they cannot be handed a bogus state. This stays
     * public only because it is the historic name; it is not the intended entry point.
     *
     * @param  array  $request [ 'Token', 'ParkId' ]
     * @param  string $waffle  self::ACTIVE_ACTIVE or self::ACTIVE_RETIRED.
     * @return array  Status array (Status / Error / Detail).
     */
    public function WafflePark($request, $waffle)
    {
        // ork_park.active is an enum; anything else would be coerced to '' by MySQL.
        if (self::ACTIVE_ACTIVE !== $waffle && self::ACTIVE_RETIRED !== $waffle) {
            return InvalidParameter(null, 'Unknown park state requested.');
        }

        $park_id = (int)($request[ 'ParkId' ] ?? 0);
        if (!valid_id($park_id)) {
            return InvalidParameter(null, 'A ParkId is required.');
        }

        $this->park->clear();
        $this->park->park_id = $park_id;
        if (!$this->park->find()) {
            return InvalidParameter(null, 'Park #' . $park_id . ' could not be found.');
        }

        // Name the park for the caller's per-park result. Read before any write so the
        // label is right even when nothing changes.
        $park_label = trim((string)$this->park->name);
        if (strlen((string)$this->park->abbreviation)) {
            $park_label .= ' (' . strtoupper((string)$this->park->abbreviation) . ')';
        }
        if (0 === strlen(trim($park_label))) {
            $park_label = 'Park #' . $park_id;
        }

        $mundane_id = Ork3::$Lib->authorization->IsAuthorized($request[ 'Token' ]);
        // Authorize against the kingdom that actually owns the park, taken off the row,
        // not off anything the caller supplied.
        if ($mundane_id <= 0
            || !Ork3::$Lib->authorizationgate->checkPermissionOrAuthority($mundane_id, 'kingdom.park.retire', 'kingdom', $this->park->kingdom_id, AUTH_EDIT)
        ) {
            return NoAuthorization('You do not have permission to change the status of ' . $park_label . '.');
        }

        $_prior_active = $this->park->active;
        $verb = (self::ACTIVE_ACTIVE === $waffle) ? 'restored' : 'retired';

        // Idempotent no-op. Bulk callers hand this whole selections, most of which are
        // already in the requested state; writing a LOG entry and a danger-audit row for
        // each of those buries the real changes in noise.
        if ($_prior_active === $waffle) {
            return Success($park_label . ' was already ' . $verb . '.');
        }

        $this->log->Write('Park', $mundane_id, self::ACTIVE_ACTIVE === $waffle ? LOG_RESTORE : LOG_RETIRE, $request);
        $this->park->active = $waffle;
        $this->park->save();

        $_audit_req = $request;
        unset($_audit_req[ 'Token' ]);
        // Synthetic method name so the audit log distinguishes Retire from Restore.
        $_call = (self::ACTIVE_ACTIVE === $waffle) ? (__CLASS__ . '::RestorePark') : (__CLASS__ . '::RetirePark');
        Ork3::$Lib->dangeraudit->audit($_call, $_audit_req, 'Park', $park_id, [ 'active' => $_prior_active ], [ 'active' => $waffle ]);

        return Success($park_label . ' has been ' . $verb . '.');
    }
}
