<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

final class HomeController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        if ($request->user() === null) {
            return redirect()->route('login');
        }

        if (Gate::has('viewDashboard') && Gate::allows('viewDashboard')) {
            return redirect()->route('dashboard');
        }

        return Route::has('tickets.index')
            ? redirect()->route('tickets.index')
            : redirect()->route('dashboard');
    }
}
