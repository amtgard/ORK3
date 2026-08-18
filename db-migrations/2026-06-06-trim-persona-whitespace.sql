-- Trim stray leading/trailing whitespace from player personas.
-- Leading spaces (~60 rows) sorted a persona ahead of every letter, so e.g.
-- " Sir Baron Rigor Stormblade" floated to the top of alphabetical player-search
-- dropdowns (Move Player). Trailing spaces (~1500 rows) were cosmetic but are
-- cleaned here too. Re-runnable: once trimmed, the WHERE clause matches nothing.
UPDATE ork_mundane
   SET persona = TRIM(persona)
 WHERE persona <> TRIM(persona)
    OR persona LIKE '% ';
