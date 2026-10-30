<?php

declare(strict_types=1);

use App\Enums\EscalationLevel;
use App\Enums\Role;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Attachment;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/** @return Collection<int, RoutingRoute> */
function applicationRoutes(): Collection
{
    return collect(Route::getRoutes()->getRoutes())
        ->reject(fn (RoutingRoute $route): bool => Str::startsWith($route->uri(), ['_boost/', '_inertia/', '_scramble/', 'storage/'])
            || Str::startsWith((string) $route->getName(), 'scramble.'))
        ->values();
}

/** @return Collection<int, string> */
function middlewareOf(RoutingRoute $route): Collection
{
    return collect($route->gatherMiddleware())->filter(fn (mixed $middleware): bool => is_string($middleware))->values();
}

function requiresAuthentication(RoutingRoute $route): bool
{
    return middlewareOf($route)->contains(fn (string $middleware): bool => $middleware === 'auth' || str_starts_with($middleware, 'auth:'));
}

function isGuestOnly(RoutingRoute $route): bool
{
    return middlewareOf($route)->contains('guest');
}

function describeRoute(RoutingRoute $route): string
{
    return implode('|', array_diff($route->methods(), ['HEAD'])).' '.$route->uri();
}

beforeEach(function (): void {
    config(['tickets.auto_assign' => false]);
    $this->category = Category::factory()->create(['is_active' => true]);
    $this->customer = User::factory()->customer()->create();
});

describe('server-side authorization', function (): void {
    it('requires authentication on every route that is not public by design', function (): void {
        $publicNames = ['home', 'api.v1.auth.tokens.store', 'sanctum.csrf-cookie'];

        $offenders = applicationRoutes()
            ->reject(fn (RoutingRoute $route): bool => requiresAuthentication($route)
                || isGuestOnly($route)
                || in_array($route->getName(), $publicNames, true)
                || $route->uri() === 'up')
            ->map(fn (RoutingRoute $route): string => describeRoute($route))
            ->values()
            ->all();

        expect($offenders)->toBe([]);
    });

    it('keeps guest-only routes to sign-in, registration and password reset', function (): void {
        $publicUris = collect(['login', 'register', 'password.request', 'password.email', 'password.reset', 'password.update'])
            ->map(fn (string $name): ?string => Route::getRoutes()->getByName($name)?->uri())
            ->filter()
            ->all();

        $offenders = applicationRoutes()
            ->filter(fn (RoutingRoute $route): bool => isGuestOnly($route))
            ->reject(fn (RoutingRoute $route): bool => in_array($route->uri(), $publicUris, true))
            ->map(fn (RoutingRoute $route): string => describeRoute($route))
            ->values()
            ->all();

        expect($offenders)->toBe([]);
    });

    it('answers 403 to agents and customers on every parameterless admin route', function (): void {
        $adminRoutes = applicationRoutes()
            ->filter(fn (RoutingRoute $route): bool => preg_match('/^(admin\.|api\.v1\.admin\.|api\.v1\.imports\.)/', (string) $route->getName()) === 1)
            ->filter(fn (RoutingRoute $route): bool => $route->parameterNames() === []);
        $offenders = [];

        foreach ([Role::Agent, Role::Customer] as $role) {
            $user = $role === Role::Agent ? User::factory()->agent()->create() : User::factory()->customer()->create();
            $this->actingAs($user);
            Sanctum::actingAs($user, ['*']);

            foreach ($adminRoutes as $route) {
                $method = (string) collect($route->methods())->reject(fn (string $m): bool => $m === 'HEAD')->first();
                $uri = '/'.$route->uri();
                $status = str_starts_with($route->uri(), 'api/')
                    ? $this->json($method, $uri)->status()
                    : $this->call($method, $uri)->status();

                if ($status !== 403) {
                    $offenders[] = "{$role->value} {$method} {$uri} → {$status}";
                }
            }
        }

        expect($adminRoutes)->not->toBeEmpty()
            ->and($offenders)->toBe([]);
    });
});

