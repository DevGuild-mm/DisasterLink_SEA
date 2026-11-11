<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            ...$this->profileRules(),
            'country_id' => $this->countryRules(required: true),
            'alerts_enabled' => $this->alertsEnabledRules(),
            'password' => $this->passwordRules(),
        ], [
            'country_id.required' => __('Please choose your country.'),
        ])->validate();

        // Only the named fields are read, so extra input (a `role`, a verified date...) cannot reach the model.
        // An absent `alerts_enabled` keeps the database default (true).
        return User::create([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => $input['password'],
            'country_id' => (int) $input['country_id'],
            'alerts_enabled' => array_key_exists('alerts_enabled', $input) ? (bool) $input['alerts_enabled'] : true,
        ]);
    }
}
