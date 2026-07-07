# ORK3 Test Database Tool

CLI for sandbox database lifecycle (`bin/ork-db`). See `docs/megiddo/test-database-tool/`.

**TD-2 scope:** Docker sandbox container, deployment tier guard, `validate`, and `init`.

```bash
docker compose -f docker-compose.php8.yml up -d ork3testdb
bin/ork-db validate --mode init
bin/ork-db init
bin/ork-db validate
vendor/bin/phpunit -c phpunit.ork-db.xml.dist
```
