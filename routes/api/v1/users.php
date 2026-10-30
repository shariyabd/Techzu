<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Admin\RoleController;
use App\Http\Controllers\Api\V1\Admin\UserController;
use App\Models\User;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->prefix('admin')->name('admin.')->group(function (): void {
    Route::get('users', [UserController::class, 'index'])->name('users.index')->can('viewAny', User::class);
    Route::post('users', [UserController::class, 'store'])->name('users.store')->can('create', User::class);
    Route::patch('users/{user}', [UserController::class, 'update'])->name('users.update')->can('update', 'user');
    Route::get('roles', RoleController::class)->name('roles.index')->can('viewAny', User::class);
    Route::patch('roles/{role}', [RoleController::class, 'update'])->name('roles.update')->can('managePermissions', User::class);
});