describe('mass assignment', function (): void {
    it('ignores privileged fields injected into ticket creation', function (): void {
        $agent = User::factory()->agent([$this->category->id])->create();
        $admin = User::factory()->admin()->create();
        $otherCustomer = User::factory()->customer()->create();
        Sanctum::actingAs($this->customer, ['*']);

        $this->postJson(route('api.v1.tickets.store'), [
            'subject' => 'Injected',
            'description' => 'Trying to set protected columns',
            'priority' => TicketPriority::Low->value,
            'category_id' => $this->category->id,
            'number' => 'TKT-1999-999999',
            'status' => TicketStatus::Closed->value,
            'assigned_agent_id' => $agent->id,
            'sla_due_at' => '2099-01-01 00:00:00',
            'escalation_level' => EscalationLevel::Breached->value,
            'customer_id' => $otherCustomer->id,
            'created_by_id' => $admin->id,
        ])->assertCreated();

        $ticket = Ticket::query()->where('subject', 'Injected')->sole();

        expect($ticket->number)->not->toBe('TKT-1999-999999')
            ->and($ticket->status)->toBe(TicketStatus::Open)
            ->and($ticket->assigned_agent_id)->toBeNull()
            ->and($ticket->escalation_level)->toBe(EscalationLevel::None)
            ->and($ticket->customer_id)->toBe($this->customer->id)
            ->and($ticket->created_by_id)->toBe($this->customer->id)
            ->and($ticket->sla_due_at->year)->not->toBe(2099);
    });
});

describe('file uploads', function (): void {
    it('rejects a PHP script disguised as a PDF by its content', function (): void {
        Storage::fake((string) config('attachments.disk'));
        $path = (string) tempnam(sys_get_temp_dir(), 'evil');
        file_put_contents($path, "<?php echo 'pwned';");
        Sanctum::actingAs($this->customer, ['*']);

        $this->postJson(route('api.v1.tickets.store'), [
            'subject' => 'Invoice',
            'description' => 'See attached',
            'priority' => TicketPriority::Low->value,
            'category_id' => $this->category->id,
            'attachments' => [new UploadedFile($path, 'invoice.pdf', 'application/pdf', null, true)],
        ])->assertUnprocessable()->assertJsonValidationErrors('attachments.0');

        unlink($path);

        expect(Ticket::query()->count())->toBe(0)
            ->and(Attachment::query()->count())->toBe(0);
    });

    it('rejects a text file whose extension is not allowed on ticket creation', function (): void {
        Storage::fake((string) config('attachments.disk'));
        Sanctum::actingAs($this->customer, ['*']);

        $this->postJson(route('api.v1.tickets.store'), [
            'subject' => 'Page',
            'description' => 'See attached',
            'priority' => TicketPriority::Low->value,
            'category_id' => $this->category->id,
            'attachments' => [UploadedFile::fake()->createWithContent('page.html', 'plain text')],
        ])->assertUnprocessable()->assertJsonValidationErrors('attachments.0');

        expect(Ticket::query()->count())->toBe(0);
    });
});

describe('rate limiting', function (): void {
    it('runs every api/v1 route through the throttled api group', function (): void {
        $apiGroup = collect(app('router')->getMiddlewareGroups()['api'] ?? []);

        $outside = applicationRoutes()
            ->filter(fn (RoutingRoute $route): bool => str_starts_with($route->uri(), 'api/v1/'))
            ->reject(fn (RoutingRoute $route): bool => middlewareOf($route)->contains('api'))
            ->map(fn (RoutingRoute $route): string => describeRoute($route))
            ->values()
            ->all();

        expect($apiGroup->contains(fn (string $middleware): bool => str_contains($middleware, 'throttle') || str_contains($middleware, 'ThrottleRequests')))->toBeTrue()
            ->and($outside)->toBe([]);
    });

    it('throttles every route that accepts uploads', function (string $name): void {
        expect(middlewareOf(Route::getRoutes()->getByName($name) ?? throw new RuntimeException("Route {$name} is missing."))
            ->contains(fn (string $middleware): bool => str_starts_with($middleware, 'throttle:')))->toBeTrue();
    })->with([
        'tickets.store',
        'api.v1.tickets.store',
        'tickets.replies.store',
        'api.v1.tickets.replies.store',
        'admin.imports.store',
        'api.v1.imports.store',
    ]);
});

describe('xss', function (): void {
    it('never renders raw HTML in Vue', function (): void {
        $offenders = collect(File::allFiles(resource_path('js')))
            ->filter(fn (SplFileInfo $file): bool => str_contains((string) file_get_contents($file->getPathname()), 'v-html'))
            ->map(fn (SplFileInfo $file): string => $file->getPathname())
            ->values()
            ->all();

        expect($offenders)->toBe([]);
    });
});
