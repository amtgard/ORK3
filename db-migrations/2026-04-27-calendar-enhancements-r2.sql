-- Draft events: status column gates publication visibility on the
-- park/kingdom event listings and event detail page. Officers and the
-- event creator see drafts; everyone else sees only published.
--
-- The Round-2 calendar-item half of the original migration
-- (ADD COLUMN is_officer_only) was folded into the consolidated
-- 2026-04-12-calendar-items.sql.

ALTER TABLE ork_event
    ADD COLUMN status VARCHAR(16) NOT NULL DEFAULT 'published',
    ADD INDEX idx_event_status (status);
