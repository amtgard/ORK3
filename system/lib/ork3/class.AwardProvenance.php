<?php

/**
 * Where one award on a player's ledger came from: the recommendations it granted,
 * and the court line that conferred it (court, scroll and regalia makers, other
 * artisans). Backs the (i) info modal on the player profile's Awards and Titles
 * tables. startup.php instantiates every class here, so this is reachable as
 * Ork3::$Lib->awardprovenance.
 *
 * NOT whitelisted in orkservice/Json, and the one reader is underscored besides
 * (JsonServer refuses any method name containing "_"), so nothing here is
 * reachable from the public JSON service. The viewer comes in explicitly from the
 * UI session; nothing reads RequestedBy.
 *
 * Recommendations shown: only recs that were GRANTED (resolution = 'granted') for
 * this award at this level, and only those the RECIPIENT could see about
 * themselves — never a Monarchy Only rec (RecommendationVisibility hides every
 * hidden level from the recipient). A rec snoozed and then granted anyway still
 * shows: the grant settles it. All of that is intersected with what
 * the actual viewer may see. A masked giver stays masked for every viewer: no
 * name, no id.
 *
 * Court details mirror the login-free court report (Court::getCourtReportDetail):
 * only a FINALIZED court ('complete'), only the line marked 'given', never a
 * revoked award. A certificate printed through Scrollery has no scroll maker, so
 * that half is withheld. Print state (PrintedWithScrollery / LastPrintedAt) is
 * top-level, for every award, court or not.
 */
class AwardProvenance extends Ork3
{
    /**
     * Awards whose award_id is a generic catch-all (Custom Award, Custom Title);
     * matching recs on it would pull in unrelated honours. Public: Player's
     * backing-award rule skips canonical matches on these too.
     */
    public const GENERIC_AWARD_IDS = [94, 246];

    /**
     * One award's provenance for $viewer (the UI session's user; 0 = anonymous).
     * Null when the award does not exist or is revoked (the profile lists neither
     * to the public).
     */
    public function award_provenance(int $awardsId, int $viewer): ?array
    {
        if ($awardsId <= 0) {
            return null;
        }
        $award = $this->loadAward($awardsId);
        if ($award === null) {
            return null;
        }
        $recsShown = $this->recsVisibleTo($viewer, $award);
        $court     = $this->courtLine($award);
        // Print state belongs to every award, not just finalized-court ones: most
        // scrolls are printed straight off the profile or the bulk tools. The court
        // line already read it (against its own line id); otherwise fall back to the
        // line the award row names, if any.
        $lastPrinted = $court !== null
            ? $court['LastPrintedAt']
            : $this->lastPrintedAt($awardsId, $award['CourtAwardId']);

        return [
            'AwardsId'        => $awardsId,
            'AwardName'       => $award['AwardName'],
            'Rank'            => $award['IsLadder'] ? $award['Rank'] : 0,
            'IsLadder'        => $award['IsLadder'],
            'RecsShown'       => $recsShown,
            // Why recs are withheld, for the empty state: 'login' (anonymous) or 'private'.
            'RecsWithheld'    => $recsShown ? '' : ($viewer > 0 ? 'private' : 'login'),
            'Recommendations' => $recsShown ? $this->grantedRecs($award, $viewer) : [],
            'Court'           => $court,
            'PrintedWithScrollery' => $lastPrinted !== '',
            'LastPrintedAt'   => $lastPrinted,
        ];
    }

    /**
     * Logged in, and the profile's recs gate (RecommendationVisibility::
     * profileRecsVisible, shared with the Recommendations tab) passes.
     */
    private function recsVisibleTo(int $viewer, array $award): bool
    {
        return $viewer > 0
            && Ork3::$Lib->recommendationvisibility->profileRecsVisible($viewer, $award['KingdomOfRecipient'], $award['ParkOfRecipient']);
    }

