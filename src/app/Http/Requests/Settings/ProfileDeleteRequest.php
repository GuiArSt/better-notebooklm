<?php

namespace App\Http\Requests\Settings;

use App\Concerns\PasswordValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ProfileDeleteRequest extends FormRequest
{
    use PasswordValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        // A user who joined with Google has no password: they confirm by typing their email again.
        if ($this->user()?->password === null) {
            return [
                'email' => ['required', 'string', function (string $attribute, mixed $value, \Closure $fail) {
                    if (mb_strtolower(trim((string) $value)) !== mb_strtolower((string) $this->user()->email)) {
                        $fail('That is not the email address of this account.');
                    }
                }],
            ];
        }

        return [
            'password' => $this->currentPasswordRules(),
        ];
    }
}
