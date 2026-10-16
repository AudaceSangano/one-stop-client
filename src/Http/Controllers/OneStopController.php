<?php

namespace Maico\OneStop\Http\Controllers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Maico\OneStop\OneStop;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * "Sign in with MAICO One-Stop" (OAuth 2.0 authorization code flow with PKCE).
 *
 * An optional second way in: the application keeps its own users, roles and password sign-in,
 * and never depends on One-Stop being available. One-Stop only vouches for who the person is;
 * their account in the application must already exist (it is linked by verified email once).
 */
class OneStopController extends Controller
{
    /** Start sign-in: send the person to One-Stop. */
    public function redirect(Request $request): SymfonyResponse
    {
        abort_unless(OneStop::enabled(), 404);

        // Opened inside One-Stop's application frame: report problems to One-Stop instead of
        // showing this application's sign-in page inside the frame.
        $request->session()->put('one_stop.embedded', $request->boolean('embedded') || $request->header('Sec-Fetch-Dest') === 'iframe');

        if (! $this->oneStopIsReachable()) {
            return $this->failed($request, 'One-Stop cannot be reached right now. Sign in with your '.$this->appName().' password instead.');
        }

        $state = Str::random(40);
        $verifier = Str::random(64);
        $request->session()->put('one_stop.state', $state);
        $request->session()->put('one_stop.verifier', $verifier);

        return redirect()->away(config('one-stop.url').'/oauth/authorize?'.http_build_query([
            'client_id' => config('one-stop.client_id'),
            'redirect_uri' => $this->callbackUrl(),
            'response_type' => 'code',
            'scope' => '',
            'state' => $state,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
            // From the application's own sign-in button, let the person confirm or switch their
            // One-Stop account (as with Google); opening from inside One-Stop skips that step.
            ...($request->query('prompt') === 'select_account' ? ['prompt' => 'select_account'] : []),
        ]));
    }

    /** Finish sign-in: exchange the code, identify the person and sign them in to their account. */
    public function callback(Request $request): SymfonyResponse
    {
        abort_unless(OneStop::enabled(), 404);

        $state = $request->session()->pull('one_stop.state');
        $verifier = $request->session()->pull('one_stop.verifier');
        $app = $this->appName();

        if ($request->filled('error')) {
            return $this->failed($request, "One-Stop did not sign you in. You can sign in with your {$app} password instead.");
        }

        if (! is_string($state) || ! hash_equals($state, (string) $request->query('state')) || ! $request->filled('code')) {
            return $this->failed($request, 'That sign-in link has expired. Please try again.');
        }

        try {
            $profile = $this->fetchProfile((string) $request->query('code'), (string) $verifier);
        } catch (ConnectionException|RequestException) {
            return $this->failed($request, "One-Stop could not confirm who you are. Sign in with your {$app} password instead.");
        }

        $user = $this->users()->where('one_stop_id', $profile['sub'])->first()
            ?? $this->linkableAccount($profile);

        if (! $user) {
            return $this->failed($request, "No {$app} account uses {$profile['email']}. Ask a {$app} administrator to create one.");
        }

        if ($problem = OneStop::userProblem($user)) {
            return $this->failed($request, $problem);
        }

        $lastLogin = config('one-stop.users.last_login_column');
        $user->forceFill(['one_stop_id' => $profile['sub']]);

        if ($lastLogin && Schema::hasColumn($user->getTable(), $lastLogin)) {
            $user->forceFill([$lastLogin => now()]);
        }

        $user->save();

        // Someone else may still be signed in on this browser (for example behind a lock screen).
        if (Auth::check() && ! Auth::user()->is($user)) {
            Auth::logout();
        }

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->forget('one_stop.embedded');
        $request->session()->put(OneStop::SIGN_OUTS_AT_SIGN_IN, (int) $user->getAttribute('one_stop_sign_outs'));

        $response = OneStop::signedIn($user, $request);

        return $response instanceof SymfonyResponse
            ? $response
            : redirect()->intended(OneStop::routeUrl('home_route'));
    }

