<?php

namespace App\Http\Requests;

use App\Models\Shelter;
use Illuminate\Foundation\Http\FormRequest;

class StoreShelterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'in:' . implode(',', [Shelter::TYPE_SHELTER, Shelter::TYPE_HOSPITAL, Shelter::TYPE_RELIEF_CENTER, Shelter::TYPE_EVACUATION_CENTER])],
            'country' => ['required', 'string', 'max:100'],
            'city' => ['required', 'string', 'max:100'],
            'address' => ['required', 'string', 'max:255'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'contact_name' => ['required', 'string', 'max:150'],
            'contact_phone' => ['required', 'string', 'max:50'],
            'contact_email' => ['nullable', 'email', 'max:100'],
            'total_capacity' => ['required', 'integer', 'min:1', 'max:100000'],
            'current_occupancy' => ['required', 'integer', 'min:0', 'lte:total_capacity'],
            'status' => ['required', 'string', 'in:' . implode(',', [Shelter::STATUS_OPEN, Shelter::STATUS_FULL, Shelter::STATUS_CLOSED, Shelter::STATUS_EVACUATING])],
            'facilities' => ['nullable', 'array'],
            'facilities.*' => ['string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $clean = fn ($val) => is_string($val) ? trim(strip_tags(preg_replace('#<script\b[^>]*>(.*?)</script>#is', '', $val))) : $val;

        $this->merge([
            'name' => $clean($this->input('name')),
            'country' => $clean($this->input('country')),
            'city' => $clean($this->input('city')),
            'address' => $clean($this->input('address')),
            'contact_name' => $clean($this->input('contact_name')),
            'contact_phone' => $clean($this->input('contact_phone')),
            'notes' => $clean($this->input('notes')),
        ]);
    }
}
