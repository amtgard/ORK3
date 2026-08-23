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

    public function IndexXml()
    {
        return ork_sitemap_index_xml(array(
            UIR . 'Sitemap/core',
            UIR . 'Sitemap/players',
        ));
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

    public function PlayersXml()
    {
        $key = Ork3::$Lib->ghettocache->key(array('sitemap-players'));
        if (($cache = Ork3::$Lib->ghettocache->get(__CLASS__ . '.players', $key, self::CACHE_TTL)) !== false) {
            return $cache;
        }

        // No lastmod on players, deliberately: ork_mundane.modified is
        // ON UPDATE CURRENT_TIMESTAMP and bumps on ANY row write — bulk
        // migrations and the suspension-expiry sweep stamp thousands of rows
        // at once with zero content change. A lying lastmod teaches Google to
        // ignore lastmod site-wide; a bare <loc> is honest.
        $urls = array();
        $this->db->Clear();
        $rs = $this->db->DataSet("SELECT mundane_id FROM " . DB_PREFIX . "mundane WHERE active = 1");
        if ($rs && $rs->Size() > 0) {
            while ($rs->Next()) {
                $urls[] = array('loc' => UIR . 'Player/profile/' . (int)$rs->mundane_id);
            }
        }

        return Ork3::$Lib->ghettocache->cache(__CLASS__ . '.players', $key, ork_sitemap_xml($urls));
    }
}
