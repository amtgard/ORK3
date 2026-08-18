# bastion-idp: `POST /resources/link-ork-profile`

Companion endpoint required by ORK's streamlined "Sign in with Amtgard" claim flow. ORK calls this after a user has successfully proven ownership of an ORK profile, so that the IDP's `userinfo` response includes `ork_profile.mundane_id` for that user from then on (which lets other Amtgard apps that trust the IDP see the link too).

## Endpoint

`POST /resources/link-ork-profile`

## Authentication

Confidential client basic auth. Use the existing `ORK_CLIENT_ID` / `ORK_CLIENT_SECRET` pair already configured for the ORK confidential client. No bearer token — this is a server-to-server call, not on behalf of an end user.

## Request

```http
POST /resources/link-ork-profile HTTP/1.1
Host: idp.amtgard.com
Authorization: Basic base64(ORK_CLIENT_ID:ORK_CLIENT_SECRET)
Content-Type: application/json

{
  "idp_user_id": "abc123...",
  "mundane_id": 12345
}
```

## Response

- `204 No Content` on success
- `400 Bad Request` if either field is missing or malformed
- `401 Unauthorized` if client credentials are missing/invalid
- `403 Forbidden` if the calling client is not allowed to write IDP→ORK links (only the ORK confidential client should be)
- `404 Not Found` if `idp_user_id` is unknown to the IDP

## Idempotency

Re-posting the same `(idp_user_id, mundane_id)` pair is a no-op. Posting a new `mundane_id` for an `idp_user_id` that already has a different one **MUST** either reject (409 Conflict) or update — the IDP team should pick the policy that matches their existing data model. ORK currently treats all non-2xx as a retryable failure.

## Behavior

The endpoint should update whatever join table the IDP uses to associate `users` with `ork_profile`. After this call, `GET /resources/userinfo` for the same access token should return `ork_profile.mundane_id = 12345`.

## Why ORK needs this

Today, an unlinked IDP user who signs into ORK gets *"User not found and could not be automatically linked"* and bounces. The new ORK claim flow lets users prove ownership of an ORK profile through ORK itself (password or magic link). After they do, ORK has the link locally — but other Amtgard apps that hit the IDP for `userinfo` won't see it until the IDP is told. This endpoint closes that loop.

## ORK-side caller (for reference)

`orkui/model/model.AmtgardIdpLink.php::linkOrkProfile($idpUserId, $mundaneId)` — POSTs the JSON above. On any non-2xx, ORK marks `ork_idp_auth.idp_mirror_status = 'failed'` and an hourly cron retries.
