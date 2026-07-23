<?php

namespace App\Http\Controllers;

use App\Models\LoginLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function showLogin(): View
    {
        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $remember = $request->boolean('remember');
        $ok = Auth::attempt($credentials + ['is_active' => true], $remember);

        LoginLog::create([
            'user_id' => $ok ? Auth::id() : null,
            'username' => $credentials['username'],
            'client' => 'web',
            'ip' => $request->ip(),
            'successful' => $ok,
        ]);

        if (! $ok) {
            return back()
                ->withInput($request->only('username'))
                ->withErrors(['username' => 'Invalid username or password.']);
        }

        $request->session()->regenerate();

        return redirect()->intended(route('overview'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
