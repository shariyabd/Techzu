<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\HolidayController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\SlaSettingsController;
use App\Http\Controllers\Admin\TicketImportController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Web\AgentDirectoryController;
use App\Http\Controllers\Web\AssignmentController;
use App\Http\Controllers\Web\AttachmentDownloadController;
use App\Http\Controllers\Web\AttachmentPreviewController;
use App\Http\Controllers\Web\Auth\EmailVerificationController;
use App\Http\Controllers\Web\Auth\LoginController;
use App\Http\Controllers\Web\Auth\PasswordResetController;
use App\Http\Controllers\Web\Auth\RegisterController;
use App\Http\Controllers\Web\AvatarController;
use App\Http\Controllers\Web\CustomerLookupController;
use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\HomeController;
use App\Http\Controllers\Web\NotificationController;
use App\Http\Controllers\Web\ProfileController;
use App\Http\Controllers\Web\SavedReplyController;
use App\Http\Controllers\Web\TicketController;
use App\Http\Controllers\Web\TicketReplyController;
use App\Models\Category;
use App\Models\Holiday;
use App\Models\SavedReply;
use App\Models\SlaSetting;
use App\Models\TicketImport;
use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

Route::middleware('guest')->group(function (): void {
    Route::controller(LoginController::class)->group(function (): void {
        Route::get('login', 'create')->name('login');
        Route::post('login', 'store')->middleware('throttle:5,1');
    });

    Route::controller(RegisterController::class)->group(function (): void {
        Route::get('register', 'create')->name('register');
        Route::post('register', 'store')->middleware('throttle:5,1');
    });

    Route::controller(PasswordResetController::class)->group(function (): void {
        Route::get('forgot-password', 'create')->name('password.request');
        Route::post('forgot-password', 'store')->middleware('throttle:5,1')->name('password.email');
        Route::get('reset-password/{token}', 'edit')->name('password.reset');
        Route::post('reset-password', 'update')->middleware('throttle:5,1')->name('password.update');
    });
});

Route::middleware('auth')->group(function (): void {
    Route::post('logout', [LoginController::class, 'destroy'])->name('logout');

    Route::controller(EmailVerificationController::class)->group(function (): void {
        Route::get('email/verify', 'notice')->name('verification.notice');
        Route::get('email/verify/{id}/{hash}', 'verify')
            ->middleware(['signed', 'throttle:6,1'])
            ->name('verification.verify');
        Route::post('email/verification-notification', 'send')
            ->middleware('throttle:6,1')
            ->name('verification.send');
    });
});

