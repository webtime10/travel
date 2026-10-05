<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class AdminLoginController extends Controller
{
    public function showLoginForm()
    {
        return view('auth.login');
    }

    public function login(Request $request)
    {
        $request->validate([
            'login' => ['required', 'string'],
            'password' => ['required'],
        ]);

        $user = \App\Models\User::where('name', $request->input('login'))->first();

        if ($user && Hash::check($request->input('password'), $user->password)) {
            Auth::login($user, $request->boolean('remember'));
            $request->session()->regenerate();
            $user->load('roleRelation');

            if ($user->role_id) {
                return redirect()->intended('/admin');
            }

            Auth::logout();

            return back()->withErrors(['login' => 'У пользователя не назначена роль.']);
        }

        return back()->withErrors(['login' => 'Неверный логин или пароль.']);
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }
}
