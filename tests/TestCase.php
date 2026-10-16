<?php

namespace Maico\OneStop\Tests;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Maico\OneStop\OneStop;
use Maico\OneStop\OneStopServiceProvider;
use Maico\OneStop\Tests\Fixtures\User;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected const ONE_STOP = 'http://one-stop.test';

    protected const SECRET = 'shared-client-secret';

    protected function getPackageProviders($app): array
    {
        return [OneStopServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.name', 'StockFlow');
        $app['config']->set('auth.providers.users.model', User::class);
        $app['config']->set('one-stop', array_merge($app['config']->get('one-stop'), [
            'enabled' => true,
            'url' => self::ONE_STOP,
            'client_id' => 'client-123',
            'client_secret' => self::SECRET,
            'app_name' => 'StockFlow',
            'users' => ['model' => User::class, 'last_login_column' => 'last_login_at'],
        ]));
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/database');
    }

    protected function defineRoutes($router): void
    {
        Route::middleware('web')->group(function () {
            Route::get('/login', fn () => 'login page')->name('login');
            Route::get('/', fn () => 'home page')->middleware('auth')->name('home');
        });
    }

    protected function addOneStopColumns(): void
    {
        Schema::table('users', function ($table) {
            $table->string('one_stop_id')->nullable()->unique();
            $table->unsignedInteger('one_stop_sign_outs')->default(0);
        });
    }

    protected function tearDown(): void
    {
        OneStop::flush();

        parent::tearDown();
    }
}
