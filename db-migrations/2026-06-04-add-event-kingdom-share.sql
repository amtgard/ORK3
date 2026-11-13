-- Cross-kingdom event sharing: records additional kingdoms an event surfaces in.
-- Ownership stays in ork_event.kingdom_id; this table only adds display visibility
-- on the owning-kingdom-OTHER kingdoms' Events tab.
CREATE TABLE IF NOT EXISTS ork_event_kingdom_share (
  event_kingdom_share_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  event_id               INT NOT NULL,
  kingdom_id             INT NOT NULL,
  shared_by_mundane_id   INT NOT NULL,
  created                DATETIME NOT NULL,
  UNIQUE KEY uq_event_kingdom (event_id, kingdom_id),
  KEY idx_kingdom (kingdom_id),
  KEY idx_event (event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