    private function loadAward(int $awardsId): ?array
    {
        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT aw.awards_id, aw.mundane_id, aw.kingdomaward_id, aw.award_id, aw.rank, aw.court_award_id,
                    aw.custom_name, aw.date,
                    GREATEST(aw.date, IF(aw.entered_at > \'1000-01-01\', DATE(aw.entered_at), aw.date)) AS window_end,
                    IFNULL(ka.name, a.name) AS award_name,
                    IFNULL(m.kingdom_id, 0) AS recip_kingdom, IFNULL(m.park_id, 0) AS recip_park,
                    ' . Award::sqlEffectiveIsLadder('ka', 'a') . ' AS is_ladder
               FROM ' . DB_PREFIX . 'awards aw
               LEFT JOIN ' . DB_PREFIX . 'mundane m ON m.mundane_id = aw.mundane_id
               LEFT JOIN ' . DB_PREFIX . 'kingdomaward ka ON ka.kingdomaward_id = aw.kingdomaward_id
               LEFT JOIN ' . DB_PREFIX . 'award a ON a.award_id = IF(aw.award_id > 0, aw.award_id, ka.award_id)
              WHERE aw.awards_id = ' . $awardsId . ' AND aw.revoked = 0 AND aw.mundane_id > 0
              LIMIT 1'
        );
        if (!$rs || !$rs->Next()) {
            return null;
        }
        $custom = trim((string) ($rs->custom_name ?? ''));

        $award = [
            'AwardsId'       => (int) $rs->awards_id,
            'MundaneId'      => (int) $rs->mundane_id,
            'KingdomAwardId' => (int) $rs->kingdomaward_id,
            'AwardId'        => (int) $rs->award_id,
            'Rank'           => (int) $rs->rank,
            'CourtAwardId'   => (int) ($rs->court_award_id ?? 0),
            'AwardName'      => $custom !== '' ? $custom : (string) ($rs->award_name ?? ''),
            'IsLadder'       => (int) $rs->is_ladder === 1,
            'KingdomOfRecipient' => (int) $rs->recip_kingdom,
            'ParkOfRecipient'    => (int) $rs->recip_park,
            'Date'           => (string) $rs->date,
            'WindowEnd'      => (string) $rs->window_end,
        ];
        $award['CourtRecId']  = $this->courtLineRecId($award);
        $award['WindowStart'] = $award['CourtRecId'] > 0 ? '' : $this->previousInstanceDate($award);

        return $award;
    }

    /**
     * The rec the court line that conferred this award was drawn from (0 if none):
     * when there is one, it IS the rec behind this award.
     */
    private function courtLineRecId(array $award): int
    {
        $lineClause = 'ca.award_id = ' . (int) $award['AwardsId'];
        if ($award['CourtAwardId'] > 0) {
            $lineClause = '(' . $lineClause . ' OR ca.court_award_id = ' . (int) $award['CourtAwardId'] . ')';
        }
        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT ca.recommendations_id FROM ' . DB_PREFIX . 'court_award ca
              WHERE ' . $lineClause . '
                AND ca.mundane_id = ' . (int) $award['MundaneId'] . '
                AND ca.recommendations_id > 0
              ORDER BY ca.court_award_id DESC LIMIT 1'
        );

        return ($rs && $rs->Next()) ? (int) $rs->recommendations_id : 0;
    }

    /**
     * Date of this player's previous live instance of the same award (same
     * kingdomaward or same non-generic canonical award; ladder: the same rank),
     * ordered by award date then id. '' when this is the first. Recs on or before
     * it were behind that earlier award, not this one.
     */
    private function previousInstanceDate(array $award): string
    {
        $or = ['p.kingdomaward_id = ' . (int) $award['KingdomAwardId']];
        if ($award['AwardId'] > 0 && !in_array($award['AwardId'], self::GENERIC_AWARD_IDS, true)) {
            $or[] = 'p.award_id = ' . (int) $award['AwardId'];
        }
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $award['Date']) ? $award['Date'] : '0000-00-00';
        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT p.date FROM ' . DB_PREFIX . 'awards p
              WHERE p.mundane_id = ' . (int) $award['MundaneId'] . '
                AND COALESCE(p.revoked, 0) = 0
                AND p.awards_id <> ' . (int) $award['AwardsId'] . '
                AND (' . implode(' OR ', $or) . ')
                ' . ($award['IsLadder'] ? 'AND p.rank = ' . (int) $award['Rank'] : '') . "
                AND (p.date < '" . $date . "' OR (p.date = '" . $date . "' AND p.awards_id < " . (int) $award['AwardsId'] . '))
              ORDER BY p.date DESC, p.awards_id DESC LIMIT 1'
        );

        return ($rs && $rs->Next()) ? (string) $rs->date : '';
    }

