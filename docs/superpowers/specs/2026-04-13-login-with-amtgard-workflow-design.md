# Login with Amtgard — Streamlined Workflow

**Date:** 2026-04-13
**Branch:** `feature/login-with-amtgard-workflow`
**Status:** Design approved, awaiting implementation plan

## Goals

A. Make it seamless for someone to register an Amtgard IDP account with their identity provider of choice (Google, Discord, etc.) through "Sign in with Amtgard."
B. Make it seamless for that user to connect (claim) their existing ORK profile to that IDP identity.
C. Make subsequent sign-in as close to one-click — and ideally zero-click — as possible.

## Problem

Today's flow:
1. User clicks "Sign in with Amtgard" on `/orkui/Login` (`controller.Login.php::login_oauth()`).
2. PKCE OAuth round-trip to `IDP_BASE_URL` (bastion-idp).
3. User signs in with Google/Discord/etc. on the IDP.
4. IDP redirects back to `oauth_callback`, which calls `userinfo` and hands the response to `class.Authorization.php::AuthorizeIdp()`.
5. `AuthorizeIdp` looks up `ork_idp_auth` by `idp_user_id`. If missing, it tries to match by `ork_profile.mundane_id` *as returned by the IDP itself.*
6. If the IDP didn't already have an ORK profile linked, the user sees: *"User not found and could not be automatically linked."* and is bounced back to the login page with no path forward.
7. The user has to find the legacy login, sign in with their ORK credentials, then come back and click "Sign in with Amtgard" again — and only then does the link get established (and only because the IDP has now caught up via some side channel).

The root cause is that **linking lives entirely on the IDP side**, and ORK has no flow to claim a profile from an authenticated IDP session. There is no "you're authed via IDP, now prove which ORK profile is yours" handoff.

## Approach

ORK-side claim flow with auto-link on verified email match, password fallback, magic-link last-resort, and a server-to-server mirror back to bastion-idp.

### Brainstorming forks resolved

| Fork | Decision |
|---|---|
| Where does linking live? | **ORK-side.** ORK owns its own credentials and can iterate without IDP changes. |
| Scope of claim flow | **Claim existing profiles only.** New player creation stays a Prime Minister responsibility. Routes are structured so a "create new" branch can be added later. |
| Verification method | **Auto-link on `idp.email == ork.email` exact-and-unique match → password fallback → magic-link last resort.** Auto-link relies on the IDP's existing email verification (Google/Discord/etc.) — re-verifying is theater. |
| Returning-user UX | **Promoted button on `/Login` + opt-in `ork_idp_autoredirect=1` cookie for zero-click.** Power users can skip the login page entirely; default UX is one click. |
| IDP write-back | **In scope.** Project ships a PR to `amtgard-bastion-idp` adding `POST /resources/link-ork-profile`, plus the ORK-side caller. Mirror failures are recoverable via a retry job. |

### Trust model

- ORK trusts IDP-verified emails for the auto-link path. Justified because the IDP only marks emails verified after Google/Discord/etc. confirms them via their own OAuth flow.
- bastion-idp trusts ORK's confidential-client credentials for the mirror endpoint. Same trust ORK uses today to redeem auth codes.
- Neither side ever sees the user's password from the other.

## Architecture

### ORK3 side

**Controller** — `orkui/controller/controller.Login.php`

- Existing `login_oauth()` — unchanged. Still initiates PKCE.
- Existing `oauth_callback()` — refactored to *route* on result rather than error. After token exchange + `userinfo`, calls `AuthorizeIdp()` and redirects based on the returned status (`logged_in`, `needs_claim`).
- New `claim_profile()` (GET) — renders the claim form. Reads `idp_user_id`, `email`, `access_token`, `refresh_token`, `expires_at` from the session. If session is stale, redirects to `/Login` with a banner.
- New `claim_submit()` (POST) — handles the password verification path. On success, finalizes the link, mirrors to IDP, issues an ORK session token, sets `ork_idp_autoredirect=1`, and redirects to dashboard.
- New `claim_magic_link()` (GET, takes `?token=`) — consumes a magic-link token. Same finalization as `claim_submit` on success.
- New `claim_request_magic_link()` (POST) — takes a mundane id / username, generates a token row, sends the email, redirects back to claim form with a *"Check your email"* banner.

