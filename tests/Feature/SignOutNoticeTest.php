<?php

namespace Maico\OneStop\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Maico\OneStop\OneStop;
use Maico\OneStop\Tests\Fixtures\User;
use Maico\OneStop\Tests\TestCase;

class SignOutNoticeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->addOneStopColumns();
    }

    private function notice(array $notice, string $secret = self::SECRET): TestResponse
    {
        $body = json_encode($notice);

        return $this->call('POST', '/auth/one-stop/sign-out', [], [], [], [
            'HTTP_'.str_replace('-', '_', strtoupper(OneStop::SIGNATURE_HEADER)) => 'sha256='.hash_hmac('sha256', $body, $secret),
            'CONTENT_TYPE' => 'application/json',
        ], $body);
    }

    public function test_a_signed_notice_ends_sessions_started_with_one_stop(): void
    {
        Http::fake([
            self::ONE_STOP.'/up' => Http::response('OK'),
            self::ONE_STOP.'/oauth/token' => Http::response(['access_token' => 't']),
            self::ONE_STOP.'/oauth/userinfo' => Http::response(['sub' => '42', 'email' => 'alex@stockflow.test', 'email_verified' => true]),
        ]);
        $user = User::create(['name' => 'Alex', 'email' => 'alex@stockflow.test', 'password' => 'x']);
        $events = [];
        OneStop::afterSignOutNotice(function (User $user, string $event) use (&$events) {
            $events[] = $event;
        });
        parse_str(parse_url($this->get('/auth/one-stop/redirect')->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->get("/auth/one-stop/callback?code=abc&state={$query['state']}");
        $this->get('/')->assertOk();

        $this->notice(['sub' => '42', 'event' => 'suspended', 'iat' => now()->timestamp])->assertNoContent();
        $this->app['auth']->forgetGuards();

        $this->get('/')->assertRedirect('/login');
        $this->assertGuest();
        $this->assertSame(1, $user->fresh()->one_stop_sign_outs);
        $this->assertSame(['suspended'], $events);
    }

    public function test_password_sessions_are_not_affected(): void
    {
        $user = User::create(['name' => 'Alex', 'email' => 'alex@stockflow.test', 'password' => 'x', 'one_stop_id' => '42']);
        $this->actingAs($user);

        $this->notice(['sub' => '42', 'event' => 'logout', 'iat' => now()->timestamp])->assertNoContent();
        $this->app['auth']->forgetGuards();

        $this->actingAs($user->fresh())->get('/')->assertOk();
    }

    public function test_unsigned_wrongly_signed_or_stale_notices_are_rejected(): void
    {
        User::create(['name' => 'Alex', 'email' => 'alex@stockflow.test', 'password' => 'x', 'one_stop_id' => '42']);

        $this->notice(['sub' => '42', 'iat' => now()->timestamp], 'wrong-secret')->assertUnauthorized();
        $this->notice(['sub' => '42', 'iat' => now()->subMinutes(10)->timestamp])->assertUnauthorized();
        $this->postJson('/auth/one-stop/sign-out', ['sub' => '42', 'iat' => now()->timestamp])->assertUnauthorized();

        $this->assertSame(0, User::first()->one_stop_sign_outs);
    }
}
