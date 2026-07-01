-- Track HOW each attendance row was created. Currently `by_whom_id` only
-- says "who keyed it"; for self-signins (via QR sign-in link) and self-reg
-- onboarding, the player attributes to themselves, which makes audit views
-- read confusingly ("Augustus entered Augustus's attendance"). The new
-- entry_method column tells reports the *source* of the row so they can
-- render it as "Self via Sign-in Link" / "Self-registration" instead of
-- linking back to the player as if they were the officer.
--
-- ENUM (not VARCHAR) chosen for typo-proof DB-level validation and tiny
-- 1-byte storage. Always append new values; never reorder or remove.
ALTER TABLE `ork_attendance`
    ADD COLUMN `entry_method` ENUM('manual','signin_link','self_reg','bulk_import')
        NOT NULL DEFAULT 'manual'
        AFTER `by_whom_id`;

-- Backfill the existing self-registration rows so the audit view is correct
-- from day one. These rows are easy to identify by their distinctive note.
UPDATE `ork_attendance`
    SET `entry_method` = 'self_reg'
    WHERE `note` = 'Self-registration';