**Authorization** — `system/lib/ork3/class.Authorization.php`

- Refactor `AuthorizeIdp()` — keeps its current "find existing link → log in" responsibility but, instead of erroring on no match, returns a status code (`LOGGED_IN`, `NEEDS_CLAIM`) for the controller to route on. The match-by-`mundane_id`-from-userinfo branch is removed entirely (that linking now happens through the explicit claim flow).
- New `tryAutoLinkByEmail($idpEmail)` — returns one of `LINKED`, `NONE`, `AMBIGUOUS`. Looks up the player's email-of-record (likely `ork_mundane.email` — implementation plan should confirm the canonical column) using exact case-insensitive match. If exactly one match and that mundane has no `ork_idp_auth` row, writes the link, calls `mirrorLinkToIdp`, returns `LINKED`. Otherwise returns the appropriate status.
- New `verifyClaimCredentials($identifier, $password, $idpUserId, $idpEmail, $tokens)` — reuses the existing ORK login password check verbatim. On success, writes `ork_idp_auth`, calls `mirrorLinkToIdp`, returns success.
- New `issueClaimMagicLink($identifier)` — finds the target ORK profile, generates a 64-char random token, writes a row to `ork_idp_claim_token` with 24h expiry, returns the token (caller sends the email).
- New `consumeMagicLink($token)` — looks up token, validates not expired and not consumed, marks consumed, finalizes the link the same way `verifyClaimCredentials` does.
- New `mirrorLinkToIdp($idpUserId, $mundaneId)` — calls the new IDP endpoint via the new model. On failure, sets `ork_idp_auth.idp_mirror_status='failed'` and logs. Never throws into the user's path.

**Model** — `orkui/model/model.AmtgardIdpLink.php` (new)

- `linkOrkProfile($idpUserId, $mundaneId)` — curl POST to `IDP_API_URL/resources/link-ork-profile` with confidential-client basic auth and JSON body. Returns boolean.

**Templates**

- `orkui/template/revised-frontend/Login_index.tpl` and `orkui/template/default/Login_index.tpl`:
  - "Sign in with Amtgard" promoted to the primary visual button. Legacy username/password collapsed under a *"Use legacy ORK login"* disclosure (`<details>` element or equivalent).
  - Inline script: on page load, if `document.cookie` contains `ork_idp_autoredirect=1`, immediately `window.location = '<?= UIR ?>Login/login_oauth'`.
  - Inside the legacy disclosure, a *"Sign in with a different account"* link that sets `ork_idp_autoredirect=0` (immediate expire) and stays on the page.
- `orkui/template/revised-frontend/Login_claim.tpl` (new):
  - Header: *"You're signed in as `email@example.com` via Amtgard IDP."*
  - Primary form: mundane id / username + password.
  - Secondary action: *"Forgot password? Email me a one-time link instead"* (toggles or links to the magic-link request form).
  - Footer: *"Don't have an ORK profile yet? Ask your park's Prime Minister to create one, then come back."*
  - All `pn-` style prefix applied per project convention. Inline CSS/JS only. Per project memory, all headings inside the card explicitly reset the global `h1–h6` styles.

**Email** — `system/lib/ork3/class.Email.php` (or wherever the existing send helpers live)

- `sendIdpClaimMagicLink($email, $token)` — uses the existing email-template plumbing. Body links to `<?= UIR ?>Login/claim_magic_link?token=...`.

**Cron** — `cron/idp-mirror-retry.php` (new)

- Runs hourly. Selects `ork_idp_auth` rows with `idp_mirror_status IN ('pending','failed')`. For each, calls `mirrorLinkToIdp`. Updates status and `idp_mirror_last_attempt`. Caps at ~5 retries before alerting.

### bastion-idp side (separate PR)

- New route: `POST /resources/link-ork-profile`
  - Auth: confidential client (the ORK_CLIENT_ID / ORK_CLIENT_SECRET pair already exists)
  - Body: `{ idp_user_id, mundane_id }`
  - Action: updates the IDP's user → ork profile join for that user (idempotent — re-posting the same pair is a no-op)
  - Returns: `204 No Content` on success, `4xx` with JSON error on failure
