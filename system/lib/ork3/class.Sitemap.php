<?php

/**
 * XML sitemaps for the public, index-worthy surface (2026-08-23, Ken's plan).
 * See Controller_Sitemap for the routes and the inclusion/exclusion rationale.
 * Each section is a couple of cheap indexed SELECTs (~70ms for all of players
 * on prod) behind a 24h ghettocache; the only regular consumer is Googlebot a
 * few times a day.
 */
class Sitemap extends Ork3
{
    public const CACHE_TTL = 86400;

    // Sitemap protocol caps a file at 50,000 URLs; players (~68k) shard into
    // pages of this size. 40k leaves growth headroom before a third page.
    public const PLAYERS_PER_PAGE = 40000;

    public function IndexXml()
    {
        $key = Ork3::$Lib->ghettocache->key(array('sitemap-index'));
        if (($cache = Ork3::$Lib->ghettocache->get(__CLASS__ . '.index', $key, self::CACHE_TTL)) !== false) {
            return $cache;
        }

        $sitemaps = array(UIR . 'Sitemap/core');
        for ($p = 1; $p <= $this->PlayersPageCount(); $p++) {
            $sitemaps[] = UIR . 'Sitemap/players/' . $p;
        }

        return Ork3::$Lib->ghettocache->cache(__CLASS__ . '.index', $key, ork_sitemap_index_xml($sitemaps));
    }

    public function PlayersPageCount()
    {
        $this->db->Clear();
        $rs = $this->db->DataSet("SELECT COUNT(*) AS c FROM " . DB_PREFIX . "mundane WHERE active = 1");
        $count = ($rs && $rs->Size() > 0 && $rs->Next()) ? (int)$rs->c : 0;
        return max(1, (int)ceil($count / self::PLAYERS_PER_PAGE));
    }

    public function CoreXml()
    {
        $key = Ork3::$Lib->ghettocache->key(array('sitemap-core'));
        if (($cache = Ork3::$Lib->ghettocache->get(__CLASS__ . '.core', $key, self::CACHE_TTL)) !== false) {
            return $cache;
        }

        $urls = array(
            array('loc' => UIR . 'Recap/trends'),
        );

        $this->db->Clear();
        $rs = $this->db->DataSet("SELECT park_id, modified FROM " . DB_PREFIX . "park WHERE active = 'Active'");
        if ($rs && $rs->Size() > 0) {
            while ($rs->Next()) {
                $urls[] = array('loc' => UIR . 'Park/profile/' . (int)$rs->park_id, 'lastmod' => $rs->modified);
            }
        }

        $this->db->Clear();
        $rs = $this->db->DataSet("SELECT kingdom_id, modified FROM " . DB_PREFIX . "kingdom WHERE active = 'Active'");
        if ($rs && $rs->Size() > 0) {
            while ($rs->Next()) {
                $urls[] = array('loc' => UIR . 'Kingdom/profile/' . (int)$rs->kingdom_id, 'lastmod' => $rs->modified);
            }
        }

        // Occurrence URLs match the pages' own canonicals. Published events
        // only; recent past kept so just-finished events stay crawl-fresh,
        // ancient history left to organic discovery.
        $this->db->Clear();
        $rs = $this->db->DataSet(
            "SELECT cd.event_id, cd.event_calendardetail_id, cd.modified
			   FROM " . DB_PREFIX . "event_calendardetail cd
			   JOIN " . DB_PREFIX . "event e ON e.event_id = cd.event_id AND e.status = 'published'
			  WHERE cd.event_start >= DATE_SUB(NOW(), INTERVAL 12 MONTH)"
        );
        if ($rs && $rs->Size() > 0) {
            while ($rs->Next()) {
                $urls[] = array(
                    'loc' => UIR . 'Event/detail/' . (int)$rs->event_id . '/' . (int)$rs->event_calendardetail_id,
                    'lastmod' => $rs->modified,
                );
            }
        }

        $this->db->Clear();
        $rs = $this->db->DataSet("SELECT week_start, computed_at FROM " . DB_PREFIX . "weekly_recap");
        if ($rs && $rs->Size() > 0) {
            while ($rs->Next()) {
                $urls[] = array('loc' => UIR . 'Recap/index/' . $rs->week_start, 'lastmod' => $rs->computed_at);
            }
        }

        return Ork3::$Lib->ghettocache->cache(__CLASS__ . '.core', $key, ork_sitemap_xml($urls));
    }

    public function PlayersXml($page = 1)
    {
        $page = max(1, (int)$page);
        $key = Ork3::$Lib->ghettocache->key(array('sitemap-players-' . $page));
        if (($cache = Ork3::$Lib->ghettocache->get(__CLASS__ . '.players', $key, self::CACHE_TTL)) !== false) {
            return $cache;
        }

        // No lastmod on players, deliberately: ork_mundane.modified is
        // ON UPDATE CURRENT_TIMESTAMP and bumps on ANY row write — bulk
        // migrations and the suspension-expiry sweep stamp thousands of rows
        // at once with zero content change. A lying lastmod teaches Google to
        // ignore lastmod site-wide; a bare <loc> is honest.
        // ORDER BY keeps page boundaries stable between the pages' separate
        // cache fills; a player created mid-day shifts pages at worst, and a
        // sitemap is a hint, not a contract.
        $urls = array();
        $this->db->Clear();
        $rs = $this->db->DataSet(
            "SELECT mundane_id FROM " . DB_PREFIX . "mundane WHERE active = 1
			  ORDER BY mundane_id
			  LIMIT " . self::PLAYERS_PER_PAGE . " OFFSET " . (($page - 1) * self::PLAYERS_PER_PAGE)
        );
        if ($rs && $rs->Size() > 0) {
            while ($rs->Next()) {
                $urls[] = array('loc' => UIR . 'Player/profile/' . (int)$rs->mundane_id);
            }
        }

        return Ork3::$Lib->ghettocache->cache(__CLASS__ . '.players', $key, ork_sitemap_xml($urls));
    }
}
