<?php

declare(strict_types=1);

use App\Http\Controllers\Web\Admin\CategoryController;
use App\Models\Category;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->prefix('admin')->name('admin.')->group(function (): void {
    Route::get('categories', [CategoryController::class, 'index'])->name('categories.index')->can('viewAny', Category::class);
    Route::get('categories/create', [CategoryController::class, 'create'])->name('categories.create')->can('create', Category::class);
    Route::post('categories', [CategoryController::class, 'store'])->name('categories.store')->can('create', Category::class);
    Route::get('categories/{category}/edit', [CategoryController::class, 'edit'])->name('categories.edit')->can('update', 'category');
    Route::patch('categories/{category}', [CategoryController::class, 'update'])->name('categories.update')->can('update', 'category');
    Route::delete('categories/{category}', [CategoryController::class, 'destroy'])->name('categories.destroy')->can('delete', 'category');
});
