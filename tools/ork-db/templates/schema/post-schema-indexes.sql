-- Indexes present on mirror/prod without a standalone db-migrations/ entry.

ALTER TABLE `ork_mundane`
  ADD FULLTEXT KEY `search_names` (`given_name`,`surname`,`other_name`,`username`,`persona`);

ALTER TABLE `ork_attendance`
  ADD KEY `by_whom_id_key` (`by_whom_id`);