    /**
     * Which recs are the recs behind THIS award row. Pure, for tests.
     *
     *   - CourtRecId > 0: the court line that conferred it was drawn from that rec,
     *     so that rec alone.
     *   - otherwise "for this award": the same kingdom award, or — for a specific
     *     (non-generic) award — the same award recommended under another kingdom's
     *     name for it; ladder: the same rank only. Then, when known, only recs made
     *     no later than this award was given/entered (WindowEnd) and after the
     *     previous instance of it (WindowStart), so a player's second Order of the
     *     Rose does not list the recs that earned the first.
     */
    public static function recMatchSql(array $award, string $alias = 'r'): string
    {
        $courtRec = (int) ($award['CourtRecId'] ?? 0);
        if ($courtRec > 0) {
            return "$alias.recommendations_id = $courtRec";
        }
        $ka   = (int) ($award['KingdomAwardId'] ?? 0);
        $aid  = (int) ($award['AwardId'] ?? 0);
        $or   = [];
        if ($ka > 0) {
            $or[] = "$alias.kingdomaward_id = $ka";
        }
        if ($aid > 0 && !in_array($aid, self::GENERIC_AWARD_IDS, true)) {
            $or[] = "$alias.award_id = $aid";
        }
        if (!$or) {
            return '0 = 1';
        }
        $sql = '(' . implode(' OR ', $or) . ')';
        if (!empty($award['IsLadder'])) {
            $sql .= " AND COALESCE($alias.rank, 0) = " . (int) ($award['Rank'] ?? 0);
        }
        $end   = (string) ($award['WindowEnd'] ?? '');
        $start = (string) ($award['WindowStart'] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
            $sql .= " AND $alias.date_recommended <= '$end'";
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) {
            $sql .= " AND $alias.date_recommended > '$start'";
        }

        return $sql;
    }

    /**
     * The public shape of one rec row. A masked giver is "Anonymous" for every
     * viewer — the id goes too, so the link cannot leak it. Pure, for tests.
     */
    public static function shapeRec(array $row): array
    {
        $masked = (int) ($row['mask_giver'] ?? 0) === 1;

        return [
            'RecommendationsId' => (int) ($row['recommendations_id'] ?? 0),
            'IsAnonymous'       => $masked,
            'RecommendedById'   => $masked ? 0 : (int) ($row['recommended_by_id'] ?? 0),
            'RecommendedBy'     => $masked ? '' : (string) ($row['recommender_persona'] ?? ''),
            'DateRecommended'   => (string) ($row['date_recommended'] ?? ''),
            'Reason'            => (string) ($row['reason'] ?? ''),
            'Seconds'           => (int) ($row['seconds'] ?? 0),
        ];
    }

