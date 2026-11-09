<?php

namespace App\Http\Requests;

use App\Models\DisasterReport;
use App\Services\Disaster\DisasterDashboardService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDisasterReportRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Clean text input by stripping script contents and HTML tags.
     */
    private function sanitizeInput(?string $value): string
    {
        if ($value === null) {
            return '';
        }
        $stripped = preg_replace('#<script\b[^>]*>(.*?)</script>#is', '', $value);
        return trim(strip_tags((string) $stripped));
    }

    /**
     * Prepare the data for validation with sanitization.
     */
    protected function prepareForValidation(): void
    {
        $sanitized = [];
        if ($this->has('title')) {
            $sanitized['title'] = $this->sanitizeInput($this->input('title'));
        }
        if ($this->has('description')) {
            $sanitized['description'] = $this->sanitizeInput($this->input('description'));
        }
        if ($this->has('location')) {
            $sanitized['location'] = $this->sanitizeInput($this->input('location'));
        }

        if (!empty($sanitized)) {
            $this->merge($sanitized);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $validCountries = array_keys(DisasterDashboardService::ASEAN_COUNTRIES);

        return [
            'disaster_type' => ['required', 'string', Rule::in(DisasterReport::DISASTER_TYPES)],
            'title' => ['required', 'string', 'min:5', 'max:150'],
            'description' => ['required', 'string', 'min:10', 'max:3000'],
            'country' => ['required', 'string', Rule::in($validCountries)],
            'location' => ['required', 'string', 'min:3', 'max:255'],
            'incident_time' => ['required', 'date', 'before_or_equal:now'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'photos' => ['nullable', 'array', 'max:3'],
            'photos.*' => ['file', 'image', 'mimes:jpeg,jpg,png,webp', 'max:5120'],
        ];
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'disaster_type' => 'disaster category',
            'incident_time' => 'incident date & time',
            'photos.*' => 'photo file',
        ];
    }

    /**
     * Custom validation messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'country.in' => 'Please select one of the 11 official ASEAN member countries.',
            'incident_time.before_or_equal' => 'The incident date & time cannot be in the future.',
            'photos.max' => 'You may upload a maximum of 3 photos.',
            'photos.*.max' => 'Each uploaded photo must not exceed 5MB in size.',
            'photos.*.mimes' => 'Each photo must be an image of type: JPG, JPEG, PNG, or WebP.',
        ];
    }
}
