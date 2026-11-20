<?php

namespace App\Http\Requests\Settings;

use App\Concerns\ProfileValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Only the name can change. The address is fixed for the demo (Q-021): a changed address would be unverified, and with no mail
 * service (D-022) the user could never verify it. The form's field is disabled, so it never sends one; a request that does is refused.
 */
class ProfileUpdateRequest extends FormRequest
{
    use ProfileValidationRules;

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'name' => $this->nameRules(),
            'email' => ['prohibited'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['email.prohibited' => 'Your address is fixed for this demo.'];
    }
}
