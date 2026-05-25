-- Add 'every-x-weeks' interval recurrence mode to park days.
ALTER TABLE `ork_parkday`
  MODIFY `recurrence`
  enum('weekly','monthly','week-of-month','every-x-weeks')
  NOT NULL DEFAULT 'weekly';
