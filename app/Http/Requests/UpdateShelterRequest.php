<?php

namespace App\Http\Requests;

use App\Models\Shelter;
use Illuminate\Foundation\Http\FormRequest;

class UpdateShelterRequest extends FormRequest
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
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'type' => ['sometimes', 'required', 'string', 'in:' . implode(',', [Shelter::TYPE_SHELTER, Shelter::TYPE_HOSPITAL, Shelter::TYPE_RELIEF_CENTER, Shelter::TYPE_EVACUATION_CENTER])],
            'country' => ['sometimes', 'required', 'string', 'max:100'],
            'city' => ['sometimes', 'required', 'string', 'max:100'],
            'address' => ['sometimes', 'required', 'string', 'max:255'],
            'latitude' => ['sometimes', 'required', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'required', 'numeric', 'between:-180,180'],
            'contact_name' => ['sometimes', 'required', 'string', 'max:150'],
            'contact_phone' => ['sometimes', 'required', 'string', 'max:50'],
            'contact_email' => ['nullable', 'email', 'max:100'],
            'total_capacity' => ['sometimes', 'required', 'integer', 'min:1', 'max:100000'],
            'current_occupancy' => ['sometimes', 'required', 'integer', 'min:0'],
            'status' => ['sometimes', 'required', 'string', 'in:' . implode(',', [Shelter::STATUS_OPEN, Shelter::STATUS_FULL, Shelter::STATUS_CLOSED, Shelter::STATUS_EVACUATING])],
            'facilities' => ['nullable', 'array'],
            'facilities.*' => ['string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $clean = fn ($val) => is_string($val) ? trim(strip_tags(preg_replace('#<script\b[^>]*>(.*?)</script>#is', '', $val))) : $val;

        $input = [];
        if ($this->has('name')) $input['name'] = $clean($this->input('name'));
        if ($this->has('address')) $input['address'] = $clean($this->input('address'));
        if ($this->has('contact_name')) $input['contact_name'] = $clean($this->input('contact_name'));
        if ($this->has('contact_phone')) $input['contact_phone'] = $clean($this->input('contact_phone'));
        if ($this->has('notes')) $input['notes'] = $clean($this->input('notes'));

        $this->merge($input);
    }
}
