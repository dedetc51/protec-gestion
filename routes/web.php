<?php

use App\Http\Controllers\InitialPasswordController;
use App\Models\User;
use App\Services\SecurityAudit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/login');
Route::middleware('guest')->group(function () {
    Route::view('/login', 'auth.login')->name('login');
    Route::post('/login', function (Request $request, SecurityAudit $audit) {
        $credentials = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        $email = strtolower(trim($credentials['email']));
        $actor = User::whereRaw('lower(email) = ?', [$email])->first();
        if (! $actor || $actor->deactivated_at || ! Auth::attempt(['email' => $actor->email, 'password' => $credentials['password']])) {
            $audit->record('auth.login.failed', 'rejected', $actor, $request, ['email_hash' => hash('sha256', $email)]);

            return back()->withErrors(['email' => 'Identifiants incorrects.'])->onlyInput('email');
        }
        $request->session()->regenerate();
        $audit->record('auth.login.succeeded', 'success', $actor, $request);

        return redirect()->intended($actor->must_change_password ? route('password.initial.edit') : route('dashboard'));
    })->middleware('throttle:5,1');
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
        Route::view('/dashboard', 'dashboard')->name('dashboard');
        Route::view('/equipment', 'modules.coming-soon', ['module' => 'Matériel'])->name('equipment.index');
        Route::view('/vehicles', 'modules.coming-soon', ['module' => 'Véhicules'])->name('vehicles.index');
        Route::get('/admin', function (Request $request) {
            abort_unless($request->user()->isAdmin(), 403);

            return view('admin.index');
        })->name('admin.index');
    });
});
Route::get('/up', function () {
    try {
        DB::select('select 1');

        return response('OK', 200, ['Content-Type' => 'text/plain']);
    } catch (Throwable) {
        return response('Unavailable', 503, ['Content-Type' => 'text/plain']);
    }
});