- One controller file, one route registration, one Phinx migration if the IDP's existing `ork_profile` join doesn't already have a column for the link source / timestamp.
- Will reach out to whoever owns the bastion-idp repo before opening the PR.

## Data flow — every path

### Path 1: First-time IDP user, email auto-links (best case)

1. Click "Sign in with Amtgard" → IDP → pick Google → consent → IDP redirects to `oauth_callback`.
2. Callback exchanges code, calls `userinfo`. Response has `idp_user_id` + `email`, no `ork_profile`.
3. `AuthorizeIdp` finds no `ork_idp_auth` row. Calls `tryAutoLinkByEmail($email)`.
4. Exactly one mundane email-of-record matches. Writes `ork_idp_auth` row, calls `mirrorLinkToIdp`. Returns `LOGGED_IN`.
5. Controller issues ORK session token, sets `ork_idp_autoredirect=1` cookie (since they came in via the IDP button, they clearly want it), redirects to dashboard.

**Clicks after IDP consent: zero.**

### Path 2: First-time IDP user, no/ambiguous email match (claim form)

1. Steps 1–3 as above.
2. `tryAutoLinkByEmail` returns `NONE` or `AMBIGUOUS`.
3. Callback stashes `idp_user_id`, `email`, `access_token`, `refresh_token`, `expires_at` in session and redirects to `Login/claim_profile`.
4. Claim page shows the form. If `AMBIGUOUS`, a banner explains *"Multiple ORK profiles share that email — please sign in to confirm which one is yours."*
5. **Password path**: user submits identifier + password → `claim_submit` → `verifyClaimCredentials` → on success, finalize link + mirror + session + cookie + dashboard.
6. **Magic-link path**: user clicks *"Email me a link"* → enters mundane id → `issueClaimMagicLink` → email sent → user clicks link → `claim_magic_link` → `consumeMagicLink` → same finalization.

### Path 3: Returning linked user, default UX (one-click)

1. Lands on `/orkui/Login`. Sees promoted "Sign in with Amtgard" button.
2. One click → IDP recognizes existing IDP session (or prompts only if needed) → silent redirect back → `AuthorizeIdp` finds matching row → logged in.

**Clicks on ORK pages: 1.** Goal C met.

### Path 4: Returning linked user, autoredirect cookie (zero-click)

1. Lands on `/orkui/Login`. Inline script sees `ork_idp_autoredirect=1` and immediately navigates to `Login/login_oauth`.
2. Same silent IDP round-trip → dashboard.

**Clicks on ORK pages: 0.** Power-user mode.

Escape hatch: legacy disclosure includes *"Sign in with a different account"* which sets `ork_idp_autoredirect=0` and stays on the page.

### Path 5: Legacy login

1. Click *"Use legacy ORK login"* disclosure → reveals classic form → unchanged behavior.

## Schema changes

```sql
-- New table: magic-link tokens for password fallback
CREATE TABLE ork_idp_claim_token (
    token CHAR(64) NOT NULL PRIMARY KEY,
    idp_user_id VARCHAR(255) NOT NULL,
    idp_email VARCHAR(255) NOT NULL,
    mundane_id INT NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    consumed_at DATETIME NULL,
    INDEX idx_mundane (mundane_id),
    INDEX idx_expires (expires_at)
);

-- IDP mirror retry tracking on existing table
ALTER TABLE ork_idp_auth
    ADD COLUMN idp_mirror_status ENUM('pending','synced','failed') NOT NULL DEFAULT 'pending',
    ADD COLUMN idp_mirror_last_attempt DATETIME NULL;
```

Migration file: `db-migrations/2026-04-13-idp-claim-flow.sql`. Reversible via `DROP TABLE ork_idp_claim_token; ALTER TABLE ork_idp_auth DROP COLUMN ...`.

No changes to `ork_player`, `ork_mundane`, or any other existing table.

## Files touched (ORK3)

