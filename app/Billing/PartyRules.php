<?php

namespace App\Billing;

use Illuminate\Validation\Rule;

/** Правила полей контрагента — одни на стоянку, CRM и кабинет менеджера. */
final class PartyRules
{
    public static function rules(): array
    {
        return [
            'kind' => ['required', Rule::enum(PartyKind::class)], 'name' => ['required', 'string', 'max:200'],
            'inn' => ['nullable', 'digits_between:10,12'], 'kpp' => ['nullable', 'digits:9'], 'ogrn' => ['nullable', 'digits_between:13,15'],
            'legal_address' => ['nullable', 'string', 'max:255'], 'director' => ['nullable', 'string', 'max:120'], 'director_basis' => ['nullable', 'string', 'max:120'],
            'bank_name' => ['nullable', 'string', 'max:120'], 'bik' => ['nullable', 'digits:9'], 'account' => ['nullable', 'digits:20'], 'corr_account' => ['nullable', 'digits:20'],
            'card' => ['nullable', 'regex:/^[\d ]{16,19}$/'],
            'passport' => ['nullable', 'string', 'max:60'], 'passport_issued' => ['nullable', 'string', 'max:255'], 'reg_address' => ['nullable', 'string', 'max:255'], 'birth_at' => ['nullable', 'date'],
            'phone' => ['nullable', 'string', 'max:20'], 'email' => ['nullable', 'email', 'max:120'], 'payment_purpose' => ['nullable', 'string', 'max:255'], 'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
