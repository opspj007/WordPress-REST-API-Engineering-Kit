# Architecture & decisions

## Layout
```
includes/
  Plugin.php, Installer.php      composition root, schema migrations
  Http/                          cross-cutting: Request_Lifecycle, Rate_Limiter, Pagination, Error
  Auth/                          Jwt (pure), Token_Service, Authenticator
  Api/                           Router, Base_Controller, V1/*, V2/*
  Webhooks/                      Repository, Dispatcher, Signer, Endpoint_Policy
  Docs/OpenApi.php               spec generator
  Support/                       Sanitizer, Secrets
```

## Decisions
1. **Own namespace instead of extending `wp/v2`.** Core routes carry core's contract and capabilities. A separate, versioned namespace gives us a contract we can evolve and document.
2. **Cross-cutting behaviour lives in REST server filters, not controllers.** Rate limiting, request IDs and error formatting apply uniformly (including to errors thrown by core's validation) and controllers contain only business logic.
3. **Pure classes where it matters.** `Jwt` and `Signer` have no WordPress dependency, so the security-critical code is unit-tested without booting WordPress.
4. **One error contract.** All failures are converted to RFC 9457 problems in a single place. Clients branch on `code`.
5. **Versions share logic.** `V2\Items_Controller` extends v1 and overrides only contract-level behaviour (field name, list envelope, pagination).
6. **Docs from the registry.** Handlers carry a small `raek_docs` descriptor; the OpenAPI builder reads arg schemas straight from the registered routes.
7. **Webhooks persist before sending.** Events are written as delivery rows first, so a slow or dead subscriber never affects API latency and nothing is lost on a crash.
8. **Stateless access tokens, stateful refresh tokens.** Access JWTs are verified without DB lookups apart from a user-meta version check; refresh tokens are tracked for rotation and theft detection.

## Extending
- New resource: add a controller under `Api/V1`, implement `Provides_Schemas`, tag handlers with `self::docs(...)`, register it in `Router::controllers()`.
- New event: `do_action('raek_thing_created', $id, $payload, $owner_id)` and add it to `Dispatcher::EVENTS`.
