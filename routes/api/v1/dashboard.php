<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\DashboardController;
use Illuminate\Support\Facades\Route;

Route::get('dashboard', DashboardController::class)->middleware('auth:sanctum')->can('viewDashboard')->name('dashboard');
