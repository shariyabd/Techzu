<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

function resolveLimit(string $name, Request $request): Limit
{
    $limiter = RateLimiter::limiter($name);

    expect($limiter)->not->toBeNull();

    $limit = $limiter($request);

    expect($limit)->toBeInstanceOf(Limit::class);

    return $limit;
}

it('defines named rate limiters keyed by IP for guests', function (string $name, int $perMinute): void {
    $request = Request::create('/', 'GET', server: ['REMOTE_ADDR' => '10.0.0.7']);

    $limit = resolveLimit($name, $request);

    expect($limit->maxAttempts)->toBe($perMinute)
        ->and($limit->decaySeconds)->toBe(60)
        ->and($limit->key)->toBe('10.0.0.7');
})->with([
    'api' => ['api', 60],
    'uploads' => ['uploads', 20],
]);

it('keys rate limits by user id for authenticated requests', function (string $name): void {
    $user = User::factory()->create();
    $request = Request::create('/', 'GET', server: ['REMOTE_ADDR' => '10.0.0.7']);
    $request->setUserResolver(fn (): User => $user);

    expect(resolveLimit($name, $request)->key)->toBe((string) $user->id);
})->with(['api', 'uploads']);

it('prevents lazy loading outside production', function (): void {
    expect(Model::preventsLazyLoading())->toBeTrue();
});
