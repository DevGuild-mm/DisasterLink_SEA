<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\EmergencyContactRequest;
use App\Models\EmergencyContact;
use Illuminate\Http\RedirectResponse;

class EmergencyContactAdminController extends Controller
{
    public function store(EmergencyContactRequest $request): RedirectResponse
    {
        EmergencyContact::create($this->payload($request));

        return back()->with('success', 'Emergency contact created.');
    }

    public function update(EmergencyContactRequest $request, EmergencyContact $contact): RedirectResponse
    {
        $contact->update($this->payload($request));

        return back()->with('success', 'Emergency contact updated.');
    }

    public function destroy(EmergencyContact $contact): RedirectResponse
    {
        $contact->delete();

        return back()->with('success', 'Emergency contact deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(EmergencyContactRequest $request): array
    {
        $data = $request->validated();
        $data['is_published'] = (bool) ($data['is_published'] ?? false);
        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);

        return $data;
    }
}
