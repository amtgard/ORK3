-- Fix AwardRecsPublic rows that were inserted with user_setting = 0.
-- Without this, the setting does not appear in the Kingdom Administration dialog.
UPDATE `ork_configuration`
SET `user_setting` = 1
WHERE `key` = 'AwardRecsPublic'
  AND `type` = 'Kingdom'
  AND `user_setting` = 0;
