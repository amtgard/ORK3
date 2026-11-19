-- Add 'warrior' seeding type to ork_bracket
ALTER TABLE ork_bracket
  MODIFY COLUMN seeding ENUM('manual','glicko2','random','glicko2-manual','random-manual','warrior') NOT NULL DEFAULT 'random';
