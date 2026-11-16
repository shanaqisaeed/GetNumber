<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ContactController extends Controller
{
    /**
     * Display the dedicated contacts directory page.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $query = Contact::where('user_id', $user->id);

        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('phone_number', 'like', "%{$search}%")
                  ->orWhere('notes', 'like', "%{$search}%");
            });
        }

        $contacts = $query->orderBy('name')
            ->paginate(20)
            ->withQueryString()
            ->through(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'phone_number' => $c->phone_number,
                'avatar_color' => $c->avatar_color ?: 'indigo',
                'notes' => $c->notes,
                'is_custom_id' => str_starts_with($c->phone_number, '@') || ! preg_match('/^\+?[0-9]+$/', $c->phone_number),
                'created_at' => $c->created_at?->format('Y/m/d H:i'),
            ]);

        $stats = [
            'total' => Contact::where('user_id', $user->id)->count(),
            'phone_lines' => Contact::where('user_id', $user->id)
                ->where('phone_number', 'not like', '@%')
                ->whereRaw('phone_number REGEXP "^[0-9+]+$"')
                ->count(),
            'custom_ids' => Contact::where('user_id', $user->id)
                ->where(function ($q) {
                    $q->where('phone_number', 'like', '@%')
                      ->orWhereRaw('phone_number NOT REGEXP "^[0-9+]+$"');
                })
                ->count(),
        ];

        return Inertia::render('User/Contacts/Index', [
            'contacts' => $contacts,
            'filters' => $request->only(['search']),
            'stats' => $stats,
        ]);
    }

    /**
     * Store a newly created contact.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone_number' => ['required', 'string', 'max:64'],
            'avatar_color' => ['nullable', 'string', 'in:indigo,emerald,blue,amber,rose,purple'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $request->user()->contacts()->create([
            'name' => trim($validated['name']),
            'phone_number' => trim($validated['phone_number']),
            'avatar_color' => $validated['avatar_color'] ?? 'indigo',
            'notes' => isset($validated['notes']) ? trim($validated['notes']) : null,
        ]);

        return back()->with('success', __('controllers.user.contacts.created'));
    }

    /**
     * Update an existing contact.
     */
    public function update(Request $request, Contact $contact): RedirectResponse
    {
        if ($contact->user_id !== $request->user()->id) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone_number' => ['required', 'string', 'max:64'],
            'avatar_color' => ['nullable', 'string', 'in:indigo,emerald,blue,amber,rose,purple'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $contact->update([
            'name' => trim($validated['name']),
            'phone_number' => trim($validated['phone_number']),
            'avatar_color' => $validated['avatar_color'] ?? $contact->avatar_color,
            'notes' => isset($validated['notes']) ? trim($validated['notes']) : null,
        ]);

        return back()->with('success', __('controllers.user.contacts.updated'));
    }

    /**
     * Delete a contact.
     */
    public function destroy(Request $request, Contact $contact): RedirectResponse
    {
        if ($contact->user_id !== $request->user()->id) {
            abort(403);
        }

        $contact->delete();

        return back()->with('success', __('controllers.user.contacts.deleted'));
    }

    /**
     * Lightweight JSON picker endpoint for Calls and Messages modal selectors.
     */
    public function pickerJson(Request $request): JsonResponse
    {
        $contacts = Contact::where('user_id', $request->user()->id)
            ->orderBy('name')
            ->get(['id', 'name', 'phone_number', 'avatar_color', 'notes'])
            ->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'phone_number' => $c->phone_number,
                'avatar_color' => $c->avatar_color ?: 'indigo',
                'notes' => $c->notes,
                'is_custom_id' => str_starts_with($c->phone_number, '@') || ! preg_match('/^\+?[0-9]+$/', $c->phone_number),
            ]);

        return response()->json([
            'success' => true,
            'contacts' => $contacts,
            'count' => $contacts->count(),
        ]);
    }
}

