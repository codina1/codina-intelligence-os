# SPA Session CSRF Strategy — Phase 01

## Strategy

First-party SPA session authentication with cookie CSRF (Sanctum-style):

1. `GET /api/v1/auth/csrf-cookie` — starts session and issues encrypted `XSRF-TOKEN` cookie.
2. Client sends `X-XSRF-TOKEN` (or `X-CSRF-TOKEN` / `_token`) on all state-changing requests (`POST`/`PUT`/`PATCH`/`DELETE`).
3. Session cookie authenticates the user after `POST /api/v1/auth/login` (also CSRF-protected).

## Middleware

API stack includes `EncryptCookies`, session start, and `VerifySpaCsrfToken` (does **not** skip verification under PHPUnit so CSRF can be tested).

Missing/invalid tokens return **419** with `error.code = csrf_token_mismatch`.
