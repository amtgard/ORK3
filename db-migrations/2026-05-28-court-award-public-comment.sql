-- Court Report: public-facing comment per court award, distinct from internal `notes`.
ALTER TABLE ork_court_award
    ADD COLUMN IF NOT EXISTS public_comment TEXT NULL DEFAULT NULL AFTER notes;
