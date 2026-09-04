<?php

/**
 * Award rows -> scroll data.
 *
 * The permission decision lives here so every route shares one copy of it. The
 * render route is a view, never the authority: it re-runs this against its own
 * URL parameters.
 */
class Model_Scrollery extends Model
{
    /**
     * Returns the scroll data for one awards row, or null when the viewer may not
     * print it or the award has no certificate art.
     */
    public function scroll_for_awards_id(int $awardsId, int $viewerId, string $variant = ''): ?array
    {
        if ($awardsId <= 0 || $viewerId <= 0) {
            return null;
        }

        $row = $this->award_row($awardsId);
        if ($row === null || !Scroll::HasArt($row)) {
            return null;
        }

        if (!$this->viewer_may_print($row, $viewerId)) {
            return null;
        }

        $player = Ork3::$Lib->player->GetPlayer(['MundaneId' => (int) $row['MundaneId']]);

        return Scroll::BuildScroll($row, [
            'player'      => $player['Player']['Persona'] ?? '',
            'pronoun'     => $this->possessive_pronoun($player['Player'] ?? []),
            'heraldry'    => $this->heraldry_url($row),
            'issuerTitle' => $this->issuer_title($row),
            'variant'     => $variant,
        ]);
    }

    /**
     * The player's own awards that can actually be printed.
     *
     * Filtered to those with certificate art — phase 1 shows a Print control only
     * where one exists, and nothing elsewhere. No dead buttons.
     *
     * @return array<int, array>
     */
    public function printable_awards_for_player(int $mundaneId): array
    {
        if ($mundaneId <= 0) {
            return [];
        }

        $response  = Ork3::$Lib->player->AwardsForPlayer(['MundaneId' => $mundaneId, 'AwardsId' => 0]);
        $printable = [];

        foreach ($response['Awards'] ?? [] as $row) {
            if (!Scroll::HasArt($row)) {
                continue;
            }

            $row['Title']       = Scroll::ResolveTitle($row);
            $row['IssuerTitle'] = $this->issuer_title($row);
            $row['IssuerName']  = trim((string) ($row['ParkName'] ?? '')) !== ''
                ? (string) $row['ParkName']
                : (string) ($row['KingdomName'] ?? '');
            $row['PrettyDate']  = Scroll::FormatDate((string) ($row['Date'] ?? ''));
            $row['PrintUrl']    = UIR . 'Scrollery/render&awards=' . (int) $row['AwardsId'] . '&print=1';
            $printable[]        = $row;
        }

        return $printable;
    }

    /**
     * Name, kingdom and heraldry for the park or kingdom a scroll is being issued by.
     *
     * @return array{Type:string,Id:int,Name:string,Title:string,KingdomId:int,Heraldry:string,UseThe:bool,UseOf:bool}
     */
    public function scope_context(string $scopeType, int $scopeId): array
    {
        if ($scopeType === 'kingdom') {
            $k    = Ork3::$Lib->kingdom->GetKingdomShortInfo(['KingdomId' => $scopeId]);
            $info = $k['KingdomInfo'] ?? [];

            return [
                'Type'      => 'kingdom',
                'Id'        => $scopeId,
                // Kingdom names already carry their own article and rank word —
                // "The Kingdom of the Wetlands" — so the certificate adds neither.
                'Name'      => (string) ($info['KingdomName'] ?? ''),
                'Title'     => '',
                'UseThe'    => false,
                'UseOf'     => false,
                'KingdomId' => $scopeId,
                'Heraldry'  => (int) ($info['HasHeraldry'] ?? 0) === 1
                    ? (string) Ork3::$Lib->heraldry->GetHeraldryUrl(['Type' => 'Kingdom', 'Id' => $scopeId])['Url']
                    : '',
            ];
        }

        $park = $this->park_details($scopeId);

        return [
            'Type'      => 'park',
            'Id'        => $scopeId,
            'Name'      => (string) ($park['ParkName'] ?? ''),
            'Title'     => trim((string) ($park['ParkTitle'] ?? '')) ?: 'Barony',
            'UseThe'    => true,
            'UseOf'     => true,
            'KingdomId' => (int) ($park['KingdomId'] ?? 0),
            'Heraldry'  => (int) ($park['HasHeraldry'] ?? 0) === 1
                ? (string) Ork3::$Lib->heraldry->GetHeraldryUrl(['Type' => 'Park', 'Id' => $scopeId])['Url']
                : '',
        ];
    }

