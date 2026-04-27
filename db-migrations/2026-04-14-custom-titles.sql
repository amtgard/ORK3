-- Custom Titles: add alias_award_id column + Custom Title sentinel row

ALTER TABLE ork_awards
  ADD COLUMN alias_award_id INT(11) NULL DEFAULT NULL AFTER custom_name,
  ADD KEY idx_alias_award_id (alias_award_id);

-- Sentinel ork_award row.
-- is_title=1 so unaliased Custom Titles flow into the Titles tab;
-- peerage=None and officer_role=none keep them out of peerage/officer queries
-- unless they are aliased (in which case query-time COALESCE substitutes).
INSERT INTO ork_award
    (name, proposed_name, deprecate, is_ladder, is_title, title_class, peerage, crown_points, crown_limit, officer_role)
VALUES
    ('Custom Title', '', 0, 0, 1, 0, 'None', 0, 0, 'none');

SELECT LAST_INSERT_ID() AS custom_title_award_id;
