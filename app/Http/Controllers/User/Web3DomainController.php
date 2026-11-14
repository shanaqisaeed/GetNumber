<?php

namespace App\Http\Controllers\User;

use App\Contracts\Communication\EmailServiceInterface;
use App\Http\Controllers\Controller;
use App\Models\EmailAlias;
use App\Models\EmailMailbox;
use App\Models\Web3Domain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class Web3DomainController extends Controller
{
    public function __construct(
        protected EmailServiceInterface $emailService
    ) {}

    /**
     * Get user's Web3 domains and mailboxes.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $domains = Web3Domain::with(['mailboxes.aliases'])
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->latest('id')
            ->get()
            ->map(fn ($d) => [
                'id' => $d->id,
                'domain_name' => $d->domain_name,
                'tld' => $d->tld,
                'full_domain' => $d->full_domain,
                'tier_badge' => $d->tier_badge,
                'max_sub_emails' => $d->max_sub_emails,
                'mailboxes_count' => $d->mailboxes->count(),
                'mailboxes' => $d->mailboxes->map(fn ($m) => [
                    'id' => $m->id,
                    'address' => $m->address,
                    'display_name' => $m->display_name,
                    'quota_mb' => $m->quota_mb,
                    'used_mb' => $m->used_mb,
                    'aliases' => $m->aliases->map(fn ($a) => [
                        'id' => $a->id,
                        'alias_address' => $a->alias_address,
                        'is_active' => (bool) $a->is_active,
                    ]),
                ]),
            ]);

        return response()->json([
            'domains' => $domains,
        ]);
    }

    /**
     * Create a new mailbox for a Web3 domain.
     */
    public function storeMailbox(Request $request, Web3Domain $web3Domain): JsonResponse
    {
        $user = $request->user();
        if ($web3Domain->user_id !== $user->id) {
            abort(403);
        }

        $validated = $request->validate([
            'local_part' => ['required', 'string', 'min:2', 'max:64', 'regex:/^[a-z0-9._-]+$/i'],
            'display_name' => ['nullable', 'string', 'max:128'],
        ]);

        $mailbox = $this->emailService->createMailbox(
            $user,
            $web3Domain,
            $validated['local_part'],
            $validated['display_name'] ?? null
        );

        return response()->json([
            'success' => true,
            'mailbox' => [
                'id' => $mailbox->id,
                'address' => $mailbox->address,
                'display_name' => $mailbox->display_name,
                'quota_mb' => $mailbox->quota_mb,
                'aliases' => [],
            ],
            'message' => 'صندوق ایمیل سازمانی با موفقیت ایجاد گردید.',
        ]);
    }

    /**
     * Add a sub-email alias to a mailbox.
     */
    public function storeAlias(Request $request, EmailMailbox $mailbox): JsonResponse
    {
        $user = $request->user();
        if ($mailbox->user_id !== $user->id) {
            abort(403);
        }

        $validated = $request->validate([
            'alias' => ['required', 'string', 'min:2', 'max:64', 'regex:/^[a-z0-9._-]+$/i'],
        ]);

        $alias = $this->emailService->createAlias($mailbox, $validated['alias']);

        return response()->json([
            'success' => true,
            'alias' => [
                'id' => $alias->id,
                'alias_address' => $alias->alias_address,
                'is_active' => (bool) $alias->is_active,
            ],
            'message' => 'نام مستعار / ساب‌ایمیل جدید ایجاد شد.',
        ]);
    }

    /**
     * Delete a sub-email alias.
     */
    public function destroyAlias(Request $request, EmailAlias $alias): JsonResponse
    {
        $user = $request->user();
        $mailbox = $alias->mailbox;
        if (! $mailbox || $mailbox->user_id !== $user->id) {
            abort(403);
        }

        $this->emailService->deleteAlias($alias);

        return response()->json([
            'success' => true,
            'message' => 'ساب‌ایمیل با موفقیت حذف گردید.',
        ]);
    }
}