    /**
     * The kingdom's own award rows that have certificate art.
     *
     * Goes through the kingdomaward list the ORK already uses, so a kingdom holding
     * more than one name for a base award offers both by name and nothing has to
     * special-case the archery spellings.
     *
     * @return array<int, array{KingdomAwardId:int, AwardId:int, Name:string, Order:string, MaxRank:int, Variants:array}>
     */
    public function printable_award_choices(int $kingdomId): array
    {
        if ($kingdomId <= 0) {
            return [];
        }

        $response = Ork3::$Lib->kingdom->GetAwardList([
            'IsLadder'  => 'Both',
            'IsTitle'   => 'Both',
            'KingdomId' => $kingdomId,
        ]);

        $choices = [];
        foreach ($response['Awards'] ?? [] as $row) {
            $order = Scroll::OrderForAwardId((int) ($row['AwardId'] ?? $row['award_id'] ?? 0));
            if ($order === null) {
                continue;
            }

            $choices[] = [
                'KingdomAwardId' => (int) ($row['KingdomAwardId'] ?? $row['kingdomaward_id'] ?? 0),
                'AwardId'        => (int) ($row['AwardId'] ?? $row['award_id'] ?? 0),
                'Name'           => (string) ($row['KingdomAwardName'] ?? $row['kingdom_awardname'] ?? ''),
                'Order'          => (string) $order['id'],
                'MinRank'        => (int) $order['ranks']['from'],
                'MaxRank'        => (int) $order['ranks']['to'],
                'Variants'       => array_values($order['variants']),
            ];
        }

        usort($choices, static fn ($a, $b) => strcasecmp($a['Name'], $b['Name']));

        return $choices;
    }

    /**
     * The bulk selection — the module's ONE new query. Everything else reuses
     * Player::AwardsForPlayer().
     *
     * Public and named ...ForTest so ScrolleryBulkQueryTest can assert on the
     * generated statement without reflection and without a database. It builds a
     * string and touches nothing.
     *
     * The surrounding code interpolates rather than binds, and this codebase's
     * mysql_real_escape_string() is a no-op shim, so what protects this is the (int)
     * cast on every id and the ISO-date regex — not escaping.
     */
    public function bulkSqlForTest(string $type, string $id, string $from, string $to): string
    {
        $id = (int) $id;

        switch ($type) {
            case 'event':
                $scope = 'awards.at_event_id = ' . $id;
                break;
            case 'kingdom':
                $scope = 'awards.at_kingdom_id = ' . $id;
                break;
            case 'park':
                $scope = 'awards.at_park_id = ' . $id;
                break;
            default:
                // Never widen to "everything" on an unrecognised scope.
                $scope = 'awards.at_park_id = 0';
                break;
        }

        $range = '';
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $range .= " and awards.date >= '" . $from . "'";
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $range .= " and awards.date <= '" . $to . "'";
        }