    private function grantedRecs(array $award, int $viewer): array
    {
        $recipient = $award['MundaneId'];
        $vis = Ork3::$Lib->recommendationvisibility;
        $recipKingdom = $award['KingdomOfRecipient'];
        $recipPark    = $award['ParkOfRecipient'];
        // Both predicates are built BEFORE the Clear() below: sqlPredicate may run
        // authority lookups on the shared handle (it cleans up after itself).
        $asRecipient = $vis->sqlPredicate($recipient, 'r', 'm', [$recipKingdom]);
        $asViewer    = $vis->sqlPredicate($viewer, 'r', 'm', [$recipKingdom]);

        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT r.recommendations_id, r.recommended_by_id, r.date_recommended, r.reason, r.mask_giver,
                    r.monarchy_only, r.passed_to_local, rbi.persona AS recommender_persona,
                    (SELECT COUNT(*) FROM ' . DB_PREFIX . 'recommendation_seconds s
                      WHERE s.recommendations_id = r.recommendations_id
                        AND (s.deleted_at IS NULL OR (r.deleted_at IS NOT NULL AND s.deleted_at >= r.deleted_at))) AS seconds
               FROM ' . DB_PREFIX . 'recommendations r
               JOIN ' . DB_PREFIX . 'mundane m ON m.mundane_id = r.mundane_id
               LEFT JOIN ' . DB_PREFIX . 'mundane rbi ON rbi.mundane_id = r.recommended_by_id
              WHERE r.mundane_id = ' . $recipient . '
                AND r.resolution = \'' . Player::REC_RESOLUTION_GRANTED . '\'
                AND ' . self::recMatchSql($award, 'r') . '
                AND ' . $asRecipient . '
                AND ' . $asViewer . '
              ORDER BY r.date_recommended ASC, r.recommendations_id ASC
              LIMIT 100'
        );
        $out = [];
        if (!$rs) {
            return $out;
        }
        while ($rs->Next()) {
            // The PHP rule as well as its SQL twin: the recipient's view, then the viewer's.
            $facts = [
                'MundaneId'       => $recipient,
                'RecommendedById' => (int) $rs->recommended_by_id,
                'KingdomId'       => $recipKingdom,
                'ParkId'          => $recipPark,
                'MonarchyOnly'    => (int) $rs->monarchy_only,
                'PassedToLocal'   => (int) $rs->passed_to_local,
            ];
            if (!$vis->canSee($recipient, $facts) || !$vis->canSee($viewer, $facts)) {
                continue;
            }
            $out[] = self::shapeRec([
                'recommendations_id'  => $rs->recommendations_id,
                'recommended_by_id'   => $rs->recommended_by_id,
                'recommender_persona' => $rs->recommender_persona,
                'date_recommended'    => $rs->date_recommended,
                'reason'              => $rs->reason,
                'mask_giver'          => $rs->mask_giver,
                'seconds'             => $rs->seconds,
            ]);
        }

