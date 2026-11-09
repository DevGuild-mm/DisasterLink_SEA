<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreResourceDistributionRequest extends FormRequest
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
            'recipient' => ['required', 'string', 'max:255'],
            'quantity' => ['required', 'integer', 'min:1'],
            'distributed_by' => ['required', 'string', 'max:150'],
            'distributed_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $clean = fn ($val) => is_string($val) ? trim(strip_tags(preg_replace('#<script\b[^>]*>(.*?)</script>#is', '', $val))) : $val;

        $this->merge([
            'recipient' => $clean($this->input('recipient')),
            'distributed_by' => $clean($this->input('distributed_by')),
            'notes' => $clean($this->input('notes')),
        ]);
    }
}
