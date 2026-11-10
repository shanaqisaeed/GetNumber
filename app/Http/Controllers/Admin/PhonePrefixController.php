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
                'country_name' => $p->country_name,
                'country_code' => $p->country_code,
                'flag' => $p->flag,
                'sub_prefixes' => $p->sub_prefixes ?? [],
                'price_regular' => (string) ($p->price_regular ?? '1.0000'),
                'price_semi_rond' => (string) ($p->price_semi_rond ?? '5.0000'),
                'price_golden_rond' => (string) ($p->price_golden_rond ?? '20.0000'),
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
            'country_name' => ['nullable', 'string', 'max:100'],
            'country_code' => ['nullable', 'string', 'max:10'],
            'flag' => ['nullable', 'string', 'max:32'],
            'sub_prefixes' => ['nullable'],
            'price_regular' => ['nullable', 'numeric', 'min:0'],
            'price_semi_rond' => ['nullable', 'numeric', 'min:0'],
            'price_golden_rond' => ['nullable', 'numeric', 'min:0'],
            'is_default' => ['boolean'],
            'is_active' => ['boolean'],
        ], [
            'prefix.required' => __('controllers.admin.phone_prefixes.val_prefix_required'),
            'prefix.regex' => __('controllers.admin.phone_prefixes.val_prefix_regex'),
            'prefix.unique' => __('controllers.admin.phone_prefixes.val_prefix_unique'),
            'name.required' => __('controllers.admin.phone_prefixes.val_name_required'),
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

        $subPrefixes = $this->parseSubPrefixes($request->input('sub_prefixes'));

        $prefix = PhonePrefix::create([
            'prefix' => $prefixStr,
            'clean_prefix' => $cleanPrefix,
            'name' => trim($validated['name']),
            'country_name' => ! empty($validated['country_name']) ? trim($validated['country_name']) : trim($validated['name']),
            'country_code' => ! empty($validated['country_code']) ? strtoupper(trim($validated['country_code'])) : 'INTL',
            'flag' => ! empty($validated['flag']) ? trim($validated['flag']) : '🌐',
            'sub_prefixes' => $subPrefixes,
            'price_regular' => $validated['price_regular'] ?? '1.0000',
            'price_semi_rond' => $validated['price_semi_rond'] ?? '5.0000',
            'price_golden_rond' => $validated['price_golden_rond'] ?? '20.0000',
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

        return back()->with('success', __('controllers.admin.phone_prefixes.created_flash', ['prefix' => $prefix->prefix]));
    }

    /**
     * Update the specified phone prefix.
     */
    public function update(Request $request, PhonePrefix $prefix): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'country_name' => ['nullable', 'string', 'max:100'],
            'country_code' => ['nullable', 'string', 'max:10'],
            'flag' => ['nullable', 'string', 'max:32'],
            'sub_prefixes' => ['nullable'],
            'price_regular' => ['nullable', 'numeric', 'min:0'],
            'price_semi_rond' => ['nullable', 'numeric', 'min:0'],
            'price_golden_rond' => ['nullable', 'numeric', 'min:0'],
            'is_default' => ['boolean'],
            'is_active' => ['boolean'],
        ], [
            'name.required' => __('controllers.admin.phone_prefixes.val_name_required'),
        ]);

        $isDefault = (bool) ($validated['is_default'] ?? $prefix->is_default);
        $isActive = (bool) ($validated['is_active'] ?? $prefix->is_active);

        if ($isDefault && ! $prefix->is_default) {
            PhonePrefix::where('id', '!=', $prefix->id)->update(['is_default' => false]);
            $isActive = true;
        }

        // Cannot deactivate default prefix
        if (! $isActive && $prefix->is_default) {
            return back()->with('error', __('controllers.admin.phone_prefixes.cannot_deactivate_default'));
        }

        $subPrefixes = $request->has('sub_prefixes')
            ? $this->parseSubPrefixes($request->input('sub_prefixes'))
            : $prefix->sub_prefixes;

        $prefix->update([
            'name' => trim($validated['name']),
            'country_name' => ! empty($validated['country_name']) ? trim($validated['country_name']) : $prefix->country_name,
            'country_code' => ! empty($validated['country_code']) ? strtoupper(trim($validated['country_code'])) : $prefix->country_code,
            'flag' => ! empty($validated['flag']) ? trim($validated['flag']) : $prefix->flag,
            'sub_prefixes' => $subPrefixes,
            'price_regular' => $validated['price_regular'] ?? $prefix->price_regular,
            'price_semi_rond' => $validated['price_semi_rond'] ?? $prefix->price_semi_rond,
            'price_golden_rond' => $validated['price_golden_rond'] ?? $prefix->price_golden_rond,
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

        return back()->with('success', __('controllers.admin.phone_prefixes.updated_flash', ['prefix' => $prefix->prefix]));
    }

    /**
     * Parse sub-prefixes input into array of strings.
     */
    protected function parseSubPrefixes(mixed $input): array
    {
        if (is_array($input)) {
            return array_values(array_filter(array_map('trim', $input)));
        }

        if (is_string($input)) {
            $parts = preg_split('/[,\s]+/', $input);

            return array_values(array_filter(array_map('trim', $parts ?: [])));
        }

        return [];
    }

    /**
     * Remove the specified phone prefix.
     */
    public function destroy(Request $request, PhonePrefix $prefix): RedirectResponse
    {
        if ($prefix->is_default) {
            return back()->with('error', __('controllers.admin.phone_prefixes.cannot_delete_default'));
        }

        if ($prefix->phoneNumbers()->exists()) {
            return back()->with('error', __('controllers.admin.phone_prefixes.has_lines_connected', ['count' => $prefix->phoneNumbers()->count()]));
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

        return back()->with('success', __('controllers.admin.phone_prefixes.deleted_flash', ['prefix' => $prefixStr]));
    }
}