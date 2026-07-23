-- Schema-only excerpt from 2026-05-31-attendance-entry-method.sql (backfill excluded).

ALTER TABLE `ork_attendance`
    ADD COLUMN `entry_method` ENUM('manual','signin_link','self_reg','bulk_import')
        NOT NULL DEFAULT 'manual'
        AFTER `by_whom_id`;

ALTER TABLE `ork_attendance`
    ADD COLUMN `entered_at` datetime NOT NULL
        AFTER `entry_method`;
