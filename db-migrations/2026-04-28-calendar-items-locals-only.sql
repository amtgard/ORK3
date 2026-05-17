-- Calendar Items: locals-only flag.
-- When set, item is visible only to logged-in players whose home park/kingdom
-- matches the item's scope (plus ORK admins). Independent of is_officer_only.

ALTER TABLE ork_calendar_item
    ADD COLUMN is_locals_only TINYINT(1) NOT NULL DEFAULT 0;
