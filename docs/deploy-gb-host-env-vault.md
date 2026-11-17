# Deploy: generic-green-blue + host-env-vault (ORK3)

Operator runbook for **blue-green web deploys** with **host-env-vault** inject mounts. Use **`gb deploy`** to build the inactive slot, flip nginx, and leave the previous color **running** so **`gb revert`** can roll nginx back without a rebuild.

ORK3 has one vault profile: **`ork-web`**. PHP loads repo **`.env`** (non-secrets), then **`/run/secrets/secrets.env`** when present. Optional inject file **`ga4-sa.json`** lands under the mount’s `files/` tree.

Related:

- [host-env/README.md](../host-env/README.md) — catalog, manifests, secrets-mode
- [gb-host-env-vault-plan.md](planning/gb-host-env-vault-plan.md) — design decisions for this stack

External toolkits (install on the host or as a sibling checkout on a laptop):

- [generic-green-blue-deployment](https://github.com/esdraelon/generic-green-blue-deployment) — `gb`, nginx color-symlink, compose slots
- [host-env-vault](https://github.com/esdraelon/host-env-vault) — sync, render, encrypted **host-store**, SSM cutover (later)

---

## Architecture (what happens on `gb deploy`)

```text
┌─────────────────────────────────────────────────────────────────┐
│ Host (laptop or production)                                      │
│  .greenblue              gb deploy                                 │
│       │                      │                                     │
│       ▼                      ▼                                     │
│  gb-pre-hev-sync.sh ──► catalog fragment → vault dev-shared      │
│       │                      │                                     │
│       ▼                      ▼                                     │
│  host-env-vault sync ──► bundles in state_dir                    │
│       │                      │                                     │
│       ▼                      ▼                                     │
│  host-env-vault render ► {render_root}/ork-web/current/          │
│                          secrets.env + files/                      │
│       │                      │                                     │
│       ▼                      ▼                                     │
│  GB_HEV_MOUNT_MAP ─────► .gb/runtime.env (ORK_HEV_WEB_SECRETS)   │
│       │                      │                                     │
│       ▼                      ▼                                     │
│  docker compose ────────► mount …/current → /run/secrets:ro    │
│       │                      │                                     │
│       ▼                      ▼                                     │
│  config/bootstrap.php ───► .env then secrets.env (fill-unset)    │
└─────────────────────────────────────────────────────────────────┘
```

**Slots (unchanged from legacy ORK3 compose):**

| Slot | Container | Host port |
| --- | --- | --- |
| Green | `ork3-php8-app-green` | **19080** |
| Blue | `ork3-php8-app-blue` | **29080** |

**Database:** MariaDB stays on **`ork3-php8-net`** and is **not** slot-swapped. Dev **`gb deploy`** (via **`GB_HOOK_POST_COMMAND`**) starts **`ork3-php8-test-db`**, runs **`bin/ork-db deploy-sandbox`**, sets profile **`dev`** (sandbox / `ork_test`), and curls the UI — no separate ork-db step after deploy.

**Blue-green behavior:** `generic-green-blue` with **`GB_NGINX_STRATEGY=color-symlink`** starts or refreshes the **inactive** slot, health-checks it, then points nginx at that slot. The **previous color keeps running**. Use **`gb revert`** to point nginx at the other slot again. This replaces the old `greenblue.sh` pattern that stopped the old container.

---

## Prerequisites

| Requirement | Dev (laptop) | Production (host) |
| --- | --- | --- |
| Docker + Compose v2 | Yes | Yes |
| **generic-green-blue-deployment** | Sibling repo or `/var/gb/…` | `/var/gb/generic-green-blue-deployment` |
| **host-env-vault** binary | `cargo build --release` or `PATH` | `/usr/local/bin` or package |
| App repo | ORK3 clone | e.g. `/var/www/ork.amtgard.com` |
| Non-secret config | `.env` from `.env.example` | Host `.env` — DB host, URLs, flags |
| Secrets | `dev-shared/secrets/` (gitignored on vault side) | **Later:** SSM via vault; **now:** host-store or plaintext staging |

---

## Phase 1 — Install generic-green-blue (`gb`)

```bash
sudo mkdir -p /var/gb
sudo git clone https://github.com/esdraelon/generic-green-blue-deployment.git \
  /var/gb/generic-green-blue-deployment
/var/gb/generic-green-blue-deployment/tests/smoke.sh
```

From a dev machine with a sibling checkout:

```bash
cd /full/path/to/ORK3
../generic-green-blue-deployment/bin/gb "$PWD" deploy
```

The first argument to `gb` is always the **ORK3 repo root** (directory containing `.greenblue`), not the toolkit path.

---

## Phase 2 — Install host-env-vault

### Laptop

```bash
cd /path/to/host-env-vault
cargo build --release
./scripts/local-shared-bootstrap.sh
```

If the vault is not at the default relative path, set in `.greenblue` (see `.greenblue.example.dev`):

```bash
GB_HEV_REPO="/path/to/host-env-vault"
GB_HEV_CONFIG="${GB_HEV_VAULT_ROOT}/dev-shared/host-config.toml"
```

### Production (SSM — **later operator step**)

This stack does **not** implement SSM wiring in application code. When the host is ready:

1. Complete **host-env-vault** production setup (systemd sync, `/etc/host-env-vault/config.toml` with `[sync] source = "ssm"`).
2. Import or migrate secrets, then run **`host-env-vault store export`** per the vault repo’s [transition](https://github.com/esdraelon/host-env-vault/blob/main/docs/transition.md) docs — that export is **out of band** for this ORK3 milestone stack.
3. Set **`GB_HEV_SYNC_ON_DEPLOY=1`** and **`GB_HEV_RENDER_ON_DEPLOY=1`** in prod `.greenblue` (already in `.greenblue.example.prod`).
4. Deploy with **`--hev-config /etc/host-env-vault/config.toml`**.

Until SSM is live, use **host-store** or **plaintext** on the server as described below. **Staging** may keep **`docker-compose.staging.yml`** and **`.stage.env`** in **plaintext** mode with no vault on that host.

---

## Phase 3 — Secrets posture (pick one)

| Mode | Command | When |
| --- | --- | --- |
| **plaintext** | `./scripts/ork3-secrets-mode.sh plaintext` | Staging escape hatch; secrets only in app `.env`; gb skips HEV sync/render/mounts |
| **local** | `./scripts/ork3-secrets-mode.sh local` | Laptop profile files under vault `dev-shared/secrets/` |
| **host-store** | `./scripts/ork3-secrets-mode.sh host-store` | After `migrate-ork3-secrets-to-host-store.sh`; encrypted intermediary store |

Modes write **`.gb/secrets-mode`**. Dev `.greenblue.example.dev` sources **`host-env/load-secrets-mode.sh`**, which reads that file and sets **`GB_HEV_SYNC_SOURCE`**, sync/render flags, and **`GB_HEV_MOUNT_MAP`**.

Check current mode:

```bash
./scripts/ork3-secrets-mode.sh status
```

### 3.1 Catalog fragment

**`scripts/gb-pre-hev-sync.sh`** (dev: sourced from `.greenblue.example.dev`; prod: **`GB_HOOK_PRE_DEPLOY`**) copies **`host-env/catalog.d/30-ork3.json`** into the vault **`dev-shared/catalog.d/`** and refreshes the merged catalog when vault merge scripts exist.

Profile **`ork-web`** inject keys match **`host-env/manifests/ork-web.env-host-vault`** and **`host-env/secrets.example/ork-web.env`** (placeholders only in git).

### 3.2 Seed dev secrets

Copy the example profile and fill **every inject key** (empty values break vault validate/sync):

```bash
cp host-env/secrets.example/ork-web.env \
   /path/to/host-env-vault/dev-shared/secrets/ork-web.env
# edit with real dev values — never commit
```

Optional: place **`ga4-sa.json`** under the vault secrets **`files/`** tree so render includes it in the inject bundle.

### 3.3 Migrate to encrypted **host-store** (optional)

From the ORK3 install root:

```bash
./scripts/migrate-ork3-secrets-to-host-store.sh "$PWD" …
./scripts/ork3-secrets-mode.sh host-store
```

The migrate script reads legacy **`config.php`** / **`.dev.env`** style inputs when present and must **not** print secret values on stdout/stderr. Requires the **`host-env-vault`** binary.

### 3.4 Application `.greenblue` and `.env`

```bash
cp .greenblue.example.dev .greenblue    # or .greenblue.example.prod on the server
cp .env.example .env
```

Edit **`.env`** for non-secrets only. Vault inject keys belong in the profile env file or host-store, not duplicated in `.env` on production.

---

## Phase 4 — Deploy (local, lights out)

One command brings up web + mirror DB + sandbox DB, syncs/render secrets, builds the sandbox schema, points the app at **`ork_test`**, and seeds test logins (`megiddo` / `test-db-player`, `admin` / `password`):

```bash
/path/to/generic-green-blue-deployment/bin/gb /full/path/to/ORK3 deploy
```

Optional checks after deploy:

Confirm the active slot has secrets mounted (replace container name with the slot nginx currently targets, or check both):

```bash
docker exec ork3-php8-app-green bash -lc 'test -f /run/secrets/secrets.env && echo ok'
docker exec ork3-php8-app-blue bash -lc 'test -f /run/secrets/secrets.env && echo ok'
```

Health check on the **slot port** (green **19080**, blue **29080**):

```bash
curl -sf http://127.0.0.1:19080/ | head
# or 29080 when blue is live
```

Inspect mount path wiring:

```bash
grep ORK_HEV_WEB_SECRETS .gb/runtime.env
```

---

## Phase 5 — Production deploy

### One-time

Follow the checklist in **`.greenblue.example.prod`** (nginx `green`/`blue` site files, `ork3-php8-net`, `.env`, vault phase). Minimum copy:

```bash
sudo git clone … /var/www/ork.amtgard.com
cd /var/www/ork.amtgard.com
sudo cp .greenblue.example.prod .greenblue
sudo cp .env.example .env
```

Uncomment and set **`GB_HEV_CONFIG`**, **`GB_HEV_VAULT_ROOT`**, and **`GB_HEV_RENDER_ROOT`** in `.greenblue` when the host vault is configured (or pass **`--hev-config`** on the `gb` command line). For **host-store** before SSM, uncomment the **`load-secrets-mode.sh`** source in `.greenblue` after `ork3-secrets-mode.sh host-store`.

After deploy, **`GB_HOOK_POST_DEPLOY`** runs **`scripts/gb-prod-post-command.sh`**: verifies **`/run/secrets/secrets.env`** on the active slot and curls **`GB_HEALTH_PATH`** (default **`/orkui/`**).

### Routine deploy

```bash
sudo /var/gb/generic-green-blue-deployment/bin/gb \
  --hev-config /etc/host-env-vault/config.toml \
  /var/www/ork.amtgard.com deploy
```

Omit **`--hev-config`** on a laptop using **`dev-shared/host-config.toml`**.

### Rollback (nginx only — previous slot stays up)

```bash
sudo /var/gb/generic-green-blue-deployment/bin/gb /var/www/ork.amtgard.com revert
```

**Both containers remain running.** Revert only flips which port nginx proxies to. You do not need to rebuild the old color.

The legacy **`greenblue.sh`** script may still exist on old hosts until operators switch to **`gb`**; prefer **`gb`** for new deploys.

---

## Migration path (summary)

| Phase | Secrets | Operator action |
| --- | --- | --- |
| **A** | Plaintext `.env` / `.stage.env` | `ork3-secrets-mode.sh plaintext`; staging unchanged |
| **B** | `dev-shared/secrets/ork-web.env` | `local` mode + `gb deploy` |
| **C** | Encrypted host-store | `migrate-ork3-secrets-to-host-store.sh` + `host-store` |
| **D** | AWS SSM | **`host-env-vault store export`** (later); `source = "ssm"` in host config; prod `gb deploy` |

---

## Troubleshooting

| Symptom | Fix |
| --- | --- |
| Vault validate/sync fails on empty inject key | Fill all keys in `ork-web.env` or store |
| No `/run/secrets/secrets.env` in container | HEV sync/render off (plaintext mode?) or failed render; check `.gb/runtime.env` and `ORK_HEV_WEB_SECRETS` |
| Wrong slot live | `gb revert` or re-run `gb deploy`; confirm nginx color-symlink targets |
| `migrate-ork3-secrets-to-host-store.sh` missing binary | Install `host-env-vault`; script exits without printing secrets |

---

## Quick reference

```bash
# Local deploy (repo root = first argument)
../generic-green-blue-deployment/bin/gb "$PWD" deploy

# Secrets posture
./scripts/ork3-secrets-mode.sh local|host-store|plaintext|status

# Migrate to host-store
./scripts/migrate-ork3-secrets-to-host-store.sh "$PWD" …

# Prod
sudo /var/gb/generic-green-blue-deployment/bin/gb \
  --hev-config /etc/host-env-vault/config.toml \
  /var/www/ork.amtgard.com deploy

# Rollback nginx (both slots still running)
sudo /var/gb/generic-green-blue-deployment/bin/gb /var/www/ork.amtgard.com revert
```
