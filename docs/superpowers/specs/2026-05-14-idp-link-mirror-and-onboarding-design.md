# IDP Link Mirror & ORK→IDP Onboarding — Design

**Date:** 2026-05-14
**Branch:** `feature/login-with-amtgard-workflow` (both `ORK3-tobias` and `idp-tobias`)
**Status:** Approved, awaiting implementation plan
**Builds on:** [`2026-04-13-login-with-amtgard-workflow-design.md`](2026-04-13-login-with-amtgard-workflow-design.md)

## Goals

1. **Close the existing mirror loop.** The 04-13 design committed ORK to calling `POST /resources/link-ork-profile` on the IDP whenever an ORK profile gets linked. The endpoint was never built upstream, so today every claim succeeds locally but the mirror call 404s and the hourly retry cron loops forever. Build the endpoint to match the spec already committed at [`docs/integrations/bastion-idp-link-endpoint.md`](../../integrations/bastion-idp-link-endpoint.md).
2. **Add a seamless ORK→IDP onboarding entry point.** A user who logs into ORK with legacy credentials and has no linked IDP identity should be able to set up "Sign in with Amtgard" without leaving the ORK→IDP flow — no separate trip to register on the IDP site first, no fumbling between two apps.
3. **Safeguard user data across the boundary.** The new cross-system trust artifact (a signed handoff token) must be short-lived, replay-protected, audience-bound, and carry no more PII than already crosses today.

## Problem

After the 04-13 design landed, two gaps remain:

- **Gap A (mirror loop).** ORK writes `ork_idp_auth.idp_mirror_status='failed'` on every link because the IDP endpoint doesn't exist. Operationally noisy and architecturally embarrassing.
- **Gap B (onboarding).** A player who has an ORK profile but no IDP account today has to: (1) discover the IDP site, (2) register there, (3) come back to ORK, (4) click "Sign in with Amtgard", (5) hope the email auto-link matches — or fall into the claim form. The 04-13 design handles the *IDP→ORK* direction. It does nothing to help the user who starts on ORK.

The user's framing: *"there is no clean mechanism to create IDP access for login alongside your ORK profile login so that it is seamless."*

## Approach

Two deltas on top of the existing branch.

### Delta 1 — `POST /resources/link-ork-profile` on the IDP fork

Build the endpoint exactly per the integration spec. Confidential-client basic auth via `ClientRestrictedAuthMiddleware`, JSON body `{idp_user_id, mundane_id}`, idempotent, returns `204 No Content`. Coexists with the existing user-initiated `POST /resources/profile/link-ork` form — different audience, different trust model (one is a logged-in user typing ORK credentials into the IDP site; this one is ORK asserting a link it already verified on its own side).

### Delta 2 — ORK→IDP onboarding via signed JWT handoff

After a successful legacy login on ORK, if the mundane has no `ork_idp_auth` row and the 30-day dismiss cookie isn't set, render a dashboard banner:

> Speed up next time — set up your Amtgard sign-in. **[Set it up now]** [Not now]

*Set it up now* → ORK mints a 15-minute HS256 JWT carrying `{sub: mundane_id, email, iss: ork, aud: idp, iat, exp, jti}` → redirects to `IDP_BASE_URL/auth/connect?email=<prefill>&link_token=<jwt>`.

