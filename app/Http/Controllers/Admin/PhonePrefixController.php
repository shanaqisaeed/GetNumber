<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\PhonePrefix;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PhonePrefixController extends Controller
{
    /**
     * Display a listing of system phone prefixes.
     */
    public function index(Request $request): Response
    {
        $prefixes = PhonePrefix::withCount('phoneNumbers')
            ->orderByDesc('is_default')
            ->orderBy('prefix')
            ->get()
            ->map(fn (PhonePrefix $p) => [
                'id' => $p->id,
                'prefix' => $p->prefix,
                'clean_prefix' => $p->clean_prefix,
                'name' => $p->name,
                'country_code' => $p->country_code,
                'is_default' => (bool) $p->is_default,
                'is_active' => (bool) $p->is_active,
                'numbers_count' => (int) $p->phone_numbers_count,
                'created_at' => $p->created_at?->format('Y/m/d H:i'),
            ]);

        $stats = [
            'total' => PhonePrefix::count(),
            'active' => PhonePrefix::where('is_active', true)->count(),
            'default' => PhonePrefix::where('is_default', true)->value('prefix') ?? '+85',
        ];

        return Inertia::render('Admin/Prefixes/Index', [
            'prefixes' => $prefixes,
            'stats' => $stats,
        ]);
    }

    /**
     * Store a newly created phone prefix.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'prefix' => ['required', 'string', 'max:10', 'regex:/^\+?[0-9]{1,8}$/', 'unique:phone_prefixes,prefix'],
            'name' => ['required', 'string', 'max:100'],
            'country_code' => ['nullable', 'string', 'max:10'],
            'is_default' => ['boolean'],
            'is_active' => ['boolean'],
        ], [
            'prefix.required' => 'وارد کردن پیش‌شماره الزامی است.',
            'prefix.regex' => 'قالب پیش‌شماره باید شامل علامت + و ارقام معتبر باشد (مانند +98 یا +85).',
            'prefix.unique' => 'این پیش‌شماره پیش‌تر در سامانه تعریف شده است.',
            'name.required' => 'نام یا عنوان پیش‌شماره الزامی است.',
        ]);

        $prefixStr = trim($validated['prefix']);
        if (! str_starts_with($prefixStr, '+')) {
            $prefixStr = '+' . $prefixStr;
        }

        $cleanPrefix = ltrim($prefixStr, '+');
        $isDefault = (bool) ($validated['is_default'] ?? false);
        $isActive = (bool) ($validated['is_active'] ?? true);

        if ($isDefault) {
            PhonePrefix::where('is_default', true)->update(['is_default' => false]);
            $isActive = true; // Default prefix must be active
        }

        $prefix = PhonePrefix::create([
            'prefix' => $prefixStr,
            'clean_prefix' => $cleanPrefix,
            'name' => trim($validated['name']),
            'country_code' => ! empty($validated['country_code']) ? strtoupper(trim($validated['country_code'])) : 'INTL',
            'is_default' => $isDefault,
            'is_active' => $isActive,
        ]);

        AuditLog::record(
            action: 'prefix_created',
            entity: $prefix,
            actor: $request->user(),
            payload: [
                'prefix' => $prefix->prefix,
                'name' => $prefix->name,
                'is_default' => $prefix->is_default,
                'is_active' => $prefix->is_active,
            ]
        );

        return back()->with('success', "پیش‌شماره سازمانی {$prefix->prefix} با موفقیت ثبت شد.");
    }

    /**
     * Update the specified phone prefix.
     */
    public function update(Request $request, PhonePrefix $prefix): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'country_code' => ['nullable', 'string', 'max:10'],
            'is_default' => ['boolean'],
            'is_active' => ['boolean'],
        ], [
            'name.required' => 'نام یا عنوان پیش‌شماره الزامی است.',
        ]);

        $isDefault = (bool) ($validated['is_default'] ?? $prefix->is_default);
        $isActive = (bool) ($validated['is_active'] ?? $prefix->is_active);

        if ($isDefault && ! $prefix->is_default) {
            PhonePrefix::where('id', '!=', $prefix->id)->update(['is_default' => false]);
            $isActive = true;
        }

        // Cannot deactivate default prefix
        if (! $isActive && $prefix->is_default) {
            return back()->with('error', 'پیش‌شماره پیش‌فرض سامانه نمی‌تواند غیرفعال گردد. ابتدا پیش‌شماره پیش‌فرض دیگری برگزینید.');
        }

        $prefix->update([
            'name' => trim($validated['name']),
            'country_code' => ! empty($validated['country_code']) ? strtoupper(trim($validated['country_code'])) : $prefix->country_code,
            'is_default' => $isDefault,
            'is_active' => $isActive,
        ]);

        AuditLog::record(
            action: 'prefix_updated',
            entity: $prefix,
            actor: $request->user(),
            payload: [
                'prefix' => $prefix->prefix,
                'name' => $prefix->name,
                'is_default' => $prefix->is_default,
                'is_active' => $prefix->is_active,
            ]
        );

        return back()->with('success', "تنظیمات پیش‌شماره {$prefix->prefix} با موفقیت به‌روزرسانی شد.");
    }

    /**
     * Remove the specified phone prefix.
     */
    public function destroy(Request $request, PhonePrefix $prefix): RedirectResponse
    {
        if ($prefix->is_default) {
            return back()->with('error', 'امکان حذف پیش‌شماره پیش‌فرض سامانه وجود ندارد.');
        }

        if ($prefix->phoneNumbers()->exists()) {
            return back()->with('error', "این پیش‌شماره به {$prefix->phoneNumbers()->count()} خط سازمانی متصل است و امکان حذف آن وجود ندارد.");
        }

        $prefixStr = $prefix->prefix;

        AuditLog::record(
            action: 'prefix_deleted',
            entity: $prefix,
            actor: $request->user(),
            payload: [
                'prefix' => $prefix->prefix,
                'name' => $prefix->name,
            ]
        );

        $prefix->delete();

        return back()->with('success', "پیش‌شماره {$prefixStr} با موفقیت از سامانه حذف گردید.");
    }
}
