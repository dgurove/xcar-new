<?php

namespace App\Billing;

use App\Billing\Actions\SaveBuyerData;
use Illuminate\Validation\Rule;

/** Правила полей контрагента — одни на стоянку, CRM и кабинет менеджера. */
final class PartyRules
{
    /**
     * Паспорт для договора (`x-billing.passport-fields`) набором `$prefix[...]`: ФИО, рождение, паспорт, регистрация. С `kind`
     * — покупатель (`x-billing.buyer-fields`, `SaveBuyerData`): физлицо, ИП или организация.
     */
    public static function passport(string $prefix): array
    {
        $kind = fn (string $k) => fn ($input) => data_get($input, $prefix.'.kind') === $k;

        return [
            $prefix => ['required', 'array'],
            // ФИО — у физлица и ИП одно поле; название организации — своё `org` (06.11.2026: одно `name` на оба вида
            // затиралось при смене вида, а скан не вписывал ФИО — два поля с одним именем).
            $prefix.'.name' => ['required_unless:'.$prefix.'.kind,company', 'nullable', 'string', 'max:200'],
            $prefix.'.birth_at' => ['nullable', 'date', 'before:today'], $prefix.'.birth_place' => ['nullable', 'string', 'max:255'],
            $prefix.'.passport' => ['nullable', 'string', 'max:60'], $prefix.'.passport_issued' => ['nullable', 'string', 'max:255'],
            $prefix.'.passport_issued_at' => ['nullable', 'date', 'before_or_equal:today'], $prefix.'.passport_code' => ['nullable', 'string', 'max:10'],
            $prefix.'.reg_address' => ['nullable', 'string', 'max:255'],
            // ИП (06.11.2026: физлицо платит со своего ИП — поставщику реквизиты ИП и скан паспорта): ИНН, ОГРНИП и банк
            // обязательно; организация — название, ИНН, руководитель, адрес, банк по желанию.
            $prefix.'.kind' => ['nullable', Rule::in(SaveBuyerData::KINDS)],
            $prefix.'.org' => ['required_if:'.$prefix.'.kind,company', 'nullable', 'string', 'max:200'],
            $prefix.'.inn' => ['required_if:'.$prefix.'.kind,ip,company', 'nullable', 'digits_between:10,12', Rule::when($kind('ip'), 'digits:12')],
            $prefix.'.kpp' => ['nullable', 'digits:9'],
            $prefix.'.ogrn' => ['required_if:'.$prefix.'.kind,ip', 'nullable', 'digits_between:13,15', Rule::when($kind('ip'), 'digits:15')],
            $prefix.'.legal_address' => ['required_if:'.$prefix.'.kind,company', 'nullable', 'string', 'max:255'],
            $prefix.'.director' => ['required_if:'.$prefix.'.kind,company', 'nullable', 'string', 'max:120'],
            $prefix.'.bank_name' => ['required_if:'.$prefix.'.kind,ip', 'nullable', 'string', 'max:120'],
            $prefix.'.bik' => ['required_if:'.$prefix.'.kind,ip', 'nullable', 'digits:9'],
            $prefix.'.account' => ['required_if:'.$prefix.'.kind,ip', 'nullable', 'digits:20'],
            $prefix.'.corr_account' => ['nullable', 'digits:20'],
        ];
    }

    /**
     * Реквизиты цифрами без пробелов и дефисов («4080 2810 …» из банка) — до проверки `digits`. Серия и номер паспорта
     * двумя полями (`x-billing.passport-fields`, 07.11.2026, владелец: «серия ____ номер ______») — в одно `passport`
     * «82 07 445349».
     */
    public static function squeeze(\Illuminate\Http\Request $request, string $prefix = 'buyer'): void
    {
        $set = (array) $request->input($prefix);
        foreach (['inn', 'kpp', 'ogrn', 'bik', 'account', 'corr_account'] as $f) {
            if (isset($set[$f]) && is_string($set[$f])) {
                $set[$f] = preg_replace('/[\s\-]+/u', '', $set[$f]);
            }
        }
        if (array_key_exists('passport_series', $set) || array_key_exists('passport_number', $set)) {
            $set['passport'] = self::passportNumber((string) ($set['passport_series'] ?? ''), (string) ($set['passport_number'] ?? ''));
            unset($set['passport_series'], $set['passport_number']);
        }
        $set && $request->merge([$prefix => $set]);
    }

