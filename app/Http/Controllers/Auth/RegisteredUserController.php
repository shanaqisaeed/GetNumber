<?php

namespace App\Http\Controllers\Auth;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Inertia\Inertia;
use Inertia\Response;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/Register');
    }

    /**
     * Handle an incoming registration request.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'min:3', 'max:50', 'alpha_dash', 'unique:'.User::class.',username'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ], [
            'name.required' => 'ورود عنوان سازمانی / نام متقاضی الزامی است.',
            'username.required' => 'ورود شناسه کاربری الزامی است.',
            'username.min' => 'شناسه کاربری باید حداقل ۳ نویسه باشد.',
            'username.max' => 'شناسه کاربری نمی‌تواند بیش از ۵۰ نویسه باشد.',
            'username.alpha_dash' => 'شناسه کاربری فقط می‌تواند شامل حروف انگلیسی، ارقام، خط تیره (-) و زیرخط (_) باشد.',
            'username.unique' => 'این شناسه کاربری قبلاً در سامانه ثبت شده است.',
            'password.required' => 'ورود کلمه عبور الزامی است.',
            'password.confirmed' => 'تکرار کلمه عبور با مقدار کلمه عبور مطابقت ندارد.',
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'username' => strtolower($validated['username']),
            'password' => Hash::make($validated['password']),
            'role' => UserRole::User,
            'status' => UserStatus::Active,
        ]);

        event(new Registered($user));

        Auth::login($user);

        return redirect(route('dashboard', absolute: false));
    }
}
