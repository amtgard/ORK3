UPDATE ork_authorization
SET role = 'create',
    modified = NOW()
WHERE role = 'edit'
  AND unit_id > 0
  AND park_id = 0
  AND kingdom_id = 0
  AND event_id = 0;
