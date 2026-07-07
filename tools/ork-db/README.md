# ORK3 Test Database Tool

CLI for sandbox database lifecycle (`bin/ork-db`). See `docs/megiddo/test-database-tool/`.

**TD-3 scope:** Mirror extract command (`Extract.php`), prod canary migration, PHPUnit suite (`phpunit.ork-db.xml.dist`).

```bash
docker compose -f docker-compose.php8.yml up -d
# Apply prod canary to mirror once:
docker exec -i ork3-php8-db mariadb -uroot -proot ork < db-migrations/2026-07-07-add-prod-canary.sql
bin/ork-db extract
bin/ork-db extract --table award
bin/ork-db extract --players-only
vendor/bin/phpunit -c phpunit.ork-db.xml.dist
```
