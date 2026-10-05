# IDP Flow A — ORK mails the claim code (v2)

Branch: `feature/idp-send-claim-ork-code`

Design reference: `amtgard-idp` → `agent/cursor/ork-link-possession/flow-a-ork-mail-plan.md`.

## ORK env

| Variable | Purpose |
|----------|---------|
| `IDP_VALIDATE_SEND_NONCE_URL` | Optional full URL; default `{IDP_APP_URL}/resources/ork/validate-send-nonce` |
| `IDP_APP_URL` | IDP base (e.g. `https://idp.amtgard.com`) |
| `IDP_LINK_CLIENT_ID` / `IDP_LINK_CLIENT_SECRET` | HTTP Basic for IDP server routes (same as link mirror) |
| `IDP_PROFILE_URL` | Optional; mailed link target |

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
