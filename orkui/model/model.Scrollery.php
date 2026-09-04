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
