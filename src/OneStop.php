<?php

namespace Maico\OneStop;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * Settings and hooks for "Sign in with MAICO One-Stop".
 *
 * Register hooks in a service provider's boot() method to fit the package to the application:
 *
 *     OneStop::checkUserUsing(fn (User $user) => $user->is_active ? null : 'Your account is inactive.');
 *     OneStop::afterSignIn(fn (User $user, Request $request) => AuditLog::record(...));
 */
class OneStop
{
    /** Session key holding the user's sign-out count when the session was started through One-Stop. */
    public const SIGN_OUTS_AT_SIGN_IN = 'one_stop.sign_outs';

    /** Header carrying the HMAC-SHA256 signature of a sign-out notice. */
    public const SIGNATURE_HEADER = 'X-One-Stop-Signature';

    /** @var (Closure(Model): ?string)|null */
    protected static ?Closure $userCheck = null;

    /** @var (Closure(Model, Request): mixed)|null */
    protected static ?Closure $afterSignIn = null;

    /** @var (Closure(Model, string): mixed)|null */
    protected static ?Closure $afterSignOutNotice = null;

    /** @var (Closure(Request, string): ?Response)|null */
    protected static ?Closure $failureResponse = null;

    /**
     * Whether One-Stop sign-in is switched on and fully configured.
     */
    public static function enabled(): bool
    {
        return config('one-stop.enabled')
            && config('one-stop.url')
            && config('one-stop.client_id')
            && config('one-stop.client_secret');
    }

    /**
     * One-Stop's origin (scheme, host and port): the only window allowed to frame the application
     * and to receive sign-in problems from it.
     */
    public static function origin(): string
    {
        $parts = parse_url((string) config('one-stop.url'));

        return ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    /**
     * The URL for the application's "Sign in with MAICO One-Stop" button. It lets the person confirm
     * or switch their One-Stop account, as with Google.
     */
    public static function signInUrl(): string
    {
        return route(config('one-stop.routes.name').'redirect', ['prompt' => 'select_account']);
    }

    /**
     * The URL of a configured route ("login_route" or "home_route"). Falls back to the site root
     * when the application has no such route, so a missing route never breaks sign-in.
     */
    public static function routeUrl(string $key): string
    {
        $name = (string) config("one-stop.{$key}");

        return $name !== '' && Route::has($name) ? route($name) : url('/');
    }

    /**
     * Decide whether a matched account may sign in. Return null to allow, or the message to show.
     *
     * @param  Closure(Model): ?string  $callback
     */
    public static function checkUserUsing(Closure $callback): void
    {
        static::$userCheck = $callback;
    }

    /**
     * Run after someone signs in with One-Stop (for example to write an audit entry). Returning a
     * response (such as a redirect) replaces the default redirect to the intended page.
     *
     * @param  Closure(Model, Request): mixed  $callback
     */
    public static function afterSignIn(Closure $callback): void
    {
        static::$afterSignIn = $callback;
    }

    /**
     * Run when One-Stop reports that a linked person signed out there ("logout") or was suspended ("suspended").
     *
     * @param  Closure(Model, string): mixed  $callback
     */
    public static function afterSignOutNotice(Closure $callback): void
    {
        static::$afterSignOutNotice = $callback;
    }

    /**
     * Choose where to send someone when sign-in with One-Stop fails outside One-Stop's frame.
     * Return null to use the default (the sign-in page, or the home page if already signed in).
     *
     * @param  Closure(Request, string): ?Response  $callback
     */
    public static function respondToFailureUsing(Closure $callback): void
    {
        static::$failureResponse = $callback;
    }

    /**
     * @internal
     */
    public static function userProblem(Model $user): ?string
    {
        if (static::$userCheck) {
            return (static::$userCheck)($user);
        }

        // Without a hook, honour a conventional is_active flag when the table has one.
        if (array_key_exists('is_active', $user->getAttributes()) && ! $user->getAttribute('is_active')) {
            return 'Your '.config('one-stop.app_name').' account has been deactivated.';
        }

        return null;
    }

    /**
     * @internal
     */
    public static function signedIn(Model $user, Request $request): mixed
    {
        return static::$afterSignIn ? (static::$afterSignIn)($user, $request) : null;
    }

    /**
     * @internal
     */
    public static function signOutNoticeReceived(Model $user, string $event): void
    {
        if (static::$afterSignOutNotice) {
            (static::$afterSignOutNotice)($user, $event);
        }
    }

    /**
     * @internal
     */
    public static function failureResponse(Request $request, string $message): ?Response
    {
        return static::$failureResponse ? (static::$failureResponse)($request, $message) : null;
    }

    /**
     * Forget every registered hook (used by tests).
     */
    public static function flush(): void
    {
        static::$userCheck = static::$afterSignIn = static::$afterSignOutNotice = static::$failureResponse = null;
    }
}
