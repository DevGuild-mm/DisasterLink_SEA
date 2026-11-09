<?php

namespace App\Http\Requests;

use App\Models\EmergencyResource;
use Illuminate\Foundation\Http\FormRequest;

class StoreEmergencyResourceRequest extends FormRequest
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
            'shelter_id' => ['nullable', 'string', 'exists:shelters,id'],
            'category' => ['required', 'string', 'in:' . implode(',', [
                EmergencyResource::CATEGORY_WATER_FOOD,
                EmergencyResource::CATEGORY_MEDICAL,
                EmergencyResource::CATEGORY_RESCUE_EQUIPMENT,
                EmergencyResource::CATEGORY_NECESSITIES,
            ])],
            'item_name' => ['required', 'string', 'max:200'],
            'quantity' => ['required', 'integer', 'min:0', 'max:1000000'],
            'unit' => ['required', 'string', 'max:50'],
            'min_threshold' => ['required', 'integer', 'min:1', 'max:1000000'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $clean = fn ($val) => is_string($val) ? trim(strip_tags(preg_replace('#<script\b[^>]*>(.*?)</script>#is', '', $val))) : $val;

        $this->merge([
            'item_name' => $clean($this->input('item_name')),
            'unit' => $clean($this->input('unit')),
            'notes' => $clean($this->input('notes')),
        ]);
    }
}
