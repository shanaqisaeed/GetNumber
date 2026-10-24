<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
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
        $query = User::withCount('phoneNumbers')->with(['phoneNumbers' => function ($q) {
            $q->select('id', 'user_id', 'number', 'type', 'status', 'display_title', 'allocated_at')
              ->latest('id');
        }]);

        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $query->where(function ($q) use ($search) {
                if (is_numeric($search)) {
                    $q->where('id', (int) $search);
                }
                $q->orWhere('name', 'like', "%{$search}%")
                  ->orWhere('username', 'like', "%{$search}%")
                  ->orWhereHas('phoneNumbers', function ($pq) use ($search) {
                      $pq->where('number', 'like', "%{$search}%");
                  });
            });
        }

        if ($request->filled('role')) {
            $query->where('role', $request->input('role'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('has_numbers')) {
            if ($request->input('has_numbers') === 'yes') {
                $query->has('phoneNumbers');
            } elseif ($request->input('has_numbers') === 'no') {
                $query->doesntHave('phoneNumbers');
            }
        }

        // Sorting
        $sort = $request->input('sort', 'newest');
        match ($sort) {
            'oldest' => $query->oldest('id'),
            'name' => $query->orderBy('name', 'asc'),
            'username' => $query->orderBy('username', 'asc'),
            'numbers_count' => $query->orderBy('phone_numbers_count', 'desc'),
            default => $query->latest('id'),
        };

        $users = $query->paginate(15)
            ->withQueryString()
            ->through(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'username' => $u->username,
                'role' => $u->role->value,
                'status' => $u->status->value,
                'created_at' => $u->created_at->format('Y/m/d H:i'),
                'phone_numbers_count' => $u->phone_numbers_count,
                'phone_numbers' => $u->phoneNumbers->map(fn ($p) => [
                    'id' => $p->id,
                    'number' => $p->number,
                    'formatted_number' => $p->formatted_number,
                    'type' => $p->type->value,
                    'type_label' => $p->type->label(),
                    'type_badge' => $p->type->badgeClasses(),
                    'status' => $p->status->value,
                    'status_label' => $p->status->label(),
                    'status_badge' => $p->status->badgeClasses(),
                    'display_title' => $p->display_title,
                    'allocated_at' => $p->allocated_at?->format('Y/m/d H:i'),
                ]),
            ]);

        $stats = [
            'total' => User::count(),
            'active' => User::where('status', UserStatus::Active)->count(),
            'suspended' => User::where('status', UserStatus::Suspended)->count(),
            'admins' => User::where('role', UserRole::Admin)->count(),
        ];

        return Inertia::render('Admin/Users/Index', [
            'users' => $users,
            'filters' => $request->only(['search', 'role', 'status', 'has_numbers', 'sort']),
            'stats' => $stats,
            'roles' => [
                ['value' => UserRole::User->value, 'label' => UserRole::User->label()],
                ['value' => UserRole::Agent->value, 'label' => UserRole::Agent->label()],
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
                ['value' => UserRole::Agent->value, 'label' => UserRole::Agent->label()],
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

        $newUser = User::create([
            'name' => $validated['name'],
            'username' => strtolower($validated['username']),
            'password' => Hash::make($validated['password']),
            'role' => $validated['role'],
            'status' => $validated['status'],
        ]);

        AuditLog::record('user_created', $newUser, $request->user(), [
            'username' => $newUser->username,
            'name' => $newUser->name,
            'role' => $newUser->role->value,
            'status' => $newUser->status->value,
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

        $fromStatus = $user->status->value;

        $user->update([
            'status' => $validated['status'],
        ]);

        AuditLog::record('user_status_changed', $user, $request->user(), [
            'username' => $user->username,
            'from_status' => $fromStatus,
            'to_status' => $validated['status'],
        ]);

        return back()->with('success', 'وضعیت حساب کاربری با موفقیت به‌روزرسانی شد.');
    }

    /**
     * Update the specified user identity, role, status and credentials.
     */
    public function update(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => [
                'required',
                'string',
                'min:3',
                'max:50',
                'alpha_dash',
                Rule::unique('users', 'username')->ignore($user->id),
            ],
            'role' => ['required', Rule::enum(UserRole::class)],
            'status' => ['required', Rule::enum(UserStatus::class)],
            'password' => ['nullable', 'string', 'min:6'],
        ], [
            'name.required' => 'ورود عنوان یا نام کاربر الزامی است.',
            'username.required' => 'ورود شناسه کاربری الزامی است.',
            'username.unique' => 'این شناسه کاربری قبلاً در سامانه ثبت شده است.',
            'username.alpha_dash' => 'شناسه کاربری فقط می‌تواند شامل حروف انگلیسی، اعداد، خط تیره و زیرخط باشد.',
            'role.required' => 'تعیین نقش کاربری الزامی است.',
            'status.required' => 'تعیین وضعیت حساب کاربری الزامی است.',
            'password.min' => 'کلمه عبور جدید باید حداقل ۶ کاراکتر باشد.',
        ]);

        if ($user->id === $request->user()->id) {
            if ($validated['role'] !== UserRole::Admin->value) {
                return back()->with('error', 'امکان حذف نقش راهبری حساب شخصی توسط خود کاربر مجاز نمی‌باشد.');
            }
            if ($validated['status'] !== UserStatus::Active->value) {
                return back()->with('error', 'امکان غیرفعال‌سازی یا تعلیق حساب کاربری شخصی توسط خود کاربر مجاز نمی‌باشد.');
            }
        }

        $updateData = [
            'name' => $validated['name'],
            'username' => strtolower($validated['username']),
            'role' => $validated['role'],
            'status' => $validated['status'],
        ];

        if (!empty($validated['password'])) {
            $updateData['password'] = Hash::make($validated['password']);
        }

        $oldData = [
            'name' => $user->name,
            'username' => $user->username,
            'role' => $user->role->value,
            'status' => $user->status->value,
        ];

        $user->update($updateData);

        AuditLog::record('user_updated', $user, $request->user(), [
            'username' => $user->username,
            'previous' => $oldData,
            'updated' => [
                'name' => $user->name,
                'role' => $user->role->value,
                'status' => $user->status->value,
                'password_changed' => !empty($validated['password']),
            ],
        ]);

        return back()->with('success', "مشخصات حساب کاربری «{$user->username}» با موفقیت به‌روزرسانی شد.");
    }

    /**
     * Get the list of numbers allocated to a specific user.
     */
    public function numbers(User $user): \Illuminate\Http\JsonResponse
    {
        $numbers = $user->phoneNumbers()
            ->latest('id')
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'number' => $p->number,
                'formatted_number' => $p->formatted_number,
                'type' => $p->type->value,
                'type_label' => $p->type->label(),
                'type_badge' => $p->type->badgeClasses(),
                'status' => $p->status->value,
                'status_label' => $p->status->label(),
                'status_badge' => $p->status->badgeClasses(),
                'display_title' => $p->display_title,
                'allocated_at' => $p->allocated_at?->format('Y/m/d H:i'),
            ]);

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
            ],
            'numbers' => $numbers,
        ]);
    }

    /**
     * Search users by ID, username or name (for async pickers).
     */
    public function search(Request $request): \Illuminate\Http\JsonResponse
    {
        $query = trim($request->input('q', ''));

        if ($query === '') {
            return response()->json([]);
        }

        $users = User::query()
            ->where(function ($q) use ($query) {
                if (is_numeric($query)) {
                    $q->where('id', (int) $query);
                }
                $q->orWhere('username', 'like', "%{$query}%")
                  ->orWhere('name', 'like', "%{$query}%");
            })
            ->select('id', 'name', 'username', 'role', 'status')
            ->limit(20)
            ->get();

        return response()->json($users);
    }
}

