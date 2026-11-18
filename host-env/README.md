# ORK3 + host-env-vault (local dev)

**Full operator runbook:** [docs/deploy-gb-host-env-vault.md](../docs/deploy-gb-host-env-vault.md).

ORK3 uses a single vault profile: **`ork-web`** (`host-env/catalog.d/30-ork3.json`). Manifest: **`host-env/manifests/ork-web.env-host-vault`**. Example inject file (placeholders only): **`host-env/secrets.example/ork-web.env`**.

## One-time laptop setup

1. Bootstrap the shared vault (from your **host-env-vault** checkout):

   ```bash
   cd /path/to/host-env-vault
   ./scripts/local-shared-bootstrap.sh
   ```

2. **Catalog sync:** `scripts/gb-pre-hev-sync.sh` copies `host-env/catalog.d/30-ork3.json` into the vault `dev-shared/catalog.d/` (run automatically from `.greenblue.example.dev` on load, and as **`GB_HOOK_PRE_DEPLOY`** in prod).

3. **Secrets mode** (writes `.gb/secrets-mode`, read by `host-env/load-secrets-mode.sh`):

   ```bash
   ./scripts/ork3-secrets-mode.sh local      # dev-shared profile .env files
   ./scripts/ork3-secrets-mode.sh host-store # after migrate script
   ./scripts/ork3-secrets-mode.sh plaintext  # no vault mounts; .env only
   ./scripts/ork3-secrets-mode.sh status
   ```

4. **Seed values** (on the vault side, gitignored):

   ```bash
   cp host-env/secrets.example/ork-web.env \
      /path/to/host-env-vault/dev-shared/secrets/ork-web.env
   ```

   Fill every inject key. Copy **`ga4-sa.json`** into the vault secrets `files/` tree if you use GA4 reporting.

5. **Green-blue config:**

   ```bash
   cp .greenblue.example.dev .greenblue
   cp .env.example .env
   ```

   Override **`GB_HEV_VAULT_ROOT`** / **`GB_HEV_CONFIG`** in `.greenblue` when the vault is not beside the repo.

6. **Deploy:**

   ```bash
   cd /full/path/to/ORK3
   /path/to/generic-green-blue-deployment/bin/gb "$PWD" deploy
   ```

   Compose reads **`GB_HEV_MOUNT_MAP=ork-web:ORK_HEV_WEB_SECRETS`** via `.gb/runtime.env`. Web containers mount `${ORK_HEV_WEB_SECRETS}:/run/secrets:ro` and set **`HOST_ENV_SECRETS_DIR=/run/secrets`**.

## Verify inject

```bash
docker exec ork3-php8-app-green bash -lc 'test -f /run/secrets/secrets.env && echo ok'
curl -sf http://127.0.0.1:19080/ | head
```

Green listens on **19080**, blue on **29080**. After deploy, the previous color **stays running**; **`gb revert`** flips nginx only.

## Production

Copy **`.greenblue.example.prod`** to **`.greenblue`** on the server. SSM-backed vault and **`host-env-vault store export`** are **later operator steps** (see the deploy runbook). Until then, use **host-store** or **plaintext** as appropriate.

**Staging** may continue **`docker-compose.staging.yml`** with **`.stage.env`** and **plaintext** mode — no vault required on that host.

## Migrate from legacy config

Use **`scripts/migrate-ork3-secrets-to-host-store.sh`** at the repo root to move inject keys from an on-server `config.php` or `.dev.env` into the vault store without printing secrets. Then:

```bash
./scripts/ork3-secrets-mode.sh host-store
```

Re-run **`gb deploy`** to render and mount the new bundle.