| File | Change |
|---|---|
| `db-migrations/2026-04-13-idp-claim-flow.sql` | new — schema above |
| `system/lib/ork3/class.Authorization.php` | refactor `AuthorizeIdp`; add `tryAutoLinkByEmail`, `verifyClaimCredentials`, `issueClaimMagicLink`, `consumeMagicLink`, `mirrorLinkToIdp` |
| `orkui/controller/controller.Login.php` | refactor `oauth_callback` to dispatch; add `claim_profile`, `claim_submit`, `claim_request_magic_link`, `claim_magic_link` |
| `orkui/model/model.AmtgardIdpLink.php` | new — `linkOrkProfile($idpUserId, $mundaneId)` |
| `orkui/template/revised-frontend/Login_index.tpl` | promote IDP button, add legacy disclosure, add autoredirect cookie script |
| `orkui/template/default/Login_index.tpl` | same promotion + script |
| `orkui/template/revised-frontend/Login_claim.tpl` | new — claim form |
| `system/lib/ork3/class.Email.php` (or local equivalent) | add `sendIdpClaimMagicLink` template |
| `cron/idp-mirror-retry.php` | new — hourly retry job |

## Files touched (bastion-idp, separate PR)

- New controller for `POST /resources/link-ork-profile`
- New route registration
- Phinx migration if `ork_profile` join needs a `linked_via` / `linked_at` column

## Error handling

| Condition | Response |
|---|---|
| `oauth_callback` missing/invalid `code` | Redirect to `/Login` with banner *"IDP did not return an authorization code."* |
| Token exchange fails | Banner *"Couldn't reach Amtgard IDP. Try again or use legacy login."* |
| `userinfo` returns no email | Claim form opens with *"Your IDP didn't share an email — sign in manually below to link."* |
| Auto-link finds zero matches | Claim form opens normally |
| Auto-link finds 2+ matches | Claim form opens with multi-match banner |
| Password verify fails | Claim form re-renders with *"Username or password incorrect"* (matches legacy wording — no info disclosure) |
| Magic-link token missing | Page: *"That link isn't valid."* |
| Magic-link token expired | Page: *"That link has expired. Start over from the login page."* |
| Magic-link token already consumed | Page: *"That link has already been used."* |
| Target ORK profile already IDP-linked | Claim form rejects: *"This ORK profile is already linked to another Amtgard account."* + support contact |
| `mirrorLinkToIdp` fails | Silent — local link succeeds, `idp_mirror_status='failed'`, retry job picks it up |
| Stale claim session | Redirect to `/Login` with *"Session expired — please start over."* |

## Testing strategy

**PHPUnit / integration tests** for `class.Authorization.php` methods using a fixtured DB. Per the project's hard rule, every test asserts after `$DB->Clear()`:

- `tryAutoLinkByEmail` — zero match, one match, multi-match
- `verifyClaimCredentials` — happy path, wrong password, nonexistent identifier, profile already IDP-linked
- `issueClaimMagicLink` + `consumeMagicLink` — happy, expired, reused, wrong target
- `mirrorLinkToIdp` — success path mocked, failure path sets `idp_mirror_status='failed'`

**Controller-level smoke tests** for each new action — assert redirect targets and session keys.

**Manual browser walkthroughs** (per the project's debugging-in-browser convention):

- All five paths from the data flow section walked end-to-end against a local bastion-idp dev container
- `ork_idp_autoredirect=1` cookie behavior verified in a private window
- Legacy login still works
- "Sign in with a different account" escape hatch clears the cookie

**Manual rollback drill** — confirm migration reverses cleanly on a copy of the dev database.

## Out of scope (explicit YAGNI)

- New player creation through the IDP flow (claim-only by design)
- Multiple IDP identities per ORK profile (Google + Discord both linked to one player)
- Self-service IDP unlinking from a settings page
- IDP `prompt=none` silent re-auth (true zero-click without a cookie; deferred until we confirm bastion-idp supports it)
- Migration of legacy ORK passwords to delegated-only mode

## Follow-up work

- Confirm bastion-idp supports `prompt=none`. If yes, ship a small follow-up that fires a hidden silent-auth check on the login page and auto-redirects on success — delivers true zero-click for returning users without relying on the cookie.
- Settings page entry to view linked IDP identities and unlink them.
- Open the path for "create new ORK profile from IDP info" once park assignment / Prime Minister approval workflow is designed.
