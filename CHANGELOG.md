# Changelog

## v1.0.0 (2026-10-16)

First release.

- **Single sign-on:** "Sign in with MAICO One-Stop" (OAuth 2.0 authorization code with PKCE), with account linking by verified email and the `checkUserUsing`, `afterSignIn`, `afterSignOutNotice` and `respondToFailureUsing` hooks.
- **Signed sign-out notices:** end only the sessions that were started through One-Stop.
- **Opening inside One-Stop:**
  - a `frame-ancestors` header that lets only One-Stop frame the application;
  - sign-in problems are passed to One-Stop and shown as its alert;
  - `<x-one-stop::head />` with the `standalone-only` and `one-stop-only` classes;
  - the password form is kept out of One-Stop's frame.
- **`<x-one-stop::button />`:** the sign-in button.
- **`one-stop:install`:**
  - checks the user table and the routes it needs before changing anything;
  - adds the missing user columns;
  - publishes the configuration and the `.env` settings.
- **`one-stop:check`:** a read-only setup check.
- **Supports** Laravel 12 and 13 and PHP 8.2 and later.
