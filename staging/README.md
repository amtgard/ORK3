# Staging ORK on the "applications" instance

A dev-flavored ORK (`ENVIRONMENT=DEV` → `config.dev.php`) on the shared AWS
`applications` box (t4g.medium arm64, Ubuntu 22.04 — same Graviton arch as the
prod ORK host), living beside the wiki/play/go/idp stacks without touching
them. Public name: **https://staging.amtgard.com** (single-level on purpose —
Cloudflare's universal cert doesn't cover two-level names; the warning
triangle on idp.dev.amtgard.com in the CF dash is that exact problem).

**Operating rules** (Ken, 2026-08-24):
- Nothing automated ever touches the staging DB — Ken restores seed backups
  by hand, exactly like a local install; the staging schema may deliberately
  diverge (testing migrations before prod runs them).
- Deploys are the local ritual: `git pull`, apply DB changes by hand when
  needed, restart the app container when needed.
- The instance is shared production for other apps: every server-side step
  below is run deliberately (and `nginx -t` before any reload), never scripted
  blind.

## One-time bring-up

### 1. On the instance (ssh apps-aws)

```bash
git clone https://github.com/amtgard/ORK3.git /var/www/staging.amtgard.com   # or your fork/remote of choice
cd /var/www/staging.amtgard.com
git checkout master        # staging tracks master unless testing a branch

# Secrets file (never committed). MARIADB_* configure the db container;
# ORK3_DB_* point the app at it (config.dev.php reads them, falling back to
# the local-dev defaults when unset).
cat > .stage.env <<'EOF'
MARIADB_DATABASE=ork
MARIADB_USER=ork
MARIADB_PASSWORD=<generate one>
MARIADB_ROOT_PASSWORD=<generate another>
ORK3_DB_HOST=ork3-stage-db
ORK3_DB_USER=ork
ORK3_DB_PASSWORD=<same as MARIADB_PASSWORD>
EOF
chmod 600 .stage.env

sudo docker compose -f docker-compose.staging.yml up -d --build
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1:41080/orkui/index.php?Route=   # expect 200/302
```

Port map (chosen clear of the neighbors' 21080/31080/32080/33080/37080-1):
app `127.0.0.1:41080`, MariaDB `127.0.0.1:41306`. Nothing binds the public
interface.

### 2. Seed the database (by hand, like local)

```bash
# from the instance; backup file however you get it there (scp via apps-aws)
sudo docker exec -i ork3-stage-db sh -c 'mariadb -u ork -p"$MARIADB_PASSWORD" ork' < seed-backup.sql
```

### 3. DNS + Cloudflare (matching the go/play/wiki pattern)

1. **Route 53** (`apps.amtgard.com` hosted zone, AWS console): A record
   `staging.apps.amtgard.com` → 18.191.144.189 — the direct name, like the
   siblings' `go.apps` / `play.apps`.
2. **Cloudflare DNS** (amtgard.com zone): CNAME `staging` →
   `staging.apps.amtgard.com`, **proxied** (orange) — exactly like the `go` /
   `play` / `wiki` rows.
3. **Origin cert**: SSL/TLS → Origin Server → Create Certificate, hostname
   `staging.amtgard.com`, 15-year validity. Paste cert + key into
   `/etc/ssl/ork-staging/` on the instance (see the vhost's install
   comments). No certbot: its renewals would hit the Access wall.
4. **Zero Trust → Access → Applications → Add application** (self-hosted):
   - Application domain: `staging.amtgard.com`
   - Policy: Allow → Include → Emails → Ken + whoever else
   - Session duration: 1 week is comfortable.
   Everyone else — humans and crawlers alike — is stopped at the edge; the
   origin never sees them, so no robots/noindex worry on top. The direct
   Route 53 name is never proxied to the app: the vhost 301s it to the front
   door.

### 4. Host nginx vhost (on the instance)

Follow the install comments in `staging/nginx-ork-staging.conf` (cert files,
cp, symlink, `nginx -t`, reload).

## Routine operation

```bash
ssh apps-aws
cd /var/www/staging.amtgard.com
git pull                                              # deploy
sudo docker compose -f docker-compose.staging.yml restart ork3app   # when needed
sudo docker exec -it ork3-stage-db sh -c 'mariadb -u ork -p"$MARIADB_PASSWORD" ork'  # SQL console
sudo docker logs --tail 50 ork3-stage-app                  # app logs
```

Memcached lives inside the app container (same as prod): a container restart
clears cache; a bare `git pull` does not (long-TTL ghettocache can serve
pre-pull content — same trap as prod).

## Deliberate differences from prod

- Dev config: errors displayed, no CF analytics keys, empty API keys, cache
  prefix `ork-dev`.
- Seed data, restored manually; schema may be ahead of prod.
- No sitemap submission, no crawler WAF skips, no OG cards reachable —
  Access gates everything (link previews inside Discord will NOT unfurl for
  staging links; that's the gate working).
