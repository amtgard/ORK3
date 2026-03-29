-- Add QualTestReeveEnabled and QualTestCorporaEnabled kingdom configs
-- for all existing active kingdoms. Default to '0' (disabled) so kingdoms
-- must explicitly opt-in to each test type.

INSERT INTO `ork_configuration` (`type`, `var_type`, `id`, `key`, `value`, `user_setting`, `allowed_values`, `modified`)
SELECT 'Kingdom', 'fixed', k.kingdom_id, 'QualTestReeveEnabled', '"0"', 1, 'null', NOW()
  FROM `ork_kingdom` k
 WHERE k.active = 'Active'
   AND NOT EXISTS (
       SELECT 1 FROM `ork_configuration` c
        WHERE c.type = 'Kingdom' AND c.id = k.kingdom_id AND c.`key` = 'QualTestReeveEnabled'
   );

INSERT INTO `ork_configuration` (`type`, `var_type`, `id`, `key`, `value`, `user_setting`, `allowed_values`, `modified`)
SELECT 'Kingdom', 'fixed', k.kingdom_id, 'QualTestCorporaEnabled', '"0"', 1, 'null', NOW()
  FROM `ork_kingdom` k
 WHERE k.active = 'Active'
   AND NOT EXISTS (
       SELECT 1 FROM `ork_configuration` c
        WHERE c.type = 'Kingdom' AND c.id = k.kingdom_id AND c.`key` = 'QualTestCorporaEnabled'
   );