    /** «8207» и «445349» → «82 07 445349»; не по образцу (старый или чужой паспорт) — как вписали, через пробел. */
    public static function passportNumber(string $series, string $number): ?string
    {
        $s = preg_replace('/\D+/u', '', $series);
        $n = preg_replace('/\D+/u', '', $number);
        if (strlen($s) === 4 && strlen($n) === 6) {
            return substr($s, 0, 2).' '.substr($s, 2).' '.$n;
        }
        $raw = trim(preg_replace('/\s+/u', ' ', trim($series).' '.trim($number)));

        return $raw === '' ? null : $raw;
    }

    /** Обратно, для двух полей формы: «82 07 445349» → ['82 07', '445349']. @return array{0: string, 1: string} */
    public static function passportParts(?string $passport): array
    {
        $p = trim((string) $passport);
        if (preg_match('/^(\d{2})\s?(\d{2})\s*(\d{6})$/u', $p, $m)) {
            return [$m[1].' '.$m[2], $m[3]];
        }
        // Не по образцу — номер последним словом, остальное — серия.
        $words = preg_split('/\s+/u', $p) ?: [];

        return count($words) > 1 ? [implode(' ', array_slice($words, 0, -1)), (string) end($words)] : ['', $p];
    }

    /** Подписи полей покупателя в ошибках: «Поле ОГРНИП обязательно», а не «buyer.ogrn». */
    public static function buyerLabels(string $prefix = 'buyer'): array
    {
        return collect(['name' => 'ФИО', 'org' => 'название', 'inn' => 'ИНН', 'kpp' => 'КПП', 'ogrn' => 'ОГРН', 'legal_address' => 'адрес',
            'director' => 'руководитель', 'bank_name' => 'банк', 'bik' => 'БИК', 'account' => 'расчётный счёт', 'corr_account' => 'корр. счёт'])
            ->mapWithKeys(fn ($v, $k) => [$prefix.'.'.$k => $v])->all();
    }

    public static function rules(): array
    {
        return [
            'kind' => ['required', Rule::enum(PartyKind::class)], 'name' => ['required', 'string', 'max:200'],
            'inn' => ['nullable', 'digits_between:10,12'], 'kpp' => ['nullable', 'digits:9'], 'ogrn' => ['nullable', 'digits_between:13,15'],
            'legal_address' => ['nullable', 'string', 'max:255'], 'director' => ['nullable', 'string', 'max:120'], 'director_basis' => ['nullable', 'string', 'max:120'],
            'full_name' => ['nullable', 'string', 'max:255'], 'director_full' => ['nullable', 'string', 'max:120'], 'signed_by' => ['nullable', 'string', 'max:255'],
            'bank_name' => ['nullable', 'string', 'max:120'], 'bik' => ['nullable', 'digits:9'], 'account' => ['nullable', 'digits:20'], 'corr_account' => ['nullable', 'digits:20'],
            'card' => ['nullable', 'regex:/^[\d ]{16,19}$/'],
            'passport' => ['nullable', 'string', 'max:60'], 'passport_issued' => ['nullable', 'string', 'max:255'], 'reg_address' => ['nullable', 'string', 'max:255'], 'birth_at' => ['nullable', 'date'],
            'passport_issued_at' => ['nullable', 'date'], 'passport_code' => ['nullable', 'string', 'max:10'], 'birth_place' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'], 'email' => ['nullable', 'email', 'max:120'], 'payment_purpose' => ['nullable', 'string', 'max:255'], 'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
