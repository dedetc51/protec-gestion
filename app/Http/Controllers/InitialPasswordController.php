<?php

namespace App\Http\Controllers;

use App\Http\Requests\Auth\InitialPasswordUpdateRequest;
use App\Services\SecurityAudit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class InitialPasswordController extends Controller
{
    public function edit(): View
    {
        return view('auth.change-initial-password');
    }

    public function update(InitialPasswordUpdateRequest $request, SecurityAudit $audit): RedirectResponse
    {
        $user = $request->user();
        $user->forceFill(['password' => Hash::make($request->validated('password')), 'must_change_password' => false])->save();
        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->where('id', '!=', $request->session()->getId())->delete();
        }
        $request->session()->regenerate();
        $audit->record('auth.password.initial_changed', 'success', $user, $request);

        return redirect()->route('dashboard');
    }
}
