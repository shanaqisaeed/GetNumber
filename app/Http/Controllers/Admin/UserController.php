<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    /**
     * Display a listing of the users.
     */
    public function index(Request $request): Response
    {
        $query = User::query();

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('username', 'like', "%{$search}%");
            });
        }

        if ($request->filled('role')) {
            $query->where('role', $request->input('role'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $users = $query->latest()
            ->paginate(10)
            ->withQueryString();

        return Inertia::render('Admin/Users/Index', [
            'users' => $users,
            'filters' => $request->only(['search', 'role', 'status']),
            'roles' => [
                ['value' => UserRole::User->value, 'label' => UserRole::User->label()],
                ['value' => UserRole::Admin->value, 'label' => UserRole::Admin->label()],
            ],
            'statuses' => [
                ['value' => UserStatus::Active->value, 'label' => UserStatus::Active->label()],
                ['value' => UserStatus::Suspended->value, 'label' => UserStatus::Suspended->label()],
                ['value' => UserStatus::Inactive->value, 'label' => UserStatus::Inactive->label()],
            ],
        ]);
    }

    /**
     * Show the form for creating a new user.
     */
    public function create(): Response
    {
        return Inertia::render('Admin/Users/Create', [
            'roles' => [
                ['value' => UserRole::User->value, 'label' => UserRole::User->label()],
                ['value' => UserRole::Admin->value, 'label' => UserRole::Admin->label()],
            ],
            'statuses' => [
                ['value' => UserStatus::Active->value, 'label' => UserStatus::Active->label()],
                ['value' => UserStatus::Suspended->value, 'label' => UserStatus::Suspended->label()],
                ['value' => UserStatus::Inactive->value, 'label' => UserStatus::Inactive->label()],
            ],
        ]);
    }

    /**
     * Store a newly created user in storage.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'min:3', 'max:50', 'alpha_dash', 'unique:users,username'],
            'password' => ['required', 'string', Password::defaults()],
            'role' => ['required', Rule::enum(UserRole::class)],
            'status' => ['required', Rule::enum(UserStatus::class)],
        ], [
            'name.required' => 'ورود عنوان یا نام کاربر الزامی است.',
            'username.required' => 'ورود شناسه کاربری الزامی است.',
            'username.unique' => 'این شناسه کاربری قبلاً در سامانه ثبت شده است.',
            'password.required' => 'تعیین کلمه عبور اولیه الزامی است.',
            'role.required' => 'تعیین نقش کاربری الزامی است.',
            'status.required' => 'تعیین وضعیت حساب کاربری الزامی است.',
        ]);

        User::create([
            'name' => $validated['name'],
            'username' => strtolower($validated['username']),
            'password' => Hash::make($validated['password']),
            'role' => $validated['role'],
            'status' => $validated['status'],
        ]);

        return redirect()->route('admin.users.index')->with('success', 'حساب کاربری جدید با موفقیت ایجاد گردید.');
    }

    /**
     * Update the specified user status.
     */
    public function updateStatus(Request $request, User $user): RedirectResponse
    {
        if ($user->id === $request->user()->id) {
            return back()->with('error', 'امکان تغییر وضعیت حساب کاربری شخصی توسط خود کاربر مجاز نمی‌باشد.');
        }

        $validated = $request->validate([
            'status' => ['required', Rule::enum(UserStatus::class)],
        ]);

        $user->update([
            'status' => $validated['status'],
        ]);

        return back()->with('success', 'وضعیت حساب کاربری با موفقیت به‌روزرسانی شد.');
    }
}
