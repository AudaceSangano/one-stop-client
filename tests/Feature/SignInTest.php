<?php

namespace Maico\OneStop\Tests\Feature;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Maico\OneStop\OneStop;
use Maico\OneStop\Tests\Fixtures\User;
use Maico\OneStop\Tests\TestCase;

class SignInTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->addOneStopColumns();
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    private function fakeOneStop(array $profile = []): void
    {
        Http::fake([
            self::ONE_STOP.'/up' => Http::response('OK'),
            self::ONE_STOP.'/oauth/token' => Http::response(['access_token' => 'token-abc', 'token_type' => 'Bearer']),
            self::ONE_STOP.'/oauth/userinfo' => Http::response([
                'sub' => '42', 'name' => 'Alex Morgan', 'email' => 'alex@stockflow.test', 'email_verified' => true, ...$profile,
            ]),
        ]);
    }

    private function user(array $attributes = []): User
    {
        return User::create(['name' => 'Alex Morgan', 'email' => 'alex@stockflow.test', 'password' => 'secret-password', ...$attributes]);
    }

    private function startSignIn(string $query = ''): string
    {
        $location = $this->get('/auth/one-stop/redirect'.$query)->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith(self::ONE_STOP.'/oauth/authorize?', $location);
        parse_str(parse_url($location, PHP_URL_QUERY), $params);

        $this->assertSame('client-123', $params['client_id']);
        $this->assertSame(route('one-stop.callback'), $params['redirect_uri']);
        $this->assertSame('S256', $params['code_challenge_method']);

        return $params['state'];
    }

    public function test_routes_return_404_while_disabled(): void
    {
        config(['one-stop.enabled' => false]);

        $this->get('/auth/one-stop/redirect')->assertNotFound();
        $this->get('/auth/one-stop/callback')->assertNotFound();
        $this->post('/auth/one-stop/sign-out')->assertNotFound();
        $this->get('/login')->assertHeaderMissing('Content-Security-Policy');
    }

    public function test_routes_return_404_until_the_client_is_configured(): void
    {
        config(['one-stop.client_secret' => null]);

        $this->assertFalse(OneStop::enabled());
        $this->get('/auth/one-stop/redirect')->assertNotFound();
    }

    public function test_only_one_stop_may_embed_the_application_while_enabled(): void
    {
        $this->get('/login')->assertHeader('Content-Security-Policy', "frame-ancestors 'self' ".self::ONE_STOP);

        config(['one-stop.allow_embedding' => false]);
        $this->get('/login')->assertHeaderMissing('Content-Security-Policy');
    }

    public function test_the_button_lets_the_person_choose_an_account(): void
    {
        $html = $this->blade('<x-one-stop::button class="btn" />');
        $html->assertSee('href="'.route('one-stop.redirect', ['prompt' => 'select_account']).'"', false)->assertSee('class="btn"', false)->assertSee('Sign in with MAICO One-Stop');

        config(['one-stop.enabled' => false]);
        $this->assertSame('', trim((string) $this->blade('<x-one-stop::button />')));
    }

    public function test_the_prompt_is_passed_on_only_for_account_choice(): void
    {
        $this->fakeOneStop();

        parse_str(parse_url($this->get('/auth/one-stop/redirect?prompt=select_account')->headers->get('Location'), PHP_URL_QUERY), $chosen);
        parse_str(parse_url($this->get('/auth/one-stop/redirect?prompt=none')->headers->get('Location'), PHP_URL_QUERY), $ignored);

        $this->assertSame('select_account', $chosen['prompt']);
        $this->assertArrayNotHasKey('prompt', $ignored);
    }

    public function test_first_sign_in_links_the_account_with_the_same_verified_email(): void
    {
        $this->fakeOneStop();
        $user = $this->user();
        $state = $this->startSignIn();

        $this->get("/auth/one-stop/callback?code=abc&state={$state}")->assertRedirect(route('home'));

        $this->assertAuthenticatedAs($user);
        $this->assertSame('42', $user->fresh()->one_stop_id);
        $this->assertNotNull($user->fresh()->last_login_at);
        Http::assertSent(fn ($request) => $request->url() === self::ONE_STOP.'/oauth/token'
            && $request['client_secret'] === self::SECRET
            && strlen($request['code_verifier']) === 64);
    }

    public function test_later_sign_ins_match_by_one_stop_id_even_if_the_email_changed(): void
    {
        $this->fakeOneStop(['email' => 'alex.morgan@maico.co.rw']);
        $user = $this->user(['one_stop_id' => '42']);

        $this->get('/auth/one-stop/callback?code=abc&state='.$this->startSignIn());

        $this->assertAuthenticatedAs($user);
    }

    public function test_no_account_is_created_for_unknown_people(): void
    {
        $this->fakeOneStop(['email' => 'stranger@maico.co.rw']);

        $this->get('/auth/one-stop/callback?code=abc&state='.$this->startSignIn())
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['email' => 'No StockFlow account uses stranger@maico.co.rw. Ask a StockFlow administrator to create one.']);

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'stranger@maico.co.rw']);
    }

    public function test_an_unverified_email_is_not_linked(): void
    {
        $this->fakeOneStop(['email_verified' => false]);
        $this->user();

        $this->get('/auth/one-stop/callback?code=abc&state='.$this->startSignIn());

        $this->assertGuest();
    }

    public function test_an_account_linked_to_another_one_stop_person_is_not_taken_over(): void
    {
        $this->fakeOneStop(['sub' => '99']);
        $this->user(['one_stop_id' => '42']);

        $this->get('/auth/one-stop/callback?code=abc&state='.$this->startSignIn());

        $this->assertGuest();
    }

    public function test_by_default_an_inactive_flag_is_honoured(): void
    {
        $this->fakeOneStop();
        $this->user(['is_active' => false]);

        $this->get('/auth/one-stop/callback?code=abc&state='.$this->startSignIn())
            ->assertSessionHasErrors(['email' => 'Your StockFlow account has been deactivated.']);
        $this->assertGuest();
    }

    public function test_the_application_decides_who_may_sign_in(): void
    {
        $this->fakeOneStop();
        $this->user();
        OneStop::checkUserUsing(fn (User $user) => 'Your account is on hold.');

        $this->get('/auth/one-stop/callback?code=abc&state='.$this->startSignIn())
            ->assertSessionHasErrors(['email' => 'Your account is on hold.']);
        $this->assertGuest();
    }

    public function test_after_sign_in_hook_runs_and_may_choose_the_redirect(): void
    {
        $this->fakeOneStop();
        $this->user();
        $seen = null;
        OneStop::afterSignIn(function (User $user, Request $request) use (&$seen) {
            $seen = $user->email;

            return redirect('/welcome');
        });

        $this->get('/auth/one-stop/callback?code=abc&state='.$this->startSignIn())->assertRedirect('/welcome');
        $this->assertSame('alex@stockflow.test', $seen);
    }

    public function test_failure_response_can_be_chosen_by_the_application(): void
    {
        $this->fakeOneStop(['email' => 'stranger@maico.co.rw']);
        OneStop::respondToFailureUsing(fn (Request $request, string $message) => redirect('/somewhere')->with('problem', $message));

        $this->get('/auth/one-stop/callback?code=abc&state='.$this->startSignIn())
            ->assertRedirect('/somewhere')
            ->assertSessionHas('problem');
    }

    public function test_inside_one_stop_a_failure_is_handed_to_one_stop(): void
    {
        $this->fakeOneStop(['email' => 'stranger@maico.co.rw']);

        $this->get('/auth/one-stop/callback?code=abc&state='.$this->startSignIn('?embedded=1'))
            ->assertOk()
            ->assertSee('window.parent.postMessage', false)
            ->assertSee('"http:\/\/one-stop.test"', false);
        $this->assertGuest();

        $this->withHeader('Sec-Fetch-Dest', 'iframe')
            ->get('/auth/one-stop/callback?code=abc&state='.$this->startSignIn())
            ->assertOk()
            ->assertSee('window.parent.postMessage', false);
    }

    public function test_forged_state_errors_and_unreachable_one_stop_fall_back_to_password_sign_in(): void
    {
        $this->fakeOneStop();
        $this->user();
        $this->startSignIn();

        $this->get('/auth/one-stop/callback?code=abc&state=forged')
            ->assertSessionHasErrors(['email' => 'That sign-in link has expired. Please try again.']);
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/oauth/token'));

        $this->get('/auth/one-stop/callback?error=access_denied&state='.$this->startSignIn())->assertSessionHasErrors('email');

        Http::fake(fn () => throw new ConnectionException('Connection refused'));
        $this->get('/auth/one-stop/redirect')
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['email' => 'One-Stop cannot be reached right now. Sign in with your StockFlow password instead.']);
    }
}
