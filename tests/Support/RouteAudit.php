<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\Role;
use App\Models\Attachment;
use App\Models\Category;
use App\Models\Holiday;
use App\Models\Ticket;
use App\Models\User;
use Closure;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Illuminate\Support\Str;
use LogicException;
use ReflectionFunction;
use Tests\Support\Imports\ImportCsvFixture;

final class RouteAudit
{
    public const PUBLIC = [
        'GET /',
        'GET login',
        'POST login',
        'GET register',
        'POST register',
        'GET forgot-password',
        'POST forgot-password',
        'GET reset-password/{token}',
        'POST reset-password',
        'POST api/v1/auth/tokens',
    ];

    public const AUTHENTICATED_ONLY = [
        'POST logout',
        'GET api/v1/me',
        'DELETE api/v1/auth/tokens/current',
    ];

    /** @return array<string, Closure(): string> */
    public static function bindings(): array
    {
        return [
            'attachment' => static fn (): string => (string) Attachment::factory()->create()->id,
            'ticket' => static fn (): string => Ticket::factory()->create()->number,
            'user' => static fn (): string => (string) User::factory()->customer()->create()->id,
            'notification' => static fn (): string => (string) Str::uuid(),
            'import' => static fn (): string => (string) ImportCsvFixture::pendingImport(User::factory()->admin()->create())->id,
            'category' => static fn (): string => (string) Category::factory()->create()->id,
            'holiday' => static fn (): string => (string) Holiday::factory()->create()->id,
            'role' => static fn (): string => Role::Agent->value,
        ];
    }

    /** @return list<Route> */
    public static function applicationRoutes(): array
    {
        return array_values(array_filter(
            Router::getRoutes()->getRoutes(),
            static fn (Route $route): bool => self::isApplicationRoute($route),
        ));
    }

    public static function key(Route $route): string
    {
        $methods = array_values(array_diff($route->methods(), ['HEAD']));

        return $methods[0].' '.$route->uri();
    }

    public static function method(Route $route): string
    {
        return explode(' ', self::key($route))[0];
    }

    public static function isApi(Route $route): bool
    {
        return str_starts_with($route->uri(), 'api/');
    }

    public static function uriFor(Route $route): string
    {
        $bindings = self::bindings();

        $uri = preg_replace_callback('/\{(\w+)\??\}/', static function (array $match) use ($bindings, $route): string {
            $binding = $bindings[$match[1]]
                ?? throw new LogicException("Add a RouteAudit binding for {{$match[1]}}, used by [".self::key($route).'].');

            return $binding();
        }, $route->uri());

        return '/'.ltrim((string) $uri, '/');
    }

    private static function isApplicationRoute(Route $route): bool
    {
        $uses = $route->getAction('uses');

        if ($uses instanceof Closure) {
            return str_starts_with((string) (new ReflectionFunction($uses))->getFileName(), base_path('routes'));
        }

        return is_string($uses) && str_starts_with($uses, 'App\\');
    }
}
