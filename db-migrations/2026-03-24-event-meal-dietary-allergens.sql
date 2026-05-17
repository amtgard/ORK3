ALTER TABLE ork_event_meal
  ADD COLUMN dietary   VARCHAR(500) NULL AFTER menu,
  ADD COLUMN allergens VARCHAR(500) NULL AFTER dietary;
