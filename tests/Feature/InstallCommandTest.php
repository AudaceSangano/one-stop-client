<?php

namespace Maico\OneStop\Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Maico\OneStop\Tests\TestCase;

class InstallCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        File::put(base_path('.env'), "APP_NAME=Example\n");
        File::put(base_path('.env.example'), "APP_NAME=Example\n");
        $this->cleanUp();
    }

    protected function tearDown(): void
    {
        File::delete([base_path('.env'), base_path('.env.example')]);
        $this->cleanUp();

        parent::tearDown();
    }

    private function cleanUp(): void
    {
        File::delete(config_path('one-stop.php'));
        File::delete(File::glob(database_path('migrations/*_add_one_stop_columns_to_*_table.php')));
    }

    public function test_installs_config_settings_and_adds_the_columns(): void
    {
        $this->artisan('one-stop:install', ['--url' => 'https://onestop.maico.test', '--client-id' => 'abc', '--client-secret' => 's3cret', '--enable' => true])
            ->expectsOutputToContain('/auth/one-stop/callback')
            ->assertSuccessful();

        $this->assertFileExists(config_path('one-stop.php'));
        $this->assertTrue(Schema::hasColumns('users', ['one_stop_id', 'one_stop_sign_outs']));

        $env = File::get(base_path('.env'));
        $this->assertStringContainsString("ONE_STOP_ENABLED=true\nONE_STOP_URL=https://onestop.maico.test\nONE_STOP_CLIENT_ID=abc\nONE_STOP_CLIENT_SECRET=s3cret", $env);
        $example = File::get(base_path('.env.example'));
        $this->assertStringContainsString('ONE_STOP_ENABLED=false', $example);
        $this->assertStringNotContainsString('s3cret', $example);
    }

    public function test_adds_only_the_columns_that_are_missing_including_the_last_login_column(): void
    {
        config(['one-stop.users.last_login_column' => 'last_seen_at']);

        $this->artisan('one-stop:install')->assertSuccessful();

        $migration = File::get(File::glob(database_path('migrations/*_add_one_stop_columns_to_users_table.php'))[0]);
        $this->assertStringContainsString("timestamp('last_seen_at')", $migration);
        $this->assertStringNotContainsString("'last_login_at'", $migration);
        $this->assertTrue(Schema::hasColumns('users', ['one_stop_id', 'one_stop_sign_outs', 'last_seen_at']));
    }

    public function test_with_no_migrate_the_migration_is_created_but_not_run(): void
    {
        $this->artisan('one-stop:install', ['--no-migrate' => true])->assertSuccessful();

        $this->assertCount(1, File::glob(database_path('migrations/*_add_one_stop_columns_to_users_table.php')));
        $this->assertFalse(Schema::hasColumn('users', 'one_stop_id'));
    }

    public function test_skips_the_migration_when_every_column_exists(): void
    {
        $this->addOneStopColumns();

        $this->artisan('one-stop:install')->expectsOutputToContain('ALREADY PRESENT')->assertSuccessful();

        $this->assertCount(0, File::glob(database_path('migrations/*_add_one_stop_columns_to_*_table.php')));
    }

    public function test_running_again_changes_nothing_and_never_overwrites_settings(): void
    {
        File::append(base_path('.env'), "ONE_STOP_CLIENT_SECRET=keep-me\n");
        $this->artisan('one-stop:install', ['--client-secret' => 'other'])->assertSuccessful();
        $this->artisan('one-stop:install')->expectsOutputToContain('ALREADY CONFIGURED')->assertSuccessful();

        $env = File::get(base_path('.env'));
        $this->assertSame(1, substr_count($env, 'ONE_STOP_CLIENT_SECRET='));
        $this->assertStringContainsString('ONE_STOP_CLIENT_SECRET=keep-me', $env);
        $this->assertCount(1, File::glob(database_path('migrations/*_add_one_stop_columns_to_users_table.php')));
    }

    public function test_stops_without_changes_when_the_user_table_has_no_email(): void
    {
        Schema::table('users', fn ($table) => $table->dropUnique(['email']));
        Schema::table('users', fn ($table) => $table->dropColumn('email'));

        $this->artisan('one-stop:install')->expectsOutputToContain('has no [email] column')->assertFailed();

        $this->assertFileDoesNotExist(config_path('one-stop.php'));
    }

    public function test_check_reports_whats_missing_and_what_is_in_place(): void
    {
        $this->artisan('one-stop:check')->expectsOutputToContain('MISSING')->assertFailed();

        $this->addOneStopColumns();
        $this->artisan('one-stop:check')->expectsOutputToContain('Everything One-Stop needs is in place.')->assertSuccessful();
    }
}
