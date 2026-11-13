-- Issue 4: split the conflated 'Other' weapon style (displayed "Other / Open")
-- into two distinct buckets: 'Open Weapons' and a genuine 'Other'.
-- Append-only enum change (safe), then migrate existing rows.
--
-- RE-RUN SAFETY: the one-time 'Other' -> 'Open Weapons' relabel must ONLY run on the
-- first application. Capture whether the enum ALREADY carried 'Open Weapons' before this
-- migration's MODIFY: on a first apply it does not (so the relabel is the intended
-- migration); on a re-apply it does (so we skip and leave genuinely-new 'Other' brackets
-- untouched).
SET @had_open := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'ork_bracket'
    AND column_name = 'style'
    AND COLUMN_TYPE LIKE "%'Open Weapons'%"
);

ALTER TABLE ork_bracket
  MODIFY `style` enum(
    'Single Sword','Florentine','Sword and Shield','Great Weapon','Missile',
    'Other','Jugging','Battlegame','Quest','Open Weapons'
  ) NOT NULL;

-- Today's 'Other' rows semantically meant "open weapons"; migrate them ONLY on first apply.
SET @sql := IF(@had_open = 0,
  'UPDATE ork_bracket SET `style` = ''Open Weapons'' WHERE `style` = ''Other''',
  'SELECT ''Open Weapons enum already present; skipping one-time Other relabel'' AS note'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