        return $out;
    }

    private function courtLine(array $award): ?array
    {
        $awardsId = $award['AwardsId'];
        $lineClause = 'ca.award_id = ' . $awardsId;
        if ($award['CourtAwardId'] > 0) {
            $lineClause = '(' . $lineClause . ' OR ca.court_award_id = ' . $award['CourtAwardId'] . ')';
        }
        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT ca.court_award_id, ca.court_id, ca.scroll_maker_id, ca.scroll_status,
                    ca.regalia_maker_id, ca.public_comment, ca.given_by_mundane_id,
                    rec.reason AS rec_reason, IFNULL(rec.monarchy_only, 0) AS rec_monarchy_only,
                    c.name AS court_name, c.court_date, c.park_id, c.kingdom_id,
                    p.name AS park_name, k.name AS kingdom_name, e.name AS event_name,
                    sm.persona AS scroll_maker_persona, rm.persona AS regalia_maker_persona,
                    gb.persona AS given_by_persona
               FROM ' . DB_PREFIX . 'court_award ca
               JOIN ' . DB_PREFIX . 'court c ON c.court_id = ca.court_id
               LEFT JOIN ' . DB_PREFIX . 'park p ON p.park_id = c.park_id
               LEFT JOIN ' . DB_PREFIX . 'kingdom k ON k.kingdom_id = c.kingdom_id
               LEFT JOIN ' . DB_PREFIX . 'event_calendardetail cd ON cd.event_calendardetail_id = c.event_calendardetail_id
               LEFT JOIN ' . DB_PREFIX . 'event e ON e.event_id = cd.event_id
               LEFT JOIN ' . DB_PREFIX . 'mundane sm ON sm.mundane_id = ca.scroll_maker_id
               LEFT JOIN ' . DB_PREFIX . 'mundane rm ON rm.mundane_id = ca.regalia_maker_id
               LEFT JOIN ' . DB_PREFIX . 'mundane gb ON gb.mundane_id = ca.given_by_mundane_id
               LEFT JOIN ' . DB_PREFIX . 'recommendations rec ON rec.recommendations_id = ca.recommendations_id
              WHERE ' . $lineClause . '
                AND ca.mundane_id = ' . $award['MundaneId'] . '
                AND ca.status = \'given\'
                AND c.status = \'complete\'
              ORDER BY ca.court_award_id DESC
              LIMIT 1'
        );
        if (!$rs || !$rs->Next()) {
            return null;
        }
        $lineId       = (int) $rs->court_award_id;
        $scrollStatus = (int) $rs->scroll_status;
        $lastPrinted  = $this->lastPrintedAt($awardsId, $lineId);
        $printed      = $lastPrinted !== '';
        $scrollery    = $printed || $scrollStatus === Court::SCROLL_SCROLLERY;
        $line = [
            'CourtAwardId'      => $lineId,
            'CourtId'           => (int) $rs->court_id,
            'CourtName'         => (string) ($rs->court_name ?? ''),
            'CourtDate'         => (string) ($rs->court_date ?? ''),
            'EventName'         => (string) ($rs->event_name ?? ''),
            'ParkName'          => (string) ($rs->park_name ?? ''),
            'KingdomName'       => (string) ($rs->kingdom_name ?? ''),
            'KingdomId'         => (int) $rs->kingdom_id,
            'ParkId'            => (int) $rs->park_id,
            'GivenById'         => (int) ($rs->given_by_mundane_id ?? 0),
            'GivenBy'           => (string) ($rs->given_by_persona ?? ''),
            // The login-free court report's citation, through the same publishing gate.
            'Citation'          => RecommendationVisibility::publishable_citation(
                (string) ($rs->public_comment ?? ''),
                (string) ($rs->rec_reason ?? ''),
                (int) $rs->rec_monarchy_only !== 0
            ),
            'PrintedWithScrollery' => $printed,
            'LastPrintedAt'     => $lastPrinted,
            // Who made what. A finalized line is taken as the record; tracking states
            // are planning detail and are not published. No maker → no entry.
            'Scroll'            => ($scrollery || (int) ($rs->scroll_maker_id ?? 0) <= 0) ? null : [
                'MakerId' => (int) $rs->scroll_maker_id,
                'Maker'   => (string) ($rs->scroll_maker_persona ?? ''),
            ],
            'Regalia'           => (int) ($rs->regalia_maker_id ?? 0) <= 0 ? null : [
                'MakerId' => (int) $rs->regalia_maker_id,
                'Maker'   => (string) ($rs->regalia_maker_persona ?? ''),
            ],
            'Artisans'          => [],
        ];

        $this->db->Clear();
        $ars = $this->db->DataSet(
            'SELECT caa.mundane_id, caa.contribution, m.persona
               FROM ' . DB_PREFIX . 'court_award_artisan caa
               LEFT JOIN ' . DB_PREFIX . 'mundane m ON m.mundane_id = caa.mundane_id
              WHERE caa.court_award_id = ' . $lineId . '
              ORDER BY caa.court_award_artisan_id'
        );
        if ($ars) {
            while ($ars->Next()) {
                $line['Artisans'][] = [
                    'MundaneId'    => (int) $ars->mundane_id,
                    'Persona'      => (string) ($ars->persona ?? ''),
                    'Contribution' => (string) ($ars->contribution ?? ''),
                ];
            }
        }

        return $line;
    }

    /** When this award's scroll was last printed through Scrollery ('' = never). */
    private function lastPrintedAt(int $awardsId, int $lineId): string
    {
        return Ork3::$Lib->scrollprintlog->LastPrintedAt($awardsId, $lineId);
    }
}
