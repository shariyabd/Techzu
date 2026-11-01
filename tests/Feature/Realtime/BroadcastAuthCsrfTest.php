<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;

beforeEach(function (): void {
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => 'test-app',
        'broadcasting.connections.reverb.options.host' => '127.0.0.1',
    ]);
    app(BroadcastManager::class)->forgetDrivers();
    require base_path('routes/channels.php');

    $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
    {
        protected function runningUnitTests(): bool
        {
            return false;
        }
    });
});

it('authorizes a private channel on a plain-HTTP host from the CSRF token alone, without Sec-Fetch headers', function (Closure $headers): void {
    $user = User::factory()->customer()->create();

    $this->actingAs($user)
        ->withSession(['_token' => 'session-csrf-token'])
        ->withHeaders($headers('session-csrf-token'))
        ->post('http://helpdesk.test/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => "private-App.Models.User.{$user->id}"])
        ->assertOk();
})->with([
    'meta tag token as X-CSRF-TOKEN' => [fn (string $token): array => ['X-CSRF-TOKEN' => $token]],
    'XSRF-TOKEN cookie as X-XSRF-TOKEN' => [fn (string $token): array => ['X-XSRF-TOKEN' => encrypt(CookieValuePrefix::create('XSRF-TOKEN', app('encrypter')->getKey()).$token, false)]],
]);

it('renders the CSRF token into the page head for the realtime client', function (): void {
    $this->withoutVite()
        ->actingAs(User::factory()->customer()->create())
        ->withSession(['_token' => 'session-csrf-token'])
        ->get(route('tickets.index'))
        ->assertOk()
        ->assertSee('<meta name="csrf-token" content="session-csrf-token">', false);
});