The IDP renders a new tabbed connect page (Log In / Register), pre-filled with the email, preserving `link_token` through the form post. On successful auth (any method — password, Google, Discord), the IDP validates the JWT, records the `jti` to a small replay-protection table, writes the link using **`sub` from the JWT** (not the form's email), and bounces back to the ORK dashboard. The mirror endpoint (Delta 1) is *not* called for this path — the link is being written directly on the IDP side, which is what the mirror exists to achieve.

*Not now* → sets `ork_idp_nudge_dismissed_until=<now+30d>` cookie, redirects back to dashboard.

### Trust model

- The signed JWT is the only new cross-boundary primitive. ORK signs; IDP verifies.
- Shared HS256 secret (`IDP_LINK_TOKEN_SECRET`) deployed to both sides via env.
- Replay-protected by a single-use `jti` claim recorded in a small IDP table.
- The form email on the connect page is for prefill UX only — the linked mundane comes from the JWT's `sub` so a user with a Discord-email IDP account can still link to a Gmail-email ORK profile.
- No password ever crosses the new handoff. ORK already authenticated the user (legacy login). IDP separately authenticates the user (its own method). The JWT only carries the "this ORK mundane authorized this link" assertion.

## Architecture

### ORK3 side

**Authorization** — `system/lib/ork3/class.Authorization.php`

- New `mintIdpLinkToken($mundaneId, $email)` — returns a signed HS256 JWT. Uses `firebase/php-jwt` (already used elsewhere if present; otherwise add to `composer.json`). Pulls secret from `IDP_LINK_TOKEN_SECRET` env. Sets `iss=ork`, `aud=idp`, `sub=(string)$mundaneId`, `email`, `iat`, `exp=iat+900`, `jti=uuidv4`.

**Controller** — `orkui/controller/controller.Login.php`

- New `start_idp_connect()` — requires a current legacy ORK session. Looks up the session's `mundane_id` and email. Calls `mintIdpLinkToken`. Redirects to `IDP_BASE_URL/auth/connect?email=<urlencoded>&link_token=<jwt>`.
- New `nudge_dismiss()` — sets `ork_idp_nudge_dismissed_until` cookie (30 days, HttpOnly, Secure in prod). Redirects to the referer (validated against ORK's own host) or dashboard fallback.

**Dashboard / post-login landing**

- Banner partial rendered when: legacy session present, `ork_idp_auth` row absent for the session's mundane, and the dismiss cookie unset or expired.
- Banner partial: card with copy above, two buttons (POST to `Login/start_idp_connect` and POST to `Login/nudge_dismiss`). All `pn-` style prefix per project convention. Inline CSS only. Headings inside the card reset the global `h1–h6` styles per project memory. Dark-mode compatible per project memory.

No new ORK DB migration. `ork_idp_auth` already exists from the 04-13 work.

### IDP side (`idp-tobias`)

**Migration** — `db/migrations/2026MMDDxxxxxx_link_token_jti.php`

```sql
CREATE TABLE link_token_jti (
    jti CHAR(36) NOT NULL PRIMARY KEY,
    seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_seen_at (seen_at)
);
```

A periodic cleanup of rows older than 24h is nice-to-have, not required for correctness (the table grows ~1 row per onboarding click).

**Service** — `src/Services/OrkLinkTokenService.php`

- `verify(string $jwt): array|null` — verifies HS256 signature with `ORK_LINK_TOKEN_SECRET`, checks `iss=ork`, `aud=idp`, `exp` (with 30s skew), `sub` is positive int, `jti` not already present in `link_token_jti`. On success, INSERTs `jti` and returns `['mundane_id' => int, 'email' => string]`. On any failure, returns null and logs the specific reason server-side (do not leak to browser).

**Controllers** — `src/Controllers/Client/ConnectController.php` (new)

- `showConnect(Request, Response)` — `GET /auth/connect`. Verifies `link_token` query param. If invalid, renders error template. Otherwise: query `users` by `email` claim to pick the default tab — if found, default to Log In with email locked; if not, default to Register with email prefilled but editable. Renders `connect.twig` with `link_token` as a hidden field on both forms.
- `submitConnectLogin(Request, Response)` — `POST /auth/connect/login`. Re-verifies `link_token` (this is the *single* point of consumption; the `jti` insert happens here). Authenticates the user via the existing login pathway. On success, writes the link via the existing `UserOrkProfileRepository::saveOrUpdateProfile()` adapted to accept a `mundane_id` + `linked_via='ork_handoff'` (the existing method may need a thin wrapper). Redirects to `ORK_BASE_URL/` and lets ORK's own routing handle landing.
- `submitConnectRegister(Request, Response)` — `POST /auth/connect/register`. Same `link_token` consumption pattern. Registers the user via the existing `AuthController::register` logic (extract the inner flow into a service method so the registration path can be shared). On success, writes the link and redirects to `ORK_BASE_URL/`.

**Resources controller** — `src/Controllers/Resource/ResourcesController.php`

- New `linkOrkProfile(Request, Response)` — `POST /resources/link-ork-profile`. Reads JSON body `{idp_user_id, mundane_id}`. Validates both fields present and well-formed. Looks up the IDP user by `idp_user_id`. Calls a new `UserOrkProfileRepository::linkExistingUserToMundane($idpUserId, $mundaneId)` that writes the join idempotently. Returns `204` on success, `400` on missing fields, `404` on unknown `idp_user_id`, `409` if `idp_user_id` is already linked to a *different* mundane.

**Routes** — `config/routes.php`

- `POST /resources/link-ork-profile` → `ResourcesController::linkOrkProfile`, behind `ClientRestrictedAuthMiddleware` (same allow-list config used by `link-ork`).
- `GET /auth/connect` → `ConnectController::showConnect`
- `POST /auth/connect/login` → `ConnectController::submitConnectLogin`
- `POST /auth/connect/register` → `ConnectController::submitConnectRegister`

**Template** — `templates/connect.twig` (new)

- Branded shell consistent with `login_form.twig` and `register_form.twig`.
- Tabbed UI: Log In | Register. Default tab driven by server-rendered `defaultTab` variable.
- Email field pre-filled on both tabs; locked on Log In, editable on Register.
- Hidden `link_token` field on both forms; value comes from the verified token.
- Below the form, both federated buttons (if Google/Discord exist on the IDP today) carry `link_token` in their state param so the federated callback can complete the link the same way.

**Env** — `.env.example` adds:
```
# Shared secret with ORK3 for the /auth/connect handoff and link-token verification.
# Must match ORK's IDP_LINK_TOKEN_SECRET exactly. 32+ random bytes, base64.
ORK_LINK_TOKEN_SECRET=
```

## JWT token design

```
Header:  { "alg": "HS256", "typ": "JWT" }
Payload: {
  "iss":   "ork",
  "aud":   "idp",
  "sub":   "<mundane_id, integer cast to string per RFC 7519>",
  "email": "<ork mundane email-of-record>",
  "iat":   <unix>,
  "exp":   <iat + 900>,
  "jti":   "<v4 uuid>"
}
Signature: HS256(IDP_LINK_TOKEN_SECRET)
```

**Verification order on IDP side:**

1. Signature with the shared secret.
2. `iss == "ork"`, `aud == "idp"`.
3. `exp` in future (30s skew tolerance).
4. `sub` is a positive integer.
5. `jti` absent from `link_token_jti` → INSERT (one DB transaction). If present, reject as replay.

After successful authentication on the connect page, the link uses `sub` from the verified JWT, never form data.

## Data flow

### Flow A — ORK user, no IDP account anywhere (the canonical "seamless entry" path)

1. User logs in via legacy ORK form. Lands on dashboard.
2. Dashboard render: `ork_idp_auth` row absent, dismiss cookie unset → banner shows.
3. *Set it up now* → POST `Login/start_idp_connect` → JWT minted → 302 to `IDP/auth/connect?email=...&link_token=...`.
4. IDP `GET /auth/connect` verifies JWT (does **not** consume jti yet — verify on POST), looks up `users` by email, finds none → renders connect page with **Register** tab default, email prefilled, `link_token` in hidden field.
5. User picks Register, fills first/last/password, submits → `POST /auth/connect/register`. JWT re-verified, `jti` recorded. Registration runs, user created. Link written via `UserOrkProfileRepository`. Redirect to `ORK_BASE_URL/`. The user is still on their existing ORK legacy session, so they land on the dashboard normally. The link is in place from this point forward; the next "Sign in with Amtgard" click on a fresh browser (or after logout) will find the link via the existing IDP→ORK path.
6. **Net result:** the next time the user clicks the promoted IDP button (or arrives with `ork_idp_autoredirect=1` from the existing flow), they land directly on the dashboard. Goal A from the 04-13 design ("subsequent sign-in as close to one-click — and ideally zero-click — as possible") now reachable from a legacy-login starting point.

### Flow B — ORK user, IDP account already exists, never linked

Identical to A except step 4 finds an existing `users` row by email → default tab is **Log In**, email locked. User signs in by any method. Step 5 → `POST /auth/connect/login` → JWT consumed, user authenticated, link written, redirect back.

### Flow C — Dismiss

User clicks *Not now* → POST `Login/nudge_dismiss` → cookie set, 302 back to dashboard. Banner hides for 30 days.

### Flow D — Existing IDP→ORK flow (Delta 1 alone)

Unchanged from 04-13 design. Mirror call now succeeds: `ork_idp_auth.idp_mirror_status` transitions from `pending` to `synced` on the inline write or via the hourly retry cron. The retry cron is no longer permanently noisy.

### Flow E — Returning linked user

Unchanged. Promoted IDP button + `ork_idp_autoredirect=1` cookie. Zero-click for the power-user path.

## Error handling

| Condition | Response |
|---|---|
| `link_token` missing on `/auth/connect` | Render error page: *"This link is invalid. Return to ORK and start over."* |
| JWT signature / iss / aud / sub invalid | Same error. Reason logged server-side. |
| JWT expired | Distinct copy: *"This link has expired. Return to ORK to get a fresh one."* |
| JWT replay (`jti` already in table) | Same as expired in the browser; logged distinctly as replay attempt. |
| User authenticates on `/auth/connect/login` but JWT now expired between GET and POST | Expired error; the user can re-trigger the banner from ORK. |
| `start_idp_connect` called without a current ORK session | Redirect to `/Login`. |
| `nudge_dismiss` called with no referer or off-host referer | Redirect to dashboard fallback. |
| Mirror endpoint missing fields | `400 Bad Request` JSON error. |
| Mirror endpoint unknown `idp_user_id` | `404`. ORK retry cron stops after 5 attempts and flags. |
| Mirror endpoint conflict — `idp_user_id` already linked to different mundane | `409`. ORK marks `idp_mirror_status='conflict'` (new enum value) and stops retrying; row gets surfaced for human review. |
| Mirror endpoint receives `(idp_user_id, mundane_id)` already linked together | `204`. Idempotent. |

## Security

- **Secret strength.** `IDP_LINK_TOKEN_SECRET` is 32+ random bytes, base64-encoded. Never logged. Documented in both `.env.example` files. Rotation plan deferred (see Out of scope).
- **No PII expansion.** JWT carries email + mundane_id only — both already crossed the boundary in the existing flow.
- **Short expiry + jti.** 15-minute window plus single-use `jti` insert. Even on full URL leak (referer header, screenshot, browser history sync), reuse is impossible.
- **HTTPS-only in production.** Banner action is a POST that issues the 302; the JWT only travels on the resulting redirect URL, never on a GET the user would naturally share.
- **Mirror endpoint allow-list.** `ClientRestrictedAuthMiddleware` configuration confirmed to contain *only* the ORK confidential client. Asserted as part of the implementation plan.
- **No password on the new boundary.** ORK already authenticated the user (legacy login). IDP separately authenticates (its own method). The JWT only carries the "this ORK mundane authorized this link" assertion.
- **`linked_via` audit column.** `UserOrkProfileRepository` gets a `linked_via` enum on writes: `self_form` (existing user-on-IDP form), `ork_handoff` (new Delta 2 path), `mirror` (new Delta 1 path). Schema add deferred to implementation plan — depends on what `users_ork_profiles` already tracks.
- **Open redirect prevention.** `nudge_dismiss` validates referer against the ORK host before redirecting. The connect page's post-completion redirect to ORK uses a fixed `ORK_BASE_URL` config, not user-controlled input.

## Files touched

### ORK3

| File | Change |
|---|---|
| `system/lib/ork3/class.Authorization.php` | Add `mintIdpLinkToken($mundaneId, $email)` |
| `orkui/controller/controller.Login.php` | Add `start_idp_connect`, `nudge_dismiss` actions |
| Dashboard landing template (path confirmed during plan) | Render banner partial |
| New banner partial (path confirmed during plan) | The card itself |
| `composer.json` / `composer.lock` | Ensure `firebase/php-jwt` present |
| `.env.example` (or wherever ORK config lives) | Document `IDP_LINK_TOKEN_SECRET` |

### idp-tobias

| File | Change |
|---|---|
| `db/migrations/2026MMDDxxxxxx_link_token_jti.php` | `link_token_jti` table |
| `db/migrations/2026MMDDxxxxxx_user_ork_profiles_linked_via.php` | `linked_via` column on existing `users_ork_profiles` (if not already present) |
| `src/Services/OrkLinkTokenService.php` | New — verify + jti record |
| `src/Controllers/Client/ConnectController.php` | New — `showConnect`, `submitConnectLogin`, `submitConnectRegister` |
| `src/Controllers/Client/AuthController.php` | Refactor: extract `register` body into a service method `RegistrationService::register()` so the connect path can reuse it |
| `src/Controllers/Resource/ResourcesController.php` | Add `linkOrkProfile` |
| `src/Persistence/Client/Repositories/UserOrkProfileRepository.php` | Add `linkExistingUserToMundane`; add `linked_via` parameter to existing writes |
| `config/routes.php` | Register the four new routes |
| `templates/connect.twig` | New — tabbed connect page |
| `.env.example` | Add `ORK_LINK_TOKEN_SECRET` |

## Testing

**IDP PHPUnit:**
- `OrkLinkTokenService::verify` — valid, bad signature, wrong iss, wrong aud, expired, replay (second call with same jti), non-numeric sub, malformed JWT
- `ConnectController::showConnect` — default tab selection based on user-exists, error renders on bad token
- `ConnectController::submitConnectLogin` / `submitConnectRegister` — link written with `sub` not form email, jti consumed exactly once
- `ResourcesController::linkOrkProfile` — happy path, missing fields, unknown user, conflict, idempotent re-post, auth required

**ORK PHPUnit (if suite available, otherwise manual):**
- `mintIdpLinkToken` — produces a JWT that round-trips correctly with the same secret
- Banner-render conditional logic — three cases (no link + no cookie → show, no link + cookie set → hide, linked → hide)

**Manual browser walkthrough against local dev IDP container:**
- Flow A end-to-end: legacy login → banner → register on IDP → return to ORK → banner gone → `ork_idp_auth` row + `users_ork_profiles` row both present
- Flow B end-to-end: same with an existing IDP account
- Flow C: dismiss → cookie set → banner gone for 30 days
- Flow D regression: existing IDP→ORK claim flow still works; mirror status goes to `synced`
- Flow E regression: returning user one-click and zero-click both work
- Replay drill: capture a `link_token` URL during a flow, complete the flow, paste the URL again → expired error

**Manual rollback drill:** both new migrations reverse cleanly.

## Out of scope (explicit YAGNI)

- Secret rotation tooling. Two-secret window (`_CURRENT` / `_PREVIOUS`) is a small follow-up if/when rotation pressure arrives.
- Federated provider changes inside the IDP. Google/Discord login on the connect page works by carrying `link_token` through the existing federated state param — no new federation code.
- Settings page to unlink an IDP identity from an ORK profile.
- IDP-side `prompt=none` silent re-auth (already on the 04-13 follow-up list).
- New-player creation through this flow. Still PM responsibility.
- Cleanup of the existing user-initiated `POST /resources/profile/link-ork` and its `profile.twig` form. They serve a different audience (logged-in IDP user wanting to connect) and remain useful. Deprecation, if ever, is a separate decision.

## Follow-up work

- Secret rotation.
- Settings page entry to view linked IDP identity and unlink.
- Telemetry on banner conversion (impressions, click-through, completion) once the flow is live and we want to tune.
- `prompt=none` silent re-auth for true zero-click on returning users — already tracked from 04-13.
