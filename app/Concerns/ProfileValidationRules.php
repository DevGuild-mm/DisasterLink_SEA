<?php

namespace App\Concerns;

use App\Models\Country;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

trait ProfileValidationRules
{
    /**
     * Get the validation rules used to validate user profiles.
     *
     * @return array<string, array<int, Unique|ValidationRule|array<mixed>|string>>
     */
    protected function profileRules(?int $userId = null): array
    {
        return [
            'name' => $this->nameRules(),
            'email' => $this->emailRules($userId),
        ];
    }

    /**
     * Rules for the country a person registers for alerts with (an id from the active countries).
     *
     * `$currentCountryId` is the country the person already has. Keeping it is always allowed, so a person whose
     * country has since been deactivated can still save other changes; choosing any other country needs an
     * active one.
     *
     * @return array<int, ValidationRule|string|Closure>
     */
    protected function countryRules(bool $required, ?int $currentCountryId = null): array
    {
        return [
            $required ? 'required' : 'nullable',
            'integer',
            function (string $attribute, mixed $value, Closure $fail) use ($currentCountryId): void {
                if ($currentCountryId !== null && (int) $value === $currentCountryId) {
                    return;
                }

                if (! Country::query()->active()->whereKey($value)->exists()) {
                    $fail(__('Please choose one of the available countries.'));
                }
            },
        ];
    }

    /**
     * Rules for the "Email me disaster alerts" preference.
     *
     * @return array<int, string>
     */
    protected function alertsEnabledRules(): array
    {
        return ['sometimes', 'boolean'];
    }

    /**
     * Get the validation rules used to validate user names.
     *
     * @return array<int, Unique|ValidationRule|array<mixed>|string>
     */
    protected function nameRules(): array
    {
        return ['required', 'string', 'max:255'];
    }

    /**
     * Get the validation rules used to validate user emails.
     *
     * @return array<int, Unique|ValidationRule|array<mixed>|string>
     */
    protected function emailRules(?int $userId = null): array
    {
        return [
            'required',
            'string',
            'email',
            'max:255',
            $userId === null
                ? Rule::unique(User::class)
                : Rule::unique(User::class)->ignore($userId),
        ];
    }
}
