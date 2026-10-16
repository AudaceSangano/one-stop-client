<?php

namespace Maico\OneStop\Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Maico\OneStop\Tests\TestCase;

/**
 * An application whose sign-in route is "auth-signin" and whose landing page is "dashboard",
 * so it has neither of the default route names ("login" and "home").
 */
class InstallWithoutRoutesTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        Route::middleware('web')->group(function () {
            Route::get('/auth-signin', fn () => 'login page')->name('auth-signin');
            Route::get('/dashboard', fn () => 'dashboard')->middleware('auth')->name('dashboard');
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        File::put(base_path('.env'), "APP_NAME=Example\n");
        $this->cleanUp();
    }

    protected function tearDown(): void
    {
        File::delete(base_path('.env'));
        $this->cleanUp();

        parent::tearDown();
    }

    private function cleanUp(): void
    {
        File::delete(config_path('one-stop.php'));
        File::delete(File::glob(database_path('migrations/*_add_one_stop_columns_to_*_table.php')));
    }

    public function test_non_interactive_install_stops_before_changing_anything(): void
    {
        $this->artisan('one-stop:install', ['--no-interaction' => true])
            ->expectsOutputToContain('no route named [login]')
            ->expectsOutputToContain('--login-route=NAME')
            ->expectsOutputToContain('Nothing was changed')
            ->assertFailed();

        $this->assertFileDoesNotExist(config_path('one-stop.php'));
        $this->assertFalse(Schema::hasColumn('users', 'one_stop_id'));
        $this->assertStringNotContainsString('ONE_STOP_', File::get(base_path('.env')));
    }

    public function test_the_developer_can_choose_to_stop_and_add_the_route_first(): void
    {
        $this->artisan('one-stop:install')
            ->expectsChoice(
                'Which route should One-Stop use for the sign-in page (shown again when One-Stop sign-in fails)?',
                'Stop: I will add a route named [login] first',
                ['auth-signin', 'Stop: I will add a route named [login] first'],
            )
            ->expectsOutputToContain("->name('login')")
            ->assertFailed();

        $this->assertFileDoesNotExist(config_path('one-stop.php'));
    }

    public function test_the_developer_can_pick_existing_routes_which_are_saved_in_the_config(): void
    {
        $this->artisan('one-stop:install')
            ->expectsChoice('Which route should One-Stop use for the sign-in page (shown again when One-Stop sign-in fails)?', 'auth-signin', ['auth-signin', 'Stop: I will add a route named [login] first'])
            ->expectsChoice('Which route should One-Stop use for the page people land on after signing in?', 'dashboard', ['dashboard', 'Stop: I will add a route named [home] first'])
            ->assertSuccessful();

        $config = File::get(config_path('one-stop.php'));
        $this->assertStringContainsString("'login_route' => 'auth-signin'", $config);
        $this->assertStringContainsString("'home_route' => 'dashboard'", $config);
        $this->assertTrue(Schema::hasColumn('users', 'one_stop_id'));
    }

    public function test_route_names_can_be_given_as_options(): void
    {
        $this->artisan('one-stop:install', ['--login-route' => 'auth-signin', '--home-route' => 'dashboard', '--no-interaction' => true])->assertSuccessful();

        $this->assertStringContainsString("'login_route' => 'auth-signin'", File::get(config_path('one-stop.php')));
    }

    public function test_an_unknown_route_option_is_refused(): void
    {
        $this->artisan('one-stop:install', ['--login-route' => 'nope', '--no-interaction' => true])
            ->expectsOutputToContain('There is no route named [nope]')
            ->assertFailed();
    }

    public function test_at_runtime_a_missing_route_never_breaks_sign_in(): void
    {
        $this->addOneStopColumns();
        Http::fake([
            self::ONE_STOP.'/up' => Http::response('OK'),
            self::ONE_STOP.'/oauth/token' => Http::response(['access_token' => 't']),
            self::ONE_STOP.'/oauth/userinfo' => Http::response(['sub' => '6', 'email' => 'sangano@maicoltd.com', 'email_verified' => true]),
        ]);
        parse_str(parse_url($this->get('/auth/one-stop/redirect?embedded=1')->headers->get('Location'), PHP_URL_QUERY), $query);

        // Inside One-Stop: the message still reaches One-Stop; the fallback link points at the site root.
        $this->get("/auth/one-stop/callback?code=abc&state={$query['state']}")
            ->assertOk()
            ->assertSee('No StockFlow account uses sangano@maicoltd.com.')
            ->assertSee('window.parent.postMessage', false)
            ->assertSee('href="'.url('/').'"', false);

        // Outside One-Stop: back to the site root instead of a missing "login" route.
        parse_str(parse_url($this->get('/auth/one-stop/redirect')->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->get("/auth/one-stop/callback?code=abc&state={$query['state']}")->assertRedirect(url('/'));
    }
}
