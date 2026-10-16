<?php

namespace Maico\OneStop\Console;

use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Maico\OneStop\OneStop;
use Maico\OneStop\Requirements;

/**
 * Read-only check of everything "Sign in with MAICO One-Stop" needs. Changes nothing.
 */
class CheckCommand extends Command
{
    protected $signature = 'one-stop:check';

    protected $description = 'Check that "Sign in with MAICO One-Stop" is set up correctly (changes nothing)';

    public function handle(): int
    {
        $problems = 0;
        $row = function (string $label, bool $ok, string $detail = '') use (&$problems): void {
            $problems += $ok ? 0 : 1;
            $this->components->twoColumnDetail($label, $ok ? "<fg=green;options=bold>OK</> {$detail}" : "<fg=red;options=bold>MISSING</> {$detail}");
        };

        $this->components->info('Sign in with MAICO One-Stop');

        foreach (Requirements::blockingProblems() as $problem) {
            $row('User table', false, $problem);
        }

        foreach (Requirements::ROUTES as $key => $purpose) {
            $name = (string) config("one-stop.{$key}");
            $row("Route for {$purpose}", ! array_key_exists($key, Requirements::missingRoutes()), "[{$name}]");
        }

        foreach (Requirements::columns() as $column => $exists) {
            $row('Column '.Requirements::userTable().".{$column}", $exists);
        }

        foreach (['url' => 'ONE_STOP_URL', 'client_id' => 'ONE_STOP_CLIENT_ID', 'client_secret' => 'ONE_STOP_CLIENT_SECRET'] as $key => $env) {
            $row("Setting {$env}", (bool) config("one-stop.{$key}"));
        }

        $this->components->twoColumnDetail('ONE_STOP_ENABLED', OneStop::enabled() ? '<fg=green;options=bold>ON</>' : '<fg=yellow;options=bold>OFF</> (the app works without One-Stop)');

        if (config('one-stop.url')) {
            try {
                $reachable = Http::timeout(3)->get(config('one-stop.url').'/up')->successful();
            } catch (ConnectionException) {
                $reachable = false;
            }

            $this->components->twoColumnDetail('One-Stop reachable at '.config('one-stop.url'), $reachable ? '<fg=green;options=bold>YES</>' : '<fg=yellow;options=bold>NO</> (sign-in falls back to passwords)');
        }

        $this->newLine();
        $problems === 0
            ? $this->components->info('Everything One-Stop needs is in place.')
            : $this->components->error("{$problems} problem(s) found. Run php artisan one-stop:install to fix what it can.");

        return $problems === 0 ? self::SUCCESS : self::FAILURE;
    }
}
