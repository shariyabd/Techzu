<?php

declare(strict_types=1);

use App\Http\Controllers\Web\Admin\RoleController;
use App\Http\Controllers\Web\Admin\UserController;
use App\Models\User;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->prefix('admin')->name('admin.')->group(function (): void {
    Route::get('users', [UserController::class, 'index'])->name('users.index')->can('viewAny', User::class);
    Route::get('users/create', [UserController::class, 'create'])->name('users.create')->can('create', User::class);
    Route::post('users', [UserController::class, 'store'])->name('users.store')->can('create', User::class);
    Route::get('users/{user}/edit', [UserController::class, 'edit'])->name('users.edit')->can('update', 'user');
    Route::patch('users/{user}', [UserController::class, 'update'])->name('users.update')->can('update', 'user');
    Route::get('roles', RoleController::class)->name('roles.index')->can('viewAny', User::class);
});
