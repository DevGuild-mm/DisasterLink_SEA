<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreVolunteerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:150'],
            'phone' => ['required', 'string', 'max:50'],
            'country' => ['required', 'string', 'max:100'],
            'city' => ['required', 'string', 'max:100'],
            'skills' => ['required', 'array', 'min:1'],
            'skills.*' => ['string', 'max:100'],
            'availability_status' => ['nullable', 'string', 'in:available,deployed,standby'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $clean = fn ($val) => is_string($val) ? trim(strip_tags(preg_replace('#<script\b[^>]*>(.*?)</script>#is', '', $val))) : $val;

        $this->merge([
            'name' => $clean($this->input('name')),
            'email' => strtolower(trim((string) $this->input('email'))),
            'phone' => $clean($this->input('phone')),
            'country' => $clean($this->input('country')),
            'city' => $clean($this->input('city')),
            'notes' => $clean($this->input('notes')),
        ]);
    }
}
