<?php

/**
 * XML sitemaps for the public, index-worthy surface (2026-08-23, Ken's plan):
 *
 *   /Sitemap            → sitemap index (submit THIS one in Search Console)
 *   /Sitemap/core       → active parks, kingdoms, event occurrences
 *                         (last 12 months + future, published events only),
 *                         dated recaps, trends, home  (~2k URLs)
 *   /Sitemap/players    → active players (~68k URLs)
 *
 * Split so Search Console reports indexing per section — "what fraction of
 * player pages does Google deem index-worthy" becomes its own tracked
 * number, and the players file can be dropped/refined without touching core.
 *
 * Deliberately absent: everything noindex (#382: Reports, Live, Weather,
 * Attendance, ReleaseNotes, Search) or behind the Cloudflare Attendance 403
 * wall — never invite a crawler to a locked door. A sitemap is a discovery
 * hint, not an allowlist: pages not listed remain crawlable via links.
 */
class Controller_Sitemap extends Controller
{
    public function index($p = null)
    {
        $this->_emit($this->_sitemap()->IndexXml());
    }

    public function core($p = null)
    {
        $this->_emit($this->_sitemap()->CoreXml());
    }

    public function players($p = null)
    {
        $this->_emit($this->_sitemap()->PlayersXml());
    }

    private function _sitemap()
    {
        return new APIModel('Sitemap');
    }

    private function _emit($xml)
    {
        header('Content-Type: application/xml; charset=UTF-8');
        echo $xml;
        exit;
    }
}
