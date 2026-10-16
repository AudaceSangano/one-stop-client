<?php

namespace Maico\OneStop;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * What the application must provide for "Sign in with MAICO One-Stop" to work:
 * named routes to send people to, and the user table with the columns the package uses.
 */
class Requirements
{
    /**
     * The routes the package redirects to, keyed by their config key.
     *
     * @var array<string, string>
     */
    public const ROUTES = [
        'login_route' => 'the sign-in page (shown again when One-Stop sign-in fails)',
        'home_route' => 'the page people land on after signing in',
    ];

    /**
     * Words that suggest a route could serve each purpose, used to offer alternatives.
     *
     * @var array<string, list<string>>
     */
    private const ROUTE_HINTS = [
        'login_route' => ['login', 'signin', 'sign-in', 'auth'],
        'home_route' => ['home', 'dashboard', 'index', 'welcome'],
    ];

    /**
     * Config keys whose named route does not exist.
     *
     * @return array<string, string> config key => configured route name
     */
    public static function missingRoutes(): array
    {
        return collect(self::ROUTES)
            ->keys()
            ->mapWithKeys(fn (string $key) => [$key => (string) config("one-stop.{$key}")])
            ->reject(fn (string $name) => $name !== '' && Route::has($name))
            ->all();
    }

    /**
     * Named GET routes that could stand in for a missing one, best matches first.
     *
     * @return list<string>
     */
    public static function routeCandidates(string $key): array
    {
        $hints = self::ROUTE_HINTS[$key] ?? [];

        return self::namedGetRoutes()
            ->filter(fn (RouteDefinition $route) => collect($hints)->contains(fn ($hint) => str_contains(strtolower($route->getName().' '.$route->uri()), $hint)))
            ->map(fn (RouteDefinition $route) => $route->getName())
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Every named GET route outside the package, for choosing a route by hand.
     *
     * @return list<string>
     */
    public static function allRouteNames(): array
    {
        return self::namedGetRoutes()->map(fn (RouteDefinition $route) => $route->getName())->unique()->sort()->values()->all();
    }

    /**
     * The user model's table, or null when the model class does not exist.
     */
    public static function userTable(): ?string
    {
        $model = config('one-stop.users.model');

        if (! is_string($model) || ! class_exists($model) || ! is_subclass_of($model, Model::class)) {
            return null;
        }

        return (new $model)->getTable();
    }

    /**
     * Problems that stop the package from working and that it cannot fix itself.
     *
     * @return list<string>
     */
    public static function blockingProblems(): array
    {
        $table = self::userTable();

        if ($table === null) {
            return ['The user model ['.config('one-stop.users.model').'] does not exist. Set one-stop.users.model in config/one-stop.php.'];
        }

        try {
            if (! Schema::hasTable($table)) {
                return ["The [{$table}] table does not exist yet. Run your own migrations first."];
            }

            if (! Schema::hasColumn($table, 'email')) {
                return ["The [{$table}] table has no [email] column. One-Stop links accounts by email address."];
            }
        } catch (Throwable $exception) {
            return ['The database cannot be reached: '.$exception->getMessage()];
        }

        return [];
    }

    /**
     * Columns the package needs on the user table, mapped to whether they exist.
     *
     * @return array<string, bool>
     */
    public static function columns(): array
    {
        $table = self::userTable();
        $needed = array_filter(['one_stop_id', 'one_stop_sign_outs', config('one-stop.users.last_login_column')]);

        return collect($needed)->mapWithKeys(fn (string $column) => [$column => $table !== null && Schema::hasColumn($table, $column)])->all();
    }

    /**
     * @return list<string>
     */
    public static function missingColumns(): array
    {
        return array_keys(array_filter(self::columns(), fn (bool $exists) => ! $exists));
    }

    /**
     * @return Collection<int, RouteDefinition>
     */
    private static function namedGetRoutes(): Collection
    {
        $prefix = config('one-stop.routes.name');

        return collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RouteDefinition $route) => in_array('GET', $route->methods(), true)
                && $route->getName() !== null
                && ! str_starts_with($route->getName(), $prefix)
                && ! str_contains($route->uri(), '{'));
    }
}
