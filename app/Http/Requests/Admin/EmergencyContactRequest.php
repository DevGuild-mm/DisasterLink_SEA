<?php

namespace App\Http\Requests\Admin;

use App\Models\EmergencyContact;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EmergencyContactRequest extends FormRequest
{
    /** Digits and the punctuation of a phone number or short code (for example 112, +66 2 123 4567, *911). */
    private const PHONE = '/^[0-9+*#()\-.\s]{2,50}$/';

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
            'country_id' => ['required', 'integer', Rule::exists('countries', 'id')->where('is_active', true)],
            'agency_name' => ['required', 'string', 'max:255'],
            'category' => ['required', Rule::in(EmergencyContact::CATEGORIES)],
            'phone' => ['required', 'string', 'regex:'.self::PHONE],
            'alt_phone' => ['nullable', 'string', 'regex:'.self::PHONE],
            'website' => ['nullable', 'url:https', 'max:500'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'is_published' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ];
    }
}
