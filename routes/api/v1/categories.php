<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Admin\CategoryController as AdminCategoryController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Models\Category;
use App\Models\Ticket;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('categories', [CategoryController::class, 'index'])->name('categories.index')->can('create', Ticket::class);
    Route::prefix('admin')->name('admin.')->group(function (): void {
        Route::get('categories', [AdminCategoryController::class, 'index'])->name('categories.index')->can('viewAny', Category::class);
        Route::post('categories', [AdminCategoryController::class, 'store'])->name('categories.store')->can('create', Category::class);
        Route::patch('categories/{category}', [AdminCategoryController::class, 'update'])->name('categories.update')->can('update', 'category');
        Route::delete('categories/{category}', [AdminCategoryController::class, 'destroy'])->name('categories.destroy')->can('delete', 'category');
    });
});
