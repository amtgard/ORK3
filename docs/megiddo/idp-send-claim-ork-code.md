# IDP Flow A — ORK mails the claim code (v2)

Branch: `feature/idp-send-claim-ork-code`

Design reference: `amtgard-idp` → `agent/cursor/ork-link-possession/flow-a-ork-mail-plan.md`.

Local IDP ↔ ORK Docker: use `docker-compose.local-idp.yml` overlay (shared network `amtgard-idp-shared` only — not in default dev compose).

## ORK config

Uses existing IDP integration constants (same OAuth client as ORK login):

| Constant | Purpose |
|----------|---------|
| `IDP_API_URL` | Server base; `BeginClaimOrkMail` POSTs `{IDP_API_URL}/resources/ork/validate-send-nonce` with HTTP Basic |
| `IDP_CLIENT_ID` / `IDP_CLIENT_SECRET` | Basic auth (must be listed in IDP `LINK_ORK_PROFILE_ALLOWED_CLIENT_IDS`) |
| `IDP_BASE_URL` | Browser base; claim mail links to `{IDP_BASE_URL}/resources/profile` |

## `BeginClaimOrkMail`

- `call=IdpIntegration/BeginClaimOrkMail`
- `request[SendNonce]=<opaque from IDP>`

ORK POSTs the nonce to the IDP, then mails the code on success. An existing `ork_idp_auth` row for another IDP user does **not** block mail (possession reclaim). Skips for missing mundane / mailbox / throttle still return `Success()` (no oracle).

SMTP diagnostics go to PHP `error_log` (nginx error log in Docker): `IdpIntegration sendClaimMail start|SMTP failure|SMTP completed OK`. In **DEV**, the plaintext code is also logged for local debugging.

## `ValidateClaimOrkCode`

- `call=IdpIntegration/ValidateClaimOrkCode`
- `request[IdpUserId]`, `request[MundaneId]`, `request[Code]`

Success JSON includes `Email` and `MundaneId`. Wrong codes increment `attempts`. On success ORK updates `ork_idp_auth` for that mundane to the claiming `IdpUserId` and clears stored OAuth tokens (fresh login required).

## IDP env

Set `ORK_CLAIM_MAIL_VIA_ORK=1` on the IDP to use this path from the profile Email Code button.

## Production / staging rollout

ORK has **no** Phinx-style migration runner and **`greenblue.sh` does not apply SQL**. Deploy is: build the inactive color with `docker-compose.php8-app.{green|blue}`, health-check, flip nginx, tear down the old container — same as today.

Before or after deploy (operator choice), apply the branch migration **by hand** against the live MariaDB (see root `README.md` → *Applying migrations*):

```bash
mariadb … ork < db-migrations/2026-10-05-ork-idp-mailbox-challenge.sql
```

Then **restart the ORK app container** so APCu schema cache picks up `ork_idp_mailbox_challenge`.

Ensure prod `config.php` already defines `IDP_*` and add `AMAZON_SES_*` for outbound mail (Docker dev: `.dev.env` + `heartbeat.sh`). Staging follows the same manual DB ritual (`staging/README.md`).
