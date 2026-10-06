<?php

use App\Http\Controllers\Admin\BranchController;
use App\Http\Controllers\Admin\DepartmentController;
use App\Http\Controllers\Admin\MemberAssignmentController;
use App\Http\Controllers\InitialPasswordController;
use App\Models\User;
use App\Services\SecurityAudit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/login');
Route::middleware('guest')->group(function () {
    Route::view('/login', 'auth.login')->name('login');
    Route::post('/login', function (Request $request, SecurityAudit $audit) {
        $credentials = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']], ['email.required' => 'L’adresse e-mail est obligatoire.', 'email.email' => 'L’adresse e-mail est invalide.', 'password.required' => 'Le mot de passe est obligatoire.']);
        $email = strtolower(trim($credentials['email']));
        $limitKey = hash('sha256', $email.'|'.$request->ip());
        if (RateLimiter::tooManyAttempts($limitKey, 5)) {
            return response('Identifiants incorrects.', 429, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }
        $actor = User::whereRaw('lower(email) = ?', [$email])->first();
        if (! $actor || $actor->deactivated_at || ! Auth::attempt(['email' => $actor->email, 'password' => $credentials['password']])) {
            RateLimiter::hit($limitKey, 60);
            $audit->record('auth.login.failed', 'rejected', $actor, $request, ['email_hash' => hash('sha256', $email)]);

            return back()->withErrors(['email' => 'Identifiants incorrects.'])->onlyInput('email');
        }
        RateLimiter::clear($limitKey);
        $request->session()->regenerate();
        $audit->record('auth.login.succeeded', 'success', $actor, $request);

        return redirect()->intended($actor->must_change_password ? route('password.initial.edit') : route('dashboard'));
    });
});
Route::middleware('auth')->group(function () {
    Route::get('/change-initial-password', [InitialPasswordController::class, 'edit'])->name('password.initial.edit');
    Route::patch('/change-initial-password', [InitialPasswordController::class, 'update'])->name('password.initial.update');
    Route::post('/logout', function (Request $request, SecurityAudit $audit) {
        $audit->record('auth.logout', 'success', $request->user(), $request);
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    })->name('logout');
    Route::middleware('password.changed')->group(function () {
        Route::prefix('admin')->name('admin.')->group(function (): void {
            Route::get('organization', [DepartmentController::class, 'index'])->name('organization.index');
            Route::post('organization/departments', [DepartmentController::class, 'store'])->name('organization.departments.store');
            Route::patch('organization/departments/{department}', [DepartmentController::class, 'update'])->name('organization.departments.update');
            Route::delete('organization/departments/{department}', [DepartmentController::class, 'destroy'])->name('organization.departments.destroy');
            Route::post('organization/branches', [BranchController::class, 'store'])->name('organization.branches.store');
            Route::patch('organization/branches/{branch}', [BranchController::class, 'update'])->name('organization.branches.update');
            Route::delete('organization/branches/{branch}', [BranchController::class, 'destroy'])->name('organization.branches.destroy');
            Route::get('assignments', [MemberAssignmentController::class, 'index'])->name('assignments.index');
            Route::get('assignments/{user}/edit', [MemberAssignmentController::class, 'edit'])->name('assignments.edit');
            Route::put('assignments/{user}', [MemberAssignmentController::class, 'update'])->name('assignments.update');
        });
        Route::view('/dashboard', 'dashboard')->name('dashboard');
        Route::view('/equipment', 'modules.coming-soon', ['module' => 'Matériel'])->name('equipment.index');
        Route::view('/vehicles', 'modules.coming-soon', ['module' => 'Véhicules'])->name('vehicles.index');
        Route::get('/admin', function (Request $request) {
            abort_unless($request->user()->isAdmin(), 403);

            return view('admin.index');
        })->name('admin.index');
    });
});
