# Changelog

## 1.0.0
- Versioned namespaces `raek/v1` and `raek/v2` (v2 demonstrates breaking changes: field rename, cursor pagination).
- JWT (HS256) access + rotating refresh tokens with reuse detection; Application Password support via core.
- Fixed-window rate limiting with `X-RateLimit-*` and `Retry-After`.
- RFC 9457 `application/problem+json` errors with stable codes and request IDs.
- Offset pagination (`X-WP-Total*`, RFC 8288 `Link`) and keyset pagination (v2).
- HMAC-signed webhooks with retries, backoff, delivery log and auto-disable.
- OpenAPI 3.1 document generated from the route registry.
