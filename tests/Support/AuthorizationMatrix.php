<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\Role;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use BackedEnum;
use Closure;
use Illuminate\Auth\Access\Response;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use LogicException;
use Tests\TestCase;

final class AuthorizationMatrix
{
    private ?User $user = null;

    private function __construct(private readonly TestCase $test) {}

    public static function for(TestCase $test): self
    {
        return new self($test);
    }

    /** @param array<string, mixed> $attributes */
    public function actingAsRole(Role $role, array $attributes = []): User
    {
        $factory = match ($role) {
            Role::Admin => User::factory()->admin(),
            Role::Agent => User::factory()->agent(),
            Role::Customer => User::factory()->customer(),
        };

        $this->user = $factory->create($attributes);

        return $this->user;
    }

    public function asGuest(): self
    {
        $this->user = null;
        app('auth')->forgetGuards();

        return $this;
    }

    public function user(): User
    {
        return $this->user ?? throw new LogicException('Call actingAsRole() before using the matrix user.');
    }

    /** @param  array<string, mixed>  $attributes */
    public function ticketFor(TicketRelation $relation, array $attributes = []): Ticket
    {
        $user = $this->user();

        return match ($relation) {
            TicketRelation::Own => Ticket::factory()->create(array_merge([
                'customer_id' => $user->id,
                'created_by_id' => $user->id,
                'assigned_agent_id' => null,
            ], $attributes)),
            TicketRelation::Assigned => Ticket::factory()->assignedTo($user)->create($attributes),
            TicketRelation::Unassigned => Ticket::factory()->create(array_merge([
                'assigned_agent_id' => null,
            ], $attributes)),
            TicketRelation::Others => Ticket::factory()
                ->assignedTo(User::factory()->agent()->create())
                ->create($attributes),
        };
    }

    /** @param array<string, mixed> $payload */
    public function expectAccess(string $method, string $uri, int $status, array $payload = []): TestResponse
    {
        return $this->send($method, $uri, $payload)->assertStatus($status);
    }

    /**
     * @param  string|Closure(Ticket): string  $uri
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $ticketAttributes
     */
    public function expectAccessAs(
        Role $role,
        string $method,
        string|Closure $uri,
        int $status,
        array $payload = [],
        ?TicketRelation $relation = null,
        array $ticketAttributes = [],
    ): TestResponse {
        $this->actingAsRole($role);

        if ($uri instanceof Closure) {
            if ($relation === null) {
                throw new LogicException('A URI closure needs a TicketRelation to build its ticket.');
            }

            $uri = $uri($this->ticketFor($relation, $ticketAttributes));
        } elseif ($relation !== null || $ticketAttributes !== []) {
            throw new LogicException('A string URI cannot use a TicketRelation or ticket attributes.');
        }

        return $this->expectAccess($method, $uri, $status, $payload);
    }

    public static function statusOf(Response $response): int
    {
        return $response->allowed() ? 200 : ($response->status() ?? 403);
    }

    /** @return array<string, array{0: Role}> */
    public static function roles(): array
    {
        return [
            Role::Admin->value => [Role::Admin],
            Role::Agent->value => [Role::Agent],
            Role::Customer->value => [Role::Customer],
        ];
    }

    /**
     * @param  list<array{0: Role, 1: TicketRelation, 2: TicketStatus, 3: string, 4: list<mixed>, 5: int}>  $rows
     * @return array<string, array{0: Role, 1: TicketRelation, 2: TicketStatus, 3: string, 4: list<mixed>, 5: int}>
     */
    public static function ticketCases(array $rows): array
    {
        $cases = [];

        foreach ($rows as $row) {
            [$role, $relation, $status, $ability, $arguments, $expected] = $row;
            $label = sprintf(
                '%s · %s · %s · %s%s → %d',
                $role->value,
                $relation->value,
                $status->value,
                $ability,
                self::describeArguments($arguments),
                $expected,
            );

            if (array_key_exists($label, $cases)) {
                throw new LogicException("Duplicate authorization case [{$label}].");
            }

            $cases[$label] = $row;
        }

        return $cases;
    }

    /** @param list<mixed> $arguments */
    private static function describeArguments(array $arguments): string
    {
        if ($arguments === []) {
            return '';
        }

        $parts = array_map(
            static fn (mixed $argument): string => $argument instanceof BackedEnum ? (string) $argument->value : get_debug_type($argument),
            $arguments,
        );

        return '('.implode(', ', $parts).')';
    }

    /** @param array<string, mixed> $payload */
    private function send(string $method, string $uri, array $payload): TestResponse
    {
        $path = parse_url($uri, PHP_URL_PATH);
        $isApi = str_starts_with(ltrim(is_string($path) ? $path : $uri, '/'), 'api/');

        if ($isApi) {
            if ($this->user !== null) {
                Sanctum::actingAs($this->user, ['*']);
            }

            return $this->test->json($method, $uri, $payload);
        }

        if ($this->user !== null) {
            $this->test->actingAs($this->user, 'web');
        }

        return $this->test->call($method, $uri, $payload);
    }
}
