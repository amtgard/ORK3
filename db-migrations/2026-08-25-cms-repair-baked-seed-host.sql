-- Repair CMS content seeded before CmsBlockRegistry emitted root-relative
-- URLs: DefaultFrontDoorBlocks() built image/route URLs from HTTP_TEMPLATE,
-- and under the CLI seed bootstrap (HTTP_HOST stand-in localhost:19080) that
-- froze http://localhost:19080/... into the seeded rows — broken images on
-- any host except local dev. Rewrites them to root-relative, which renders
-- correctly everywhere. Idempotent; no-op on databases seeded after the fix.
UPDATE ork_cms_block
   SET fields_json = REPLACE(fields_json, 'http://localhost:19080/', '/')
 WHERE fields_json LIKE '%localhost:19080%';

UPDATE ork_cms_revision
   SET blocks_json = REPLACE(blocks_json, 'http://localhost:19080/', '/')
 WHERE blocks_json LIKE '%localhost:19080%';
