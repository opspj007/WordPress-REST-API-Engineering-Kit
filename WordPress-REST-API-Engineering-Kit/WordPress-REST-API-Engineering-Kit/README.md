# WordPress REST API Engineering Kit

A reference implementation of a **production-grade REST API on WordPress**: the parts most plugins skip, built properly and kept small enough to read in an afternoon.

| Concern | Implementation |
|---|---|
| Custom endpoints | `raek/v1` + `raek/v2` controllers built on `WP_REST_Controller` |
| Authentication | JWT (access + rotating refresh) **and** core Application Passwords |
| Versioning | URL namespaces, shared business logic, opt-in `Deprecation`/`Sunset` headers |
| Rate limiting | Per-user/IP fixed windows, separate stricter bucket for credential endpoints |
| Validation | JSON-Schema driven (400) + semantic rules (422), one error shape |
| Sanitization | Per-field callbacks layered on schema sanitization |
| Error handling | RFC 9457 `application/problem+json`, stable codes, request IDs, no 5xx leaks |
| Pagination | Offset (`X-WP-Total*` + RFC 8288 `Link`) in v1, keyset cursors in v2 |
| Webhooks | HMAC-signed, persisted, retried with backoff, delivery log, auto-disable |
| Docs | OpenAPI 3.1 **generated from the live route registry** at `/raek/v1/openapi` |

```
 client ──► rest_pre_dispatch ──► Request_Lifecycle: request-id, rate limit (429)
              │
              ▼
        Authenticator (Bearer JWT; Basic app-passwords handled by core)
              │
              ▼
   Controller (schema validation → sanitization → permission → business rule)
              │                         │
              ▼                         ▼ do_action('raek_item_*')
   rest_post_dispatch              Webhooks\Dispatcher ─► WP-Cron ─► signed POST (retry/backoff)
   headers + RFC 9457 problems
```

## Install

```bash
composer install          # dev tooling only; the plugin itself has no runtime dependencies
# copy/symlink into wp-content/plugins and activate
```
Requires PHP ≥ 8.0 (with `sodium`) and WordPress ≥ 6.2. Set a stable signing key in `wp-config.php` (otherwise one is generated and stored in options):

```php
define('RAEK_JWT_SECRET', 'at-least-32-random-bytes…');
```

If Apache strips the `Authorization` header, add `SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1` to `.htaccess`.

## Try it

```bash
BASE=https://example.test/wp-json

# 1. Authenticate (JWT)
curl -s $BASE/raek/v1/auth/token -H 'Content-Type: application/json' \
  -d '{"username":"ada","password":"…"}'
# → { "access_token": "…", "expires_in": 900, "refresh_token": "…" }

# 2. Create an item
curl -s $BASE/raek/v1/items -H "Authorization: Bearer $ACCESS" -H 'Content-Type: application/json' \
  -d '{"title":"Ship it","priority":4,"tags":["launch"],"due_date":"2030-01-01T09:00:00Z"}'

# 3. Same API with an Application Password (server-to-server)
curl -s -u 'ada:abcd efgh ijkl mnop qrst uvwx' $BASE/raek/v1/items?status=todo&per_page=10 -i
```

### Endpoints

| Method | Path | Notes |
|---|---|---|
| POST | `/raek/v1/auth/token` · `/auth/refresh` · `/auth/revoke` | public (rate limited 5/min/IP) · revoke needs auth |
| GET | `/raek/v1/auth/me` | current user + `auth_method` |
| GET/POST | `/raek/v1/items` | filters: `status`, `tag`, `search`, `due_before`; sort: `orderby`, `order` |
| GET/PATCH/DELETE | `/raek/v1/items/{id}` | `ETag` out, `If-Match` in (412 on stale write) |
| GET/POST | `/raek/v2/items` | cursor pagination, `due_at`, `{data, meta}` envelope |
| CRUD | `/raek/v1/webhooks`, `…/{id}/deliveries`, `…/{id}/ping`, `…/{id}/rotate-secret` | secret shown once |
| GET | `/raek/v1/health` · `/raek/v1/openapi?version=v1` | public |

Import `/raek/v1/openapi` into Swagger UI, Redoc, Postman or an SDK generator.

## Contracts worth knowing

