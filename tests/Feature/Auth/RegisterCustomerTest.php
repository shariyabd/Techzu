<?php

declare(strict_types=1);

use App\Actions\Auth\RegisterCustomer;
use App\Enums\Role;

it('gives a registered user the customer role', function (): void {
    $user = app(RegisterCustomer::class)->handle('Casey Customer', 'casey@example.com', 'password');

    expect($user->fresh()?->getRoleNames()->all())->toBe([Role::Customer->value]);
});