    /**
     * Receive One-Stop's notice that a person signed out there or was suspended.
     *
     * Only sessions started through One-Stop are ended (by EndSignedOutOneStopSession); password
     * sessions, API tokens and the account itself are untouched.
     */
    public function signOut(Request $request): Response
    {
        abort_unless(OneStop::enabled(), 404);

        $body = $request->getContent();
        $expected = 'sha256='.hash_hmac('sha256', $body, (string) config('one-stop.client_secret'));
        $notice = json_decode($body, true);

        if (! hash_equals($expected, (string) $request->header(OneStop::SIGNATURE_HEADER))
            || ! is_array($notice) || ! isset($notice['sub'], $notice['iat'])
            || abs(now()->timestamp - (int) $notice['iat']) > 300) {
            return response('Invalid sign-out notice.', 401);
        }

        $user = $this->users()->where('one_stop_id', (string) $notice['sub'])->first();

        if ($user) {
            $user->increment('one_stop_sign_outs');
            OneStop::signOutNoticeReceived($user, ($notice['event'] ?? 'logout') === 'suspended' ? 'suspended' : 'logout');
        }

        return response()->noContent();
    }

    /**
     * @return Builder<Model>
     */
    private function users()
    {
        $model = config('one-stop.users.model');

        return $model::query();
    }

    private function appName(): string
    {
        return (string) config('one-stop.app_name');
    }

    private function callbackUrl(): string
    {
        return route(config('one-stop.routes.name').'callback');
    }

    /**
     * @return array{sub: string, email: string, email_verified: bool, name: string}
     *
     * @throws ConnectionException|RequestException
     */
    private function fetchProfile(string $code, string $verifier): array
    {
        $base = config('one-stop.url');
        $timeout = config('one-stop.timeout');

        $token = Http::asForm()->acceptJson()->timeout($timeout)->post("{$base}/oauth/token", [
            'grant_type' => 'authorization_code',
            'client_id' => config('one-stop.client_id'),
            'client_secret' => config('one-stop.client_secret'),
            'redirect_uri' => $this->callbackUrl(),
            'code' => $code,
            'code_verifier' => $verifier,
        ])->throw()->json('access_token');

        $profile = Http::acceptJson()->withToken((string) $token)->timeout($timeout)
            ->get("{$base}/oauth/userinfo")->throw()->json();

        return [
            'sub' => (string) ($profile['sub'] ?? ''),
            'email' => (string) ($profile['email'] ?? ''),
            'email_verified' => (bool) ($profile['email_verified'] ?? false),
            'name' => (string) ($profile['name'] ?? ''),
        ];
    }

    /**
     * The first time someone uses One-Stop, link the account with the same (verified) email
     * address. Accounts already linked to a different One-Stop person are never re-linked.
     *
     * @param  array{sub: string, email: string, email_verified: bool, name: string}  $profile
     */
    private function linkableAccount(array $profile): ?Model
    {
        if ($profile['sub'] === '' || $profile['email'] === '' || ! $profile['email_verified']) {
            return null;
        }

        return $this->users()->where('email', $profile['email'])->whereNull('one_stop_id')->first();
    }

    private function oneStopIsReachable(): bool
    {
        try {
            return Http::timeout(2)->get(config('one-stop.url').'/up')->successful();
        } catch (ConnectionException) {
            return false;
        }
    }

    private function failed(Request $request, string $message): SymfonyResponse
    {
        if ($request->session()->pull('one_stop.embedded') || $request->header('Sec-Fetch-Dest') === 'iframe') {
            return response()->view('one-stop::failed', ['message' => $message, 'oneStopOrigin' => OneStop::origin()]);
        }

        if ($response = OneStop::failureResponse($request, $message)) {
            return $response;
        }

        return Auth::check()
            ? redirect()->to(OneStop::routeUrl('home_route'))->with('status', $message)
            : redirect()->to(OneStop::routeUrl('login_route'))->withErrors(['email' => $message]);
    }
}
