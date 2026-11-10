<?php

namespace App\Http\Requests\Admin;

use App\Models\SafetyGuide;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SafetyGuideRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isAdmin();
    }

    /**
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'hazard_type' => ['required', Rule::in(SafetyGuide::HAZARD_TYPES)],
            'country_id' => ['nullable', 'integer', Rule::exists('countries', 'id')->where('is_active', true)],
            'summary' => ['required', 'string', 'max:500'],
            'content' => ['required', 'string', 'max:20000'],
            'is_published' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ];
    }
}