        return 'select awards.awards_id, awards.mundane_id, awards.rank, awards.date, awards.note,
                       awards.custom_name, awards.at_park_id, awards.at_kingdom_id, awards.at_event_id,
                       a.award_id, a.name,
                       ka.name as kingdom_awardname,
                       m.persona as recipient,
                       gb.persona as given_by,
                       p.name as park_name, k.name as kingdom_name
                  from ' . DB_PREFIX . 'awards awards
                  left join ' . DB_PREFIX . 'kingdomaward ka on ka.kingdomaward_id = awards.kingdomaward_id
                  left join ' . DB_PREFIX . 'award a on a.award_id = ka.award_id
                  left join ' . DB_PREFIX . 'mundane m on m.mundane_id = awards.mundane_id
                  left join ' . DB_PREFIX . 'mundane gb on gb.mundane_id = awards.given_by_id
                  left join ' . DB_PREFIX . 'park p on p.park_id = awards.at_park_id
                  left join ' . DB_PREFIX . 'kingdom k on k.kingdom_id = awards.at_kingdom_id
                 where ' . $scope . $range . '
                 order by m.persona, a.name, awards.rank';
    }

    /**
     * Award rows for a bulk print, shaped like the reprint list.
     *
     * Returns both the printable rows and the ones excluded for having no artwork,
     * so the officer is told rather than silently handed fewer scrolls than awards.
     *
     * @return array{Printable: array<int, array>, Excluded: array<int, array>}
     */
    public function awards_for_bulk(string $type, int $id, string $from = '', string $to = ''): array
    {
        $printable = [];
        $excluded  = [];

        if ($id <= 0) {
            return ['Printable' => $printable, 'Excluded' => $excluded];
        }

        $rs = $this->db_query($this->bulkSqlForTest($type, (string) $id, $from, $to));
        foreach ($rs as $row) {
            // Shaped to the keys Scroll::HasArt() and ResolveTitle() expect.
            $shaped = [
                'AwardsId'         => (int) $row['awards_id'],
                'AwardId'          => (int) $row['award_id'],
                'MundaneId'        => (int) $row['mundane_id'],
                'Rank'             => (int) $row['rank'],
                'Date'             => (string) $row['date'],
                'Recipient'        => (string) $row['recipient'],
                'GivenBy'          => (string) $row['given_by'],
                'Name'             => (string) $row['name'],
                'KingdomAwardName' => (string) $row['kingdom_awardname'],
                'CustomAwardName'  => (string) $row['custom_name'],
                'AliasAwardName'   => '',
                'ParkName'         => (string) $row['park_name'],
                'KingdomName'      => (string) $row['kingdom_name'],
            ];
            $shaped['Title']      = Scroll::ResolveTitle($shaped);
            $shaped['PrettyDate'] = Scroll::FormatDate($shaped['Date']);

            if (Scroll::HasArt($shaped)) {
                $printable[] = $shaped;
            } else {
                $excluded[] = $shaped;
            }
        }

        return ['Printable' => $printable, 'Excluded' => $excluded];
    }

    /** @return array<int, array<string, mixed>> */
    private function db_query(string $sql): array
    {
        $db = Ork3::$Lib->player->db;
        $db->Clear();
        $rs = $db->query($sql);

        $rows = [];
        if ($rs !== false && $rs->size() > 0) {
            while ($rs->next()) {
                $rows[] = [
                    'awards_id'         => $rs->awards_id,
                    'award_id'          => $rs->award_id,
                    'mundane_id'        => $rs->mundane_id,
                    'rank'              => $rs->rank,
                    'date'              => $rs->date,
                    'note'              => $rs->note,
                    'custom_name'       => $rs->custom_name,
                    'recipient'         => $rs->recipient,
                    'given_by'          => $rs->given_by,
                    'name'              => $rs->name,
                    'kingdom_awardname' => $rs->kingdom_awardname,
                    'park_name'         => $rs->park_name,
                    'kingdom_name'      => $rs->kingdom_name,
                ];
            }
        }

        return $rows;
    }

    /** Own award, or an officer with edit rights over the granting park/kingdom. */
    private function viewer_may_print(array $row, int $viewerId): bool
    {
        if ((int) $row['MundaneId'] === $viewerId) {
            return true;
        }

        $auth = Ork3::$Lib->authorization;
        if ((int) $row['ParkId'] > 0
            && $auth->HasAuthority($viewerId, AUTH_PARK, (int) $row['ParkId'], AUTH_EDIT)) {
            return true;
        }
        if ((int) $row['KingdomId'] > 0
            && $auth->HasAuthority($viewerId, AUTH_KINGDOM, (int) $row['KingdomId'], AUTH_EDIT)) {
            return true;
        }

        return false;
    }

    /**
     * One awards row, via the query the Awards tab already uses.
     *
     * AwardsForPlayer() ORs its AwardsId onto the mundane_id filter, so a
     * MundaneId of 0 asks it for exactly this row. Reusing it keeps the alias,
     * kingdomaward, park, kingdom, event and giver joins in one place rather than
     * in a parallel query that can drift.
     */
    private function award_row(int $awardsId): ?array
    {
        $response = Ork3::$Lib->player->AwardsForPlayer([
            'MundaneId' => 0,
            'AwardsId'  => $awardsId,
        ]);

        foreach (($response['Awards'] ?? []) as $row) {
            if ((int) $row['AwardsId'] === $awardsId) {
                return $row;
            }
        }

        return null;
    }

    /**
     * The issuing body's heraldry, or '' — on which _heraldry.tpl omits the layer.
     *
     * GetHeraldryUrl() never returns an empty string: it falls through to a .jpg
     * URL whether or not a file exists, so an issuer with no heraldry would render
     * a broken image. has_heraldry is what actually answers the question.
     */
    private function heraldry_url(array $row): string
    {
        $parkId = (int) ($row['ParkId'] ?? 0);
        if ($parkId > 0) {
            $park = $this->park_details($parkId);
            if ((int) ($park['HasHeraldry'] ?? 0) === 1) {
                return (string) Ork3::$Lib->heraldry->GetHeraldryUrl(['Type' => 'Park', 'Id' => $parkId])['Url'];
            }
        }

        $kingdomId = (int) ($row['KingdomId'] ?? 0);
        if ($kingdomId > 0) {
            $kingdom = Ork3::$Lib->kingdom->GetKingdomShortInfo(['KingdomId' => $kingdomId]);
            if ((int) ($kingdom['KingdomInfo']['HasHeraldry'] ?? 0) === 1) {
                return (string) Ork3::$Lib->heraldry->GetHeraldryUrl(['Type' => 'Kingdom', 'Id' => $kingdomId])['Url'];
            }
        }

        return '';
    }

    /**
     * The issuing body's rank word — "and the Freehold of ...".
     *
     * ORK stores this per park (ork_park.parktitle_id -> ork_parktitle.title,
     * defined per kingdom). Most parks are not baronies: 349 are Freeholds and 274
     * Grand Duchies, so the Barony/Kingdom fallback in Scroll::BuildScroll() would
     * be wrong far more often than right. Returning '' lets that fallback stand.
     */
    private function issuer_title(array $row): string
    {
        $parkId = (int) ($row['ParkId'] ?? 0);
        if ($parkId <= 0) {
            return '';
        }

        return trim((string) ($this->park_details($parkId)['ParkTitle'] ?? ''));
    }

    /**
     * GetParkDetails() returns a flat array and is called twice per scroll (title
     * and heraldry), so hold onto it.
     *
     * @return array<string, mixed>
     */
    private function park_details(int $parkId): array
    {
        static $cache = [];
        if (!array_key_exists($parkId, $cache)) {
            $cache[$parkId] = Ork3::$Lib->park->GetParkDetails(['ParkId' => $parkId]);
        }

        return is_array($cache[$parkId]) ? $cache[$parkId] : [];
    }

    /** The recipient's possessive pronoun, by player id. Never blank. */
    public function possessive_pronoun_for_player(int $mundaneId): string
    {
        if ($mundaneId <= 0) {
            return '';
        }

        $player = Ork3::$Lib->player->GetPlayer(['MundaneId' => $mundaneId]);

        return $this->possessive_pronoun($player['Player'] ?? []);
    }

    /**
     * The POSSESSIVE pronoun — the text layer reads "with <her> third".
     *
     * Never blank: an empty value makes the sentence read "with third".
     *
     * This is an outright improvement on Arcanyst, where the pronoun was typed in
     * per recipient and defaulted to "their".
     */
    private function possessive_pronoun(array $player): string
    {
        $pronounId = (int) ($player['PronounId'] ?? 0);
        if ($pronounId > 0) {
            $possessive = $this->possessive_for_ids([$pronounId]);
            if ($possessive !== '') {
                return $possessive;
            }
        }

        // Custom pronouns are stored as {"s":[ids],"o":[ids],"p":[ids],...} — "p"
        // being the possessive set. Rare (66 players), but cheap to honour.
        $custom = (string) ($player['PronounCustom'] ?? '');
        if ($custom !== '') {
            $decoded = json_decode($custom, true);
            if (is_array($decoded) && !empty($decoded['p']) && is_array($decoded['p'])) {
                $ids = array_values(array_filter(array_map('intval', $decoded['p'])));
                if ($ids !== []) {
                    $possessive = $this->possessive_for_ids($ids);
                    if ($possessive !== '') {
                        return $possessive;
                    }
                }
            }
        }

        return 'their';
    }

    /** @param int[] $ids */
    private function possessive_for_ids(array $ids): string
    {
        $list = Ork3::$Lib->pronoun->GetPronounList($ids);
        foreach ($ids as $id) {
            $possessive = trim((string) ($list['Pronouns'][$id]['Possessive'] ?? ''));
            if ($possessive !== '') {
                return $possessive;
            }
        }

        return '';
    }
}
