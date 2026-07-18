-- Sandbox parity gaps captured from the 2026-07-18 archive schema.
-- These are legacy contracts rather than upstream migrations, so apply them
-- after classified migrations and before the renderer inserts fixture data.

-- Archive defaults on legacy award rows are relied on by fixture inserts and
-- preserve the production-shaped column contract.
ALTER TABLE `ork_awards`
  MODIFY `stripped_from` int(11) DEFAULT NULL,
  MODIFY `unit_id` int(11) DEFAULT 0,
  MODIFY `park_id` int(11) DEFAULT NULL,
  MODIFY `kingdom_id` int(11) DEFAULT NULL,
  MODIFY `team_id` int(11) DEFAULT 0,
  MODIFY `rank` int(11) NOT NULL DEFAULT 0,
  MODIFY `note` varchar(400) NOT NULL DEFAULT '',
  MODIFY `by_whom_id` int(11) NOT NULL,
  MODIFY `entered_at` datetime NOT NULL,
  MODIFY `revocation` varchar(50) NOT NULL DEFAULT '',
  MODIFY `revoked_by_id` int(11) NOT NULL DEFAULT 0,
  ADD KEY `by_whom_id_key` (`by_whom_id`);

ALTER TABLE `ork_attendance_link`
  MODIFY `credits` double(4,2) NOT NULL DEFAULT 1.00;

ALTER TABLE `ork_event_calendardetail`
  MODIFY `latitude` double NOT NULL,
  MODIFY `longitude` double NOT NULL;

ALTER TABLE `ork_parkday`
  MODIFY `google_geocode` longtext NOT NULL,
  MODIFY `latitude` double NOT NULL,
  MODIFY `longitude` double NOT NULL,
  MODIFY `location` longtext NOT NULL;

-- Match the collation contracts from the archive/live sandbox.
ALTER TABLE `ork_event_fees`
  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE `ork_event_schedule_lead`
  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE `ork_whats_new_seen`
  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
