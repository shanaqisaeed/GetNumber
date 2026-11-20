<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OrgContactController extends Controller
{
    /**
     * Display shared corporate contacts.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $org = $user->getOrCreatePersonalOrganization();

        $contacts = $org->sharedContacts()
            ->latest('id')
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'phone_number' => $c->phone_number,
                'avatar_color' => $c->avatar_color,
                'notes' => $c->notes,
                'created_at' => $c->created_at?->format('Y/m/d'),
            ]);

        return Inertia::render('Organization/Contacts/Index', [
            'contacts' => $contacts,
        ]);
    }

    /**
     * Store a new shared organization contact.
     */
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $org = $user->getOrCreatePersonalOrganization();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone_number' => ['required', 'string', 'max:64'],
            'avatar_color' => ['nullable', 'string', 'max:32'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $phoneOrId = trim($validated['phone_number']);
        if ($request->input('type') === 'custom_id' && ! str_starts_with($phoneOrId, '@')) {
            $phoneOrId = '@' . $phoneOrId;
        }

        $org->sharedContacts()->create([
            'user_id' => $user->id,
            'is_shared' => true,
            'name' => $validated['name'],
            'phone_number' => $phoneOrId,
            'avatar_color' => $validated['avatar_color'] ?? 'indigo',
            'notes' => $validated['notes'] ?? null,
        ]);

        return back()->with('success', __('organization.contacts.contact_saved'));
    }

    /**
     * Delete a shared organization contact.
     */
    public function destroy(Request $request, Contact $contact): RedirectResponse
    {
        $user = $request->user();
        $org = $user->getOrCreatePersonalOrganization();

        if ($contact->organization_id !== $org->id) {
            abort(403);
        }

        $contact->delete();

        return back()->with('success', __('organization.contacts.contact_deleted'));
    }
}
