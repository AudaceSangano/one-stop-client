<?php

namespace Maico\OneStop\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Route;
use Maico\OneStop\Requirements;

/**
 * Sets an application up for "Sign in with MAICO One-Stop".
 *
 * It first checks what it cannot fix itself (the user table, and the routes it sends people to)
 * and stops before changing anything if they are missing. Then it publishes the configuration,
 * adds and runs a migration for whichever user columns are missing, adds the settings to .env and
 * .env.example (never overwriting existing values), and prints what to register in One-Stop.
 */
class InstallCommand extends Command
{
    protected $signature = 'one-stop:install
        {--url= : One-Stop address, e.g. https://onestop.maico.co.rw}
        {--client-id= : Client ID shown by One-Stop when the application was registered}
        {--client-secret= : Client secret shown by One-Stop (shown only once)}
        {--enable : Switch One-Stop sign-in on (it stays off until the client ID and secret are set)}
        {--login-route= : Name of the route for the sign-in page (default: login)}
        {--home-route= : Name of the route people land on after signing in (default: home)}
        {--no-migrate : Create the migration for the missing columns but do not run it}
        {--force : Overwrite the published configuration file}';

    protected $description = 'Install "Sign in with MAICO One-Stop" in this application';

    /**
     * Migration names created by this process, never reused.
     *
     * @var list<string>
     */
    private static array $issued = [];

    public function handle(Filesystem $files): int
    {
        $this->components->info('Installing Sign in with MAICO One-Stop');

        // 1. Things the package cannot do for the application: stop before changing anything.
        if ($problems = Requirements::blockingProblems()) {
            foreach ($problems as $problem) {
                $this->components->error($problem);
            }

            return self::FAILURE;
        }

        $routes = $this->resolveRoutes();

        if ($routes === null) {
            $this->newLine();
            $this->components->warn('Nothing was changed. Run php artisan one-stop:install again when the routes exist.');

            return self::FAILURE;
        }

        // 2. Configuration, columns and settings.
        $this->publishConfig($files, $routes);

        if (! $this->addColumns($files)) {
            return self::FAILURE;
        }

        $this->writeEnvironment($files);
        $this->nextSteps();

        return self::SUCCESS;
    }

    /**
     * Make sure every route the package redirects to exists, letting the developer pick an
     * existing route instead, or stop so they can add it first.
     *
     * @return array<string, string>|null route names to save, keyed by config key; null to stop
     */
    private function resolveRoutes(): ?array
    {
        $chosen = [];

        foreach (['login_route' => 'login-route', 'home_route' => 'home-route'] as $key => $option) {
            if ($name = $this->option($option)) {
                if (! Route::has($name)) {
                    $this->components->error("There is no route named [{$name}] (from --{$option}).");

                    return null;
                }

                config(["one-stop.{$key}" => $name]);
                $chosen[$key] = $name;
            }
        }

        foreach (Requirements::missingRoutes() as $key => $name) {
            $purpose = Requirements::ROUTES[$key];
            $option = str_replace('_', '-', $key);
            $candidates = Requirements::routeCandidates($key) ?: Requirements::allRouteNames();

            $this->components->warn("This application has no route named [{$name}] for {$purpose}.");

            if (! $this->input->isInteractive()) {
                $this->line("  Add one, for example: <options=bold>Route::get('/{$this->exampleUri($key)}', ...)->name('{$name}');</>");
                $this->line("  or run again with <options=bold>--{$option}=NAME</> to use an existing route".($candidates ? ' (for example: '.implode(', ', array_slice($candidates, 0, 5)).')' : '').'.');

                return null;
            }

            $stop = "Stop: I will add a route named [{$name}] first";
            $answer = $this->choice("Which route should One-Stop use for {$purpose}?", [...$candidates, $stop], count($candidates));

            if ($answer === $stop) {
                $this->line("  Add it in routes/web.php, for example: <options=bold>Route::get('/{$this->exampleUri($key)}', ...)->name('{$name}');</>");

                return null;
            }

            config(["one-stop.{$key}" => $answer]);
            $chosen[$key] = $answer;
        }

        foreach (Requirements::ROUTES as $key => $purpose) {
            $this->components->twoColumnDetail("Route for {$purpose}", '<fg=green;options=bold>'.config("one-stop.{$key}").'</>');
        }

        return $chosen;
    }

    /**
     * @param  array<string, string>  $routes
     */
    private function publishConfig(Filesystem $files, array $routes): void
    {
        $path = config_path('one-stop.php');

        if (! $files->exists($path) || $this->option('force')) {
            $this->callSilently('vendor:publish', ['--tag' => 'one-stop-config', '--force' => true]);
            $this->components->twoColumnDetail('config/one-stop.php', '<fg=green;options=bold>PUBLISHED</>');
        } else {
            $this->components->twoColumnDetail('config/one-stop.php', '<fg=yellow;options=bold>KEPT</>');
        }

        if ($routes === []) {
            return;
        }

        $contents = $files->get($path);

        foreach ($routes as $key => $name) {
            $contents = preg_replace("/('{$key}'\s*=>\s*)'[^']*'/", "\${1}'".addslashes($name)."'", $contents);
        }

        $files->put($path, $contents);
        $this->components->twoColumnDetail('config/one-stop.php routes', '<fg=green;options=bold>'.implode(', ', $routes).'</>');
    }

    /**
     * Create (and run) a migration for whichever user columns are missing.
     */
    private function addColumns(Filesystem $files): bool
    {
        $table = Requirements::userTable();
        $missing = Requirements::missingColumns();

        if ($missing === []) {
            $this->components->twoColumnDetail("{$table} columns (".implode(', ', array_keys(Requirements::columns())).')', '<fg=yellow;options=bold>ALREADY PRESENT</>');

            return true;
        }

        $ran = $this->laravel['migrator']->repositoryExists() ? $this->laravel['migrator']->getRepository()->getRan() : [];
        // Reuse a migration that was created but not run yet; otherwise create one with an unused name.
        $pending = collect(glob(database_path("migrations/*_add_one_stop_columns_to_{$table}_table.php")) ?: [])
            ->first(fn (string $file) => ! in_array(basename($file, '.php'), $ran, true));
        $path = $pending ?? $this->newMigrationPath($table, $ran);

        if ($pending === null) {
            $files->put($path, $this->migration($table, $missing));
            $this->components->twoColumnDetail(str_replace(base_path().'/', '', $path), '<fg=green;options=bold>CREATED</>');
        }

        if ($this->option('no-migrate')) {
            $this->components->twoColumnDetail('Columns to add: '.implode(', ', $missing), '<fg=yellow;options=bold>RUN php artisan migrate</>');

            return true;
        }

        // Run only this migration, never the application's other pending migrations.
        $this->call('migrate', ['--path' => str_replace(base_path().'/', '', $path), '--force' => true]);

        if ($still = Requirements::missingColumns()) {
            $this->components->error('These columns are still missing: '.implode(', ', $still).'. Check the migration and run it again.');

            return false;
        }

        $this->components->twoColumnDetail("{$table} columns added: ".implode(', ', $missing), '<fg=green;options=bold>DONE</>');

        return true;
    }

    /**
     * A migration file name that is neither on disk nor recorded as already run.
     *
     * @param  list<string>  $ran
     */
    private function newMigrationPath(string $table, array $ran): string
    {
        $time = time();

        do {
            $name = date('Y_m_d_His', $time++)."_add_one_stop_columns_to_{$table}_table";
        } while (in_array($name, $ran, true) || in_array($name, self::$issued, true) || file_exists(database_path("migrations/{$name}.php")));

        // Laravel's migrator caches migration files by path for the life of the process.
        self::$issued[] = $name;

        return database_path("migrations/{$name}.php");
    }

    /**
     * @param  list<string>  $columns
     */
    private function migration(string $table, array $columns): string
    {
        $definitions = [
            'one_stop_id' => "\$table->string('one_stop_id')->nullable()->unique();",
            'one_stop_sign_outs' => "\$table->unsignedInteger('one_stop_sign_outs')->default(0);",
        ];

        $up = collect($columns)->map(fn (string $column) => implode(PHP_EOL, [
            "        if (! Schema::hasColumn('{$table}', '{$column}')) {",
            "            Schema::table('{$table}', function (Blueprint \$table) {",
            '                '.($definitions[$column] ?? "\$table->timestamp('{$column}')->nullable();"),
            '            });',
            '        }',
        ]))->implode(PHP_EOL.PHP_EOL);

        $down = collect($columns)->map(fn (string $column) => implode(PHP_EOL, [
            "        if (Schema::hasColumn('{$table}', '{$column}')) {",
            "            Schema::table('{$table}', function (Blueprint \$table) {",
            ...($column === 'one_stop_id' ? ["                \$table->dropUnique(['one_stop_id']);"] : []),
            "                \$table->dropColumn('{$column}');",
            '            });',
            '        }',
        ]))->implode(PHP_EOL.PHP_EOL);

        $stub = file_get_contents(__DIR__.'/../../database/migrations/add_one_stop_columns_to_users_table.php.stub');

        return str_replace(['{{ up }}', '{{ down }}'], [$up, $down], $stub);
    }

    private function writeEnvironment(Filesystem $files): void
    {
        $values = [
            'ONE_STOP_ENABLED' => $this->option('enable') ? 'true' : 'false',
            'ONE_STOP_URL' => $this->option('url') ?: 'http://127.0.0.1:8000',
            'ONE_STOP_CLIENT_ID' => $this->option('client-id') ?: '',
            'ONE_STOP_CLIENT_SECRET' => $this->option('client-secret') ?: '',
        ];

        foreach (['.env' => $values, '.env.example' => array_merge($values, ['ONE_STOP_ENABLED' => 'false', 'ONE_STOP_CLIENT_ID' => '', 'ONE_STOP_CLIENT_SECRET' => ''])] as $file => $settings) {
            $path = base_path($file);

            if (! $files->exists($path)) {
                continue;
            }

            $contents = $files->get($path);
            $missing = array_filter($settings, fn ($key) => ! preg_match('/^'.preg_quote($key, '/').'=/m', $contents), ARRAY_FILTER_USE_KEY);

            if ($missing === []) {
                $this->components->twoColumnDetail($file, '<fg=yellow;options=bold>ALREADY CONFIGURED</>');

                continue;
            }

            $lines = collect($missing)->map(fn ($value, $key) => "{$key}={$value}")->implode(PHP_EOL);
            $files->append($path, rtrim(PHP_EOL.PHP_EOL.'# Optional sign-in with MAICO One-Stop (the application works without it).'.PHP_EOL.$lines).PHP_EOL);
            $this->components->twoColumnDetail($file, '<fg=green;options=bold>'.count($missing).' SETTINGS ADDED</>');
        }
    }

    private function nextSteps(): void
    {
        $base = rtrim((string) config('app.url'), '/').'/'.trim((string) config('one-stop.routes.prefix'), '/');

        $this->newLine();
        $this->components->info('Next steps');
        $this->line('  <options=bold>1.</> In One-Stop, register (or edit) this application with the <options=bold>Single sign-on</> integration:');
        $this->line("       Sign-in start address    {$base}/redirect");
        $this->line("       Callback address         {$base}/callback");
        $this->line("       Sign-out notice address  {$base}/sign-out");
        $this->line('  <options=bold>2.</> Put the client ID and secret it shows into .env and set ONE_STOP_ENABLED=true.');
        $this->line('  <options=bold>3.</> Add the button to your sign-in page: <options=bold><x-one-stop::button class="..." /></>');
        $this->line('  <options=bold>4.</> Optional: fit it to your app in a service provider (see README), e.g.');
        $this->line('       OneStop::checkUserUsing(fn ($user) => $user->is_active ? null : \'Your account is inactive.\');');
        $this->line('  <options=bold>5.</> Check everything at any time with <options=bold>php artisan one-stop:check</>');
        $this->newLine();
        $this->line('  The addresses above use APP_URL ('.config('app.url').'). Use the address people really open the app on.');
    }

    private function exampleUri(string $key): string
    {
        return $key === 'login_route' ? 'login' : 'home';
    }
}
