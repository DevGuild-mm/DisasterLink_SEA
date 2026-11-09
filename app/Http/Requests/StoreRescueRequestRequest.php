<?php

namespace App\Http\Requests;

use App\Models\RescueRequest;
use Illuminate\Foundation\Http\FormRequest;

class StoreRescueRequestRequest extends FormRequest
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
            'reporter_name' => ['required', 'string', 'max:150'],
            'contact_phone' => ['required', 'string', 'max:50'],
            'country' => ['required', 'string', 'max:100'],
            'location' => ['required', 'string', 'max:255'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'people_count' => ['required', 'integer', 'min:1', 'max:5000'],
            'has_medical_emergency' => ['required', 'boolean'],
            'urgency' => ['required', 'string', 'in:' . implode(',', [
                RescueRequest::URGENCY_CRITICAL,
                RescueRequest::URGENCY_HIGH,
                RescueRequest::URGENCY_MODERATE,
                RescueRequest::URGENCY_LOW,
            ])],
            'situation_description' => ['required', 'string', 'max:3000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $clean = fn ($val) => is_string($val) ? trim(strip_tags(preg_replace('#<script\b[^>]*>(.*?)</script>#is', '', $val))) : $val;

        $this->merge([
            'reporter_name' => $clean($this->input('reporter_name')),
            'contact_phone' => $clean($this->input('contact_phone')),
            'country' => $clean($this->input('country')),
            'location' => $clean($this->input('location')),
            'situation_description' => $clean($this->input('situation_description')),
            'has_medical_emergency' => filter_var($this->input('has_medical_emergency'), FILTER_VALIDATE_BOOLEAN),
        ]);
    }
}
