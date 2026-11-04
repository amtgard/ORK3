# ORK JSON service: Authorization calls

For app developers. The JSON gateway is

    https://ork.amtgard.com/orkservice/Json/index.php?call=Authorization/<Method>

with the request fields passed as `request[Field]` (GET or POST). Every
response carries a `Status` object; `Status.Status` is `0` on success.
Non-browser clients must send the `X-Ork-Key` header they were issued and
should identify themselves with `X-ORK-Client: <App>/<version>`, which becomes
the session's device label.

## Authorize

Sign in, or refresh a session.

| Request | |
|---|---|
| `UserName`, `Password` | sign in with credentials; optional `Client` names the device label |
| `Token` | re-authenticate an existing session token |

| Response | |
|---|---|
| `Token` | the session token to use from now on |
| `UserId` | the player's mundane id |
| `Timeout` | when the session expires (ISO 8601) |

**With `Token`, the token is rotated**: the one you sent stops working and the
response carries its replacement. Persist the new token before anything else.

## WhoAmI

Read-only check of a token: whose is it, and is it still live. **Never rotates
the token**, creates no session and returns no token, so a third-party site
that receives a player's token from an app (the Listmancer flow) can verify
the player without holding anything it could act with, and without having to
relay a rotated token back to the app.

| Request | |
|---|---|
| `Token` | the session token to check |

| Response | |
|---|---|
| `UserId` | the player's mundane id |
| `Timeout` | when the session expires (ISO 8601) |
| `Client` | the device label stored at sign-in (the app's `X-ORK-Client` or login `Client`) |

Failures: an unknown or expired token answers with a non-zero `Status` and the
message "Token could not be found or has expired." The token is never echoed
back. A restricted account (suspended or in the penalty box) answers with
"Your access to the ORK has been restricted."

    curl -H 'X-Ork-Key: <key>' -H 'X-ORK-Client: Listmancer/1.0' \
      'https://ork.amtgard.com/orkservice/Json/index.php?call=Authorization/WhoAmI&request[Token]=<token>'

## DestroySession

Sign out one session.

| Request | |
|---|---|
| `Token` | the session token to end |

`Authorize` and `WhoAmI` are also registered over SOAP, as
`Authorization.Authorize` and `Authorization.WhoAmI`, with the same fields.
`DestroySession` is JSON only.
