-- Baseline columns on mirror/prod that predate tracked db-migrations/ entries.
-- Applied after ork.sql and before dated migrations so legacy ALTER ... AFTER clauses succeed.

ALTER TABLE `ork_award`
  ADD COLUMN `proposed_name` varchar(100) NOT NULL AFTER `name`,
  ADD COLUMN `deprecate` tinyint(1) NOT NULL DEFAULT '0' AFTER `proposed_name`;

ALTER TABLE `ork_awards`
  ADD COLUMN `by_whom_id` int(11) NOT NULL DEFAULT 0 AFTER `award_id`,
  ADD COLUMN `entered_at` datetime NOT NULL DEFAULT '1970-01-01 00:00:00' AFTER `by_whom_id`;

ALTER TABLE `ork_attendance`
  ADD COLUMN `by_whom_id` int(11) NOT NULL AFTER `note`;

-- Mirror uses InnoDB for mundane; ork.sql ships MyISAM. InnoDB is required for
-- mundane_design FK integrity in integration fixtures.
ALTER TABLE `ork_mundane` ENGINE=InnoDB;

-- InnoDB parity for tables referenced by FK constraints from newer migrations.
ALTER TABLE `ork_account` ENGINE=InnoDB;
ALTER TABLE `ork_attendance` ENGINE=InnoDB;
ALTER TABLE `ork_awards` ENGINE=InnoDB;
ALTER TABLE `ork_credential` ENGINE=InnoDB;
ALTER TABLE `ork_event_calendardetail` ENGINE=InnoDB;
