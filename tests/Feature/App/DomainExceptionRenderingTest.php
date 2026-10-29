<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use Tests\Fixtures\ExampleDomainException;

beforeEach(function (): void {
    Route::middleware('web')->get('/_test/domain-exception', function (): never {
        throw new ExampleDomainException('The ticket was claimed by someone else.');
    });

    Route::middleware('api')->prefix('api/v1')->get('/_test/domain-exception', function (): never {
        throw new ExampleDomainException('The ticket was claimed by someone else.');
    });
});

it('renders a domain exception as {message, code} JSON with its status when JSON is expected', function (): void {
    $this->getJson('/_test/domain-exception')
        ->assertStatus(409)
        ->assertExactJson([
            'message' => 'The ticket was claimed by someone else.',
            'code' => 'example_conflict',
        ]);
});

it('renders JSON for API routes even without an Accept header', function (): void {
    $this->get('/api/v1/_test/domain-exception')
        ->assertStatus(409)
        ->assertExactJson([
            'message' => 'The ticket was claimed by someone else.',
            'code' => 'example_conflict',
        ]);
});

it('redirects back with an error flash for web requests', function (): void {
    $this->from('/previous-page')
        ->get('/_test/domain-exception')
        ->assertRedirect('/previous-page')
        ->assertSessionHas('error', 'The ticket was claimed by someone else.');
});

it('does not report domain exceptions as incidents', function (): void {
    Exceptions::fake();

    $this->getJson('/_test/domain-exception')->assertStatus(409);

    Exceptions::assertNothingReported();
});

it('renders framework errors on unknown API routes as JSON', function (): void {
    $this->get('/api/v1/does-not-exist')
        ->assertNotFound()
        ->assertHeader('content-type', 'application/json');
});