**Errors** (every status ≥ 400, including core validation failures):
```http
HTTP/1.1 422   Content-Type: application/problem+json   X-Request-Id: 8f1c…
{
  "type": "urn:raek:problem:raek_validation_failed",
  "title": "Unprocessable Entity", "status": 422,
  "detail": "The request body failed validation.",
  "code": "raek_validation_failed",
  "errors": [{ "field": "due_date", "message": "The due date must be in the future.", "code": "invalid" }],
  "request_id": "8f1c…"
}
```
`400` = malformed/typed wrong (schema), `422` = well-formed but breaks a business rule. Branch on `code`, never on `detail`.

**Rate limits**: every response carries `X-RateLimit-Limit/-Remaining/-Reset`; over the limit → `429` + `Retry-After`. Defaults: `auth` 5/min, `write` 30/min, `read` 120/min (override with the `raek_rate_limits` filter).

**Versioning policy**: additive changes ship within a version; breaking changes (renames, envelope changes) get a new namespace. v1 and v2 share one business-logic implementation, so only the contract differs. Retire a version with `add_filter('raek_v1_sunset', fn() => '2027-06-30')` → `Deprecation`, `Sunset` and `Link: rel="successor-version"` headers.

**Webhooks** — events `item.created|updated|deleted` (+ `ping`). Headers: `X-RAEK-Event`, `X-RAEK-Event-Id` (stable across retries → dedupe on it), `X-RAEK-Attempt`, `X-RAEK-Timestamp`, `X-RAEK-Signature: sha256=HMAC(secret, "{timestamp}.{raw body}")`. Verify with the raw body and reject timestamps older than 5 minutes:

```php
$ok = hash_equals(
    'sha256=' . hash_hmac('sha256', $ts . '.' . $rawBody, $secret),
    $_SERVER['HTTP_X_RAEK_SIGNATURE']
) && abs(time() - (int) $ts) <= 300;
```
```js
const expected = 'sha256=' + crypto.createHmac('sha256', secret).update(`${ts}.${rawBody}`).digest('hex');
const ok = crypto.timingSafeEqual(Buffer.from(expected), Buffer.from(signature)) && Math.abs(Date.now()/1000 - ts) < 300;
```
Delivery is at-least-once: 5 attempts with 30 s · 2 min · 8 min · 32 min backoff, auto-disable after 20 consecutive failures.

## Configuration hooks

| Filter | Purpose |
|---|---|
| `raek_rate_limits` | `['bucket' => [limit, window_seconds]]`; return `[]` to disable |
| `raek_client_ip` | Resolve the real IP behind a trusted proxy (never trust raw `X-Forwarded-For`) |
| `raek_access_token_ttl` | Access token lifetime (default 900 s) |
| `raek_v1_sunset` | ISO date to start announcing v1 deprecation |
| `raek_allow_insecure_webhooks` | Allow `http://` callbacks (default only in `local`/`development`) |

## Security notes

- **JWT**: algorithm pinned to HS256, constant-time comparison, iss/aud/exp/nbf enforced, secret ≥ 32 bytes. Refresh tokens are single use with reuse detection; a per-user `ver` claim revokes all tokens at once.
- **Enumeration**: login errors are identical for unknown user vs wrong password; other users' items/webhooks return `404`, not `403`.
- **SSRF**: webhook URLs must be HTTPS, no credentials, public hosts only; delivery uses `wp_safe_remote_post` with redirects disabled. (DNS-rebinding is mitigated by core's post-resolution check, not eliminated; for hostile multi-tenant use, egress through a proxy.)
- **Secrets at rest**: webhook secrets are encrypted with libsodium (`secretbox`, key derived from `AUTH_SALT`).
- **Errors**: 5xx responses never include internal messages; they are logged with the request ID.
- Always serve over HTTPS; Application Passwords and bearer tokens are credentials.

## Known trade-offs

- Transient-based rate limiting is not atomic without a persistent object cache; use a gateway for hard guarantees.
- Webhooks run on WP-Cron: set `DISABLE_WP_CRON` and a real system cron in production, or swap in Action Scheduler.
- Concurrent use of the *same* refresh token can trip reuse detection (fails safe: user signs in again).
- HS256 means the verifier shares the secret. Move to RS256/EdDSA if third parties must verify tokens.

## Development

```bash
composer check              # phpcs + unit tests (no WordPress needed)
npx @wordpress/env start    # integration suite against real WordPress
npx @wordpress/env run tests-cli --env-cwd=wp-content/plugins/$(basename "$PWD") vendor/bin/phpunit -c phpunit.integration.xml.dist
```

See [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) for design decisions.

License: GPL-2.0-or-later