Route::middleware('auth:sanctum')->get('avatars/{user}', AvatarController::class)
    ->whereNumber('user')
    ->name('avatars.show');

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('dashboard', DashboardController::class)->can('viewDashboard')->name('dashboard');

    Route::controller(TicketController::class)->group(function (): void {
        Route::get('tickets', 'index')->name('tickets.index');
        Route::get('tickets/create', 'create')->name('tickets.create');
        Route::post('tickets', 'store')->middleware('throttle:uploads')->name('tickets.store');
        Route::get('tickets/{ticket}', 'show')->name('tickets.show');
        Route::patch('tickets/{ticket}', 'update')->name('tickets.update');
        Route::patch('tickets/{ticket}/status', 'status')->name('tickets.status');
        Route::patch('tickets/{ticket}/priority', 'priority')->name('tickets.priority');
    });

    Route::controller(AssignmentController::class)->group(function (): void {
        Route::post('tickets/{ticket}/claim', 'claim')->name('tickets.claim');
        Route::post('tickets/{ticket}/assign', 'assign')->name('tickets.assign');
    });

    Route::post('tickets/{ticket}/replies', [TicketReplyController::class, 'store'])->middleware('throttle:uploads')->name('tickets.replies.store');
    Route::get('attachments/{attachment}', AttachmentDownloadController::class)->name('attachments.download');
    Route::get('attachments/{attachment}/preview', AttachmentPreviewController::class)->name('attachments.preview');

    Route::controller(NotificationController::class)->can('viewAny', DatabaseNotification::class)->group(function (): void {
        Route::get('notifications', 'index')->name('notifications.index');
        Route::post('notifications/read-all', 'readAll')->name('notifications.read-all');
        Route::post('notifications/{notification}/read', 'read')
            ->whereUuid('notification')
            ->name('notifications.read');
    });

    Route::controller(ProfileController::class)->group(function (): void {
        Route::get('profile', 'edit')->name('profile.edit');
        Route::patch('profile', 'update')->name('profile.update');
        Route::put('profile/password', 'updatePassword')->middleware('throttle:6,1')->name('profile.password.update');
        Route::post('profile/avatar', 'updateAvatar')->middleware('throttle:uploads')->name('profile.avatar.update');
        Route::delete('profile/avatar', 'destroyAvatar')->name('profile.avatar.destroy');
    });

    Route::controller(SavedReplyController::class)->group(function (): void {
        Route::get('saved-replies', 'index')->name('saved-replies.index')->can('viewAny', SavedReply::class);
        Route::get('saved-replies/picker', 'picker')->middleware('throttle:60,1')->name('saved-replies.picker')->can('viewAny', SavedReply::class);
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

    Route::prefix('admin')->name('admin.')->group(function (): void {
        Route::controller(UserController::class)->group(function (): void {
            Route::get('users', 'index')->name('users.index')->can('viewAny', User::class);
            Route::get('users/create', 'create')->name('users.create')->can('create', User::class);
            Route::post('users', 'store')->name('users.store')->can('create', User::class);
            Route::get('users/{user}/edit', 'edit')->name('users.edit')->can('update', 'user');
            Route::patch('users/{user}', 'update')->name('users.update')->can('update', 'user');
        });

        Route::get('roles', RoleController::class)->name('roles.index')->can('viewAny', User::class);
        Route::patch('roles/{role}', [RoleController::class, 'update'])->name('roles.update')->can('managePermissions', User::class);

        Route::controller(CategoryController::class)->group(function (): void {
            Route::get('categories', 'index')->name('categories.index')->can('viewAny', Category::class);
            Route::get('categories/create', 'create')->name('categories.create')->can('create', Category::class);
            Route::post('categories', 'store')->name('categories.store')->can('create', Category::class);
            Route::get('categories/{category}/edit', 'edit')->name('categories.edit')->can('update', 'category');
            Route::patch('categories/{category}', 'update')->name('categories.update')->can('update', 'category');
            Route::delete('categories/{category}', 'destroy')->name('categories.destroy')->can('delete', 'category');
        });

        Route::controller(HolidayController::class)->group(function (): void {
            Route::get('holidays', 'index')->name('holidays.index')->can('viewAny', Holiday::class);
            Route::get('holidays/create', 'create')->name('holidays.create')->can('create', Holiday::class);
            Route::post('holidays', 'store')->name('holidays.store')->can('create', Holiday::class);
            Route::get('holidays/{holiday}/edit', 'edit')->name('holidays.edit')->can('update', 'holiday');
            Route::patch('holidays/{holiday}', 'update')->name('holidays.update')->can('update', 'holiday');
            Route::delete('holidays/{holiday}', 'destroy')->name('holidays.destroy')->can('delete', 'holiday');
        });

        Route::controller(SlaSettingsController::class)->group(function (): void {
            Route::get('sla', 'edit')->name('sla.edit')->can('view', SlaSetting::class);
            Route::put('sla', 'update')->name('sla.update')->can('update', SlaSetting::class);
        });

        Route::controller(TicketImportController::class)->prefix('imports')->name('imports.')->group(function (): void {
            Route::get('/', 'index')->name('index')->can('viewAny', TicketImport::class);
            Route::get('create', 'create')->name('create')->can('create', TicketImport::class);
            Route::get('sample', 'downloadSample')->name('sample')->can('create', TicketImport::class);
            Route::post('/', 'store')->name('store')->middleware('throttle:uploads')->can('create', TicketImport::class);
            Route::get('{import}', 'show')->name('show')->can('view', 'import');
            Route::get('{import}/errors/download', 'downloadErrors')->name('errors.download')->can('view', 'import');
        });
    });
});
