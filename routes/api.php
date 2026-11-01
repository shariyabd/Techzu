<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Admin\CategoryController as AdminCategoryController;
use App\Http\Controllers\Api\V1\Admin\HolidayController;
use App\Http\Controllers\Api\V1\Admin\RoleController;
use App\Http\Controllers\Api\V1\Admin\SlaSettingsController;
use App\Http\Controllers\Api\V1\Admin\UserController;
use App\Http\Controllers\Api\V1\AgentDirectoryController;
use App\Http\Controllers\Api\V1\AssignmentController;
use App\Http\Controllers\Api\V1\AuthTokenController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\CurrentUserController;
use App\Http\Controllers\Api\V1\CustomerLookupController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\SavedReplyController;
use App\Http\Controllers\Api\V1\TicketController;
use App\Http\Controllers\Api\V1\TicketImportController;
use App\Http\Controllers\Api\V1\TicketReplyController;
use App\Http\Controllers\Web\AttachmentDownloadController;
use App\Models\Category;
use App\Models\Holiday;
use App\Models\SavedReply;
use App\Models\SlaSetting;
use App\Models\Ticket;
use App\Models\TicketImport;
use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Route;

Route::name('api.v1.')->group(function (): void {
    Route::post('auth/tokens', [AuthTokenController::class, 'store'])
        ->middleware('throttle:api-tokens')
        ->name('auth.tokens.store');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::delete('auth/tokens/current', [AuthTokenController::class, 'destroy'])->name('auth.tokens.destroy');
        Route::get('me', CurrentUserController::class)->name('auth.me');

        Route::controller(ProfileController::class)->prefix('me')->name('me.')->group(function (): void {
            Route::patch('profile', 'update')->name('profile.update');
            Route::put('password', 'updatePassword')->middleware('throttle:6,1')->name('password.update');
            Route::post('avatar', 'updateAvatar')->middleware('throttle:uploads')->name('avatar.update');
            Route::delete('avatar', 'destroyAvatar')->name('avatar.destroy');
        });

        Route::get('dashboard', DashboardController::class)->can('viewDashboard')->name('dashboard');

        Route::controller(TicketController::class)->group(function (): void {
            Route::get('tickets', 'index')->name('tickets.index');
            Route::post('tickets', 'store')->middleware('throttle:uploads')->name('tickets.store');
            Route::get('tickets/{ticket}', 'show')->name('tickets.show');
            Route::patch('tickets/{ticket}', 'update')->name('tickets.update');
            Route::patch('tickets/{ticket}/status', 'status')->name('tickets.status');
            Route::patch('tickets/{ticket}/priority', 'priority')->name('tickets.priority');
            Route::get('tickets/{ticket}/activities', 'activities')->name('tickets.activities');
        });

        Route::controller(AssignmentController::class)->group(function (): void {
            Route::post('tickets/{ticket}/claim', 'claim')->name('tickets.claim');
            Route::post('tickets/{ticket}/assign', 'assign')->name('tickets.assign');
        });

        Route::controller(TicketReplyController::class)->group(function (): void {
            Route::get('tickets/{ticket}/replies', 'index')->name('tickets.replies.index');
            Route::post('tickets/{ticket}/replies', 'store')->middleware('throttle:uploads')->name('tickets.replies.store');
        });

        Route::get('attachments/{attachment}', AttachmentDownloadController::class)->name('attachments.download');

        Route::get('categories', [CategoryController::class, 'index'])->name('categories.index')->can('create', Ticket::class);

        Route::controller(NotificationController::class)->can('viewAny', DatabaseNotification::class)->group(function (): void {
            Route::get('notifications', 'index')->name('notifications.index');
            Route::post('notifications/read-all', 'readAll')->name('notifications.read-all');
            Route::post('notifications/{notification}/read', 'read')
                ->whereUuid('notification')
                ->name('notifications.read');
        });

        Route::controller(SavedReplyController::class)->group(function (): void {
            Route::get('saved-replies', 'index')->name('saved-replies.index')->can('viewAny', SavedReply::class);
            Route::post('saved-replies', 'store')->name('saved-replies.store')->can('create', SavedReply::class);
            Route::patch('saved-replies/{savedReply}', 'update')->name('saved-replies.update')->can('update', 'savedReply');
            Route::delete('saved-replies/{savedReply}', 'destroy')->name('saved-replies.destroy')->can('delete', 'savedReply');
        });

        Route::controller(AgentDirectoryController::class)->group(function (): void {
            Route::get('agents', 'index')->name('agents.index')->can('viewAgentDirectory', User::class);
            Route::get('agents/{user}', 'show')->name('agents.show')->can('viewAgentProfile', 'user');
        });

        Route::get('customers/lookup', CustomerLookupController::class)
            ->middleware('throttle:60,1')
            ->name('customers.lookup')
            ->can('lookupCustomers', User::class);

        Route::controller(TicketImportController::class)->group(function (): void {
            Route::post('imports', 'store')->name('imports.store')->middleware('throttle:uploads')->can('create', TicketImport::class);
            Route::get('imports/{import}', 'show')->name('imports.show')->can('view', 'import');
            Route::get('imports/{import}/errors', 'errors')->name('imports.errors.index')->can('view', 'import');
        });

        Route::prefix('admin')->name('admin.')->group(function (): void {
            Route::controller(UserController::class)->group(function (): void {
                Route::get('users', 'index')->name('users.index')->can('viewAny', User::class);
                Route::post('users', 'store')->name('users.store')->can('create', User::class);
                Route::patch('users/{user}', 'update')->name('users.update')->can('update', 'user');
            });

            Route::get('roles', RoleController::class)->name('roles.index')->can('viewAny', User::class);
            Route::patch('roles/{role}', [RoleController::class, 'update'])->name('roles.update')->can('managePermissions', User::class);

            Route::controller(AdminCategoryController::class)->group(function (): void {
                Route::get('categories', 'index')->name('categories.index')->can('viewAny', Category::class);
                Route::post('categories', 'store')->name('categories.store')->can('create', Category::class);
                Route::patch('categories/{category}', 'update')->name('categories.update')->can('update', 'category');
                Route::delete('categories/{category}', 'destroy')->name('categories.destroy')->can('delete', 'category');
            });

            Route::controller(HolidayController::class)->group(function (): void {
                Route::get('holidays', 'index')->name('holidays.index')->can('viewAny', Holiday::class);
                Route::post('holidays', 'store')->name('holidays.store')->can('create', Holiday::class);
                Route::patch('holidays/{holiday}', 'update')->name('holidays.update')->can('update', 'holiday');
                Route::delete('holidays/{holiday}', 'destroy')->name('holidays.destroy')->can('delete', 'holiday');
            });

            Route::controller(SlaSettingsController::class)->group(function (): void {
                Route::get('sla', 'show')->name('sla.show')->can('view', SlaSetting::class);
                Route::put('sla', 'update')->name('sla.update')->can('update', SlaSetting::class);
            });
        });
    });
});
