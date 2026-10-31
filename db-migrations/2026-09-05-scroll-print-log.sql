-- Scrollery print log.
--
-- Nothing anywhere records that a scroll was printed. That blocks four separate
-- features: the scribe's "not yet printed" filter, the Court Planner handshake,
-- the Release Feature Utilization report (which currently has nothing to count
-- for Scrollery), and the upstreaming gate.
--
-- INSERT-ONLY BY DESIGN. Nothing updates or deletes a row here; a re-print is a
-- new row, and "when was this last printed" is max(printed_at). There is no
-- foreign key to ork_awards on purpose — a print is a historical fact that must
-- survive the award record being edited or removed, and awards_id is NULLABLE
-- because Scrollery/compose prints a hand-composed certificate that has no
-- award row at all.
--
-- Idempotent: CREATE TABLE IF NOT EXISTS, safe to re-run.

CREATE TABLE IF NOT EXISTS `ork_scroll_print` (
  `scroll_print_id` int(11) NOT NULL AUTO_INCREMENT,
  -- NULL for a composed (award-less) certificate.
  `awards_id` int(11) DEFAULT NULL,
  -- The recipient. 0 for a composed scroll printed for someone with no ORK row.
  `mundane_id` int(11) NOT NULL DEFAULT 0,
  -- The officer (or recipient) who pressed print.
  `printed_by_id` int(11) NOT NULL DEFAULT 0,
  -- 'park' | 'kingdom' | '' — the issuing body the certificate named.
  `scope_type` varchar(16) NOT NULL DEFAULT '',
  `scope_id` int(11) NOT NULL DEFAULT 0,
  -- Scroll::Orders() key, e.g. 'rose', 'hunter', 'custom'.
  `order_id` varchar(32) NOT NULL DEFAULT '',
  `rank` int(11) NOT NULL DEFAULT 0,
  `variant` varchar(32) NOT NULL DEFAULT '',
  -- The normalised Scroll::NormaliseRecipe() result, so a reprint can reproduce
  -- the exact certificate even after the defaults change.
  `recipe_json` text DEFAULT NULL,
  `printed_at` datetime NOT NULL,
  PRIMARY KEY (`scroll_print_id`),
  KEY `awards_id` (`awards_id`),
  KEY `printed_at` (`printed_at`),
  KEY `mundane_id_printed_at` (`mundane_id`, `printed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
