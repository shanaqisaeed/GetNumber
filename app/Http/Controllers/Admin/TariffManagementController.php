<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\PhonePrefix;
use App\Models\SystemSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TariffManagementController extends Controller
{
    /**
     * Display tariffs management dashboard.
     */
    public function index(): Response
    {
        $prefixes = PhonePrefix::orderBy('id')->get()->map(fn ($p) => [
            'id' => $p->id,
            'prefix' => $p->prefix,
            'clean_prefix' => $p->clean_prefix,
            'name' => $p->name,
            'country_name' => $p->country_name,
            'flag' => $p->flag,
            'is_default' => (bool) $p->is_default,
            'is_active' => (bool) $p->is_active,
            'sms_intra_prefix_rate' => (string) ($p->sms_intra_prefix_rate ?? $p->sms_price_per_segment ?? '0.0500'),
            'sms_inter_prefix_rate' => (string) ($p->sms_inter_prefix_rate ?? '0.1200'),
            'voice_intra_prefix_rate' => (string) ($p->voice_intra_prefix_rate ?? '0.1000'),
            'voice_inter_prefix_rate' => (string) ($p->voice_inter_prefix_rate ?? '0.2500'),
        ]);

        $globalSettings = [
            'id_sms_rate' => (string) SystemSetting::get('id_sms_rate', '0.0200'),
            'id_voice_rate_per_minute' => (string) SystemSetting::get('id_voice_rate_per_minute', '0.0500'),
            'sms_intra_prefix_rate' => (string) SystemSetting::get('sms_intra_prefix_rate', '0.0500'),
            'sms_inter_prefix_rate' => (string) SystemSetting::get('sms_inter_prefix_rate', '0.1200'),
            'voice_intra_prefix_rate' => (string) SystemSetting::get('voice_intra_prefix_rate', '0.1000'),
            'voice_inter_prefix_rate' => (string) SystemSetting::get('voice_inter_prefix_rate', '0.2500'),
        ];

        return Inertia::render('Admin/Tariffs/Index', [
            'prefixes' => $prefixes,
            'global_settings' => $globalSettings,
        ]);
    }

    /**
     * Update tariff rates for a specific phone prefix.
     */
    public function updatePrefix(Request $request, PhonePrefix $prefix): RedirectResponse
    {
        $validated = $request->validate([
            'sms_intra_prefix_rate' => ['required', 'numeric', 'min:0'],
            'sms_inter_prefix_rate' => ['required', 'numeric', 'min:0'],
            'voice_intra_prefix_rate' => ['required', 'numeric', 'min:0'],
            'voice_inter_prefix_rate' => ['required', 'numeric', 'min:0'],
        ]);

        $oldRates = [
            'sms_intra_prefix_rate' => $prefix->sms_intra_prefix_rate,
            'sms_inter_prefix_rate' => $prefix->sms_inter_prefix_rate,
            'voice_intra_prefix_rate' => $prefix->voice_intra_prefix_rate,
            'voice_inter_prefix_rate' => $prefix->voice_inter_prefix_rate,
        ];

        $prefix->update([
            'sms_intra_prefix_rate' => $validated['sms_intra_prefix_rate'],
            'sms_price_per_segment' => $validated['sms_intra_prefix_rate'],
            'sms_inter_prefix_rate' => $validated['sms_inter_prefix_rate'],
            'voice_intra_prefix_rate' => $validated['voice_intra_prefix_rate'],
            'voice_inter_prefix_rate' => $validated['voice_inter_prefix_rate'],
        ]);

        AuditLog::record('prefix_tariffs_updated', $prefix, $request->user(), [
            'prefix' => $prefix->prefix,
            'old' => $oldRates,
            'new' => $validated,
        ]);

        return back()->with('success', "تعرفه‌های سرشماره {$prefix->prefix} با موفقیت به‌روزرسانی شد.");
    }

    /**
     * Update global communications and ID-to-ID tariffs.
     */
    public function updateGlobal(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'id_sms_rate' => ['required', 'numeric', 'min:0'],
            'id_voice_rate_per_minute' => ['required', 'numeric', 'min:0'],
            'sms_intra_prefix_rate' => ['required', 'numeric', 'min:0'],
            'sms_inter_prefix_rate' => ['required', 'numeric', 'min:0'],
            'voice_intra_prefix_rate' => ['required', 'numeric', 'min:0'],
            'voice_inter_prefix_rate' => ['required', 'numeric', 'min:0'],
        ]);

        foreach ($validated as $key => $value) {
            SystemSetting::set($key, $value);
        }

        AuditLog::record('global_tariffs_updated', null, $request->user(), $validated);

        return back()->with('success', 'تعرفه‌های سراسری ارتباطات و آیدی به آیدی با موفقیت ثبت شد.');
    }
}

