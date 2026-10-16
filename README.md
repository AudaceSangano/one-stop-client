# maico/one-stop-client

Optional "Sign in with MAICO One-Stop" for Laravel 12 and 13 applications.

It adds:

- **Single sign-on:** OAuth 2.0 with PKCE. One-Stop vouches for who the person is; the application keeps its own users, roles, permissions and password sign-in.
- **Account linking:** the first time someone signs in with One-Stop, their existing account is linked by verified email. Accounts are never created automatically.
- **Sign-out notices:** when someone signs out of One-Stop or is suspended, sessions started through One-Stop end. Password sessions and API tokens are untouched.
- **Opening inside One-Stop:** One-Stop may frame the application, and no other site may. Sign-in problems inside the frame appear as a One-Stop alert.
- **A sign-in button:** `<x-one-stop::button />`.

While it is disabled or not fully configured, the application behaves exactly as without the package. The routes return 404 and no headers change. If One-Stop is unreachable, people are sent back to password sign-in.

## Install

The package is in a private GitHub repository. In the application's folder:

```bash
composer config repositories.one-stop-client vcs https://github.com/AudaceSangano/one-stop-client.git
composer require maico/one-stop-client:^1.0
```

Composer needs read access to the private repository:

- **On your own machine,** being signed in with `gh` or having SSH set up with GitHub is enough.
- **On a server or in a deployment pipeline,** use a read-only GitHub token. Run `composer config --global github-oauth.github.com TOKEN`, or set the `COMPOSER_AUTH` environment variable. Never commit the token.

For local development of the package itself, an application can use this folder instead:

```bash
composer config repositories.one-stop-client '{"type": "path", "url": "../one-stop-client", "options": {"symlink": true}}'
composer require "maico/one-stop-client:@dev"
```

Then run the installer:

```bash
php artisan one-stop:install
```

**What the installer does, in order:**

1. **Checks what it cannot fix itself, before changing anything.**
   - **The user table** must exist and have an `email` column.
   - **Two named routes are needed:** the sign-in page (`login`) and the page people land on after signing in (`home`).
   - **If a route is missing,** it offers the app's existing routes to choose from, or you can stop and add the route first.
   - **Without a terminal** (`--no-interaction`), it stops and explains what to add. You can also name routes with `--login-route=NAME` and `--home-route=NAME`.
   - **If it stops, nothing is changed.**
2. **Publishes `config/one-stop.php`,** with the route names you confirmed.
3. **Adds every user column the package needs that is missing.** These are `one_stop_id`, `one_stop_sign_outs` and the last-login column (`last_login_at` by default). It creates a migration with just those columns and runs only that migration, never your other pending ones. Use `--no-migrate` to create the migration without running it.
4. **Adds the settings to `.env` and `.env.example`.** It never overwrites existing values.
5. **Prints what to register in One-Stop.**

Running it again is safe.

**Options:** `--url`, `--client-id`, `--client-secret`, `--enable`, `--login-route`, `--home-route`, `--no-migrate`, `--force`.

**To check the setup at any time,** run this read-only command:

```bash
php artisan one-stop:check
```

It checks the routes, columns, settings, and whether One-Stop can be reached.

Then:

1. **Register the application in One-Stop.** Go to Administration → Applications, choose the *Single sign-on* integration, and enter the three addresses the installer printed:
   - `…/auth/one-stop/redirect`
   - `…/auth/one-stop/callback`
   - `…/auth/one-stop/sign-out`
2. **Configure `.env`.** Copy the client ID and secret One-Stop shows into `.env`:
   ```
   ONE_STOP_ENABLED=true
   ONE_STOP_URL=https://onestop.maico.co.rw
   ONE_STOP_CLIENT_ID=...
   ONE_STOP_CLIENT_SECRET=...
   ```
3. **Add the button** to your sign-in page:
   ```blade
   <x-one-stop::button class="btn btn-secondary" />
   ```
   Or build your own link with `\Maico\OneStop\OneStop::enabled()` and `\Maico\OneStop\OneStop::signInUrl()`.

## Inside One-Stop: hide parts of the page, and sign-in through One-Stop only

**1. Hide or show parts of the page.** Put this tag in the `<head>` of your layout:

```blade
<x-one-stop::head />
```

Then mark elements with a class:

```blade
<form method="POST" action="{{ route('logout') }}" class="standalone-only">…Sign out…</form>   {{-- hidden inside One-Stop --}}
<a href="…" class="one-stop-only">…</a>                                                      {{-- shown only inside One-Stop --}}
```

Hide things One-Stop already handles: **Sign out**, lock screen, switch account, and your own top logo bar if it duplicates One-Stop's header. Opened directly in a browser, the page is unchanged. The tag outputs nothing while One-Stop sign-in is disabled.

**2. Keep the password form out of One-Stop.** Inside One-Stop, a request for your sign-in page (`login_route`) goes to "Sign in with MAICO One-Stop" instead. People are signed in through One-Stop and never see the password form there. Opened directly in a browser, your sign-in page works as before. This is on by default; to allow the password form inside One-Stop, set the following in `config/one-stop.php`:

```php
'password_sign_in_inside_one_stop' => true,
```

**Who can sign in at all:**
- **Access to the application** is decided in One-Stop, under Administration → Access.
- **Which accounts are accepted** is decided by your application, with `OneStop::checkUserUsing(...)` (below).

## Fit it to your application

`config/one-stop.php` sets:

- **`app_name`:** the name used in messages.
- **`users.model`:** the user model.
- **`users.last_login_column`:** the last-login column.
- **`login_route` and `home_route`:** the named routes used for sign-in and after sign-in.
- **`routes.prefix`:** the route prefix.
- **`allow_embedding`:** whether One-Stop may show the application inside its own page.

Hooks go in a service provider's `boot()`:

```php
use Maico\OneStop\OneStop;

// Who may sign in (default: honours an is_active column if the table has one).
OneStop::checkUserUsing(fn (User $user) => $user->status === 'Active' ? null : 'Your account is inactive.');

// After a One-Stop sign-in, e.g. write to your audit log. Return a response to choose the redirect.
OneStop::afterSignIn(fn (User $user, Request $request) => AuditLog::record('Signed in with One-Stop', $user));

// When One-Stop reports a sign-out ("logout") or suspension ("suspended").
OneStop::afterSignOutNotice(fn (User $user, string $event) => AuditLog::record("One-Stop {$event}", $user));

// Where to send people when sign-in fails outside One-Stop (null keeps the default).
OneStop::respondToFailureUsing(fn (Request $request, string $message) => null);
```

## Development

```bash
composer install
vendor/bin/phpunit
```

## Releasing

Versions are git tags. Do not add a `version` field to `composer.json`.

1. Make sure `vendor/bin/phpunit` passes.
2. Add the changes to `CHANGELOG.md`.
3. Tag and push the release:

   ```bash
   git tag v1.1.0 && git push origin main --tags
   ```

4. In each application, run `composer update maico/one-stop-client`.

Version numbers:

- **Patch** (`v1.0.1`): fixes only.
- **Minor** (`v1.1.0`): new features that do not break applications.
- **Major** (`v2.0.0`): changes that need applications to adapt.
