-- Schema-only excerpt from 2018-11-12-officer-roles (role seeds come from extract).

ALTER TABLE `ork_award` ADD `officer_role` ENUM('kingdom','park','principality','bod','other-officer','none') NOT NULL DEFAULT 'none' AFTER `crown_limit`;
