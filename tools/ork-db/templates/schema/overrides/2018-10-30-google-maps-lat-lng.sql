-- Schema-only extract from 2018-10-30-google-maps-api-update (PB data backfill omitted).
ALTER TABLE `ork_parkday`
  ADD `google_geocode` MEDIUMTEXT NOT NULL DEFAULT '' AFTER `postal_code`,
  ADD `latitude` DOUBLE NOT NULL DEFAULT 0 AFTER `google_geocode`,
  ADD `longitude` DOUBLE NOT NULL DEFAULT 0 AFTER `latitude`,
  ADD `location` MEDIUMTEXT NOT NULL DEFAULT '' AFTER `longitude`;

ALTER TABLE `ork_event_calendardetail`
  ADD `latitude` DOUBLE NOT NULL DEFAULT 0 AFTER `location`,
  ADD `longitude` DOUBLE NOT NULL DEFAULT 0 AFTER `latitude`;
