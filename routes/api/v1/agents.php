<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AgentDirectoryController;
use App\Models\User;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('agents', [AgentDirectoryController::class, 'index'])->name('agents.index')->can('viewAgentDirectory', User::class);
    Route::get('agents/{user}', [AgentDirectoryController::class, 'show'])->name('agents.show')->can('viewAgentProfile', 'user');
});
