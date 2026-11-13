<?php

namespace App\Billing\Actions;

use App\Billing\Party;
use App\Billing\PartyKind;
use App\Users\Actions\AddPerson;
use App\Users\User;

/**
 * Данные покупателя для договора и счёта — одна дверь шторки CRM, ДКП менеджера и «Данных для договора» (06.11.2026,
 * владелец: покупатель-физлицо платит со своего ИП, «при смене на ИП заполненные данные должны оставаться»). Вид — физлицо,
 * ИП или организация; пишутся только поля выбранного вида, остальные в базе не трогаются: сохранили физлицо, потом сменили
 * на ИП — паспорт и скан на месте, ушли в организацию и вернулись — тоже.
 */
final class SaveBuyerData
{
    public const KINDS = ['person', 'ip', 'company'];

    private const FIELDS = [
        'person' => ['name', 'birth_at', 'birth_place', 'passport', 'passport_issued', 'passport_issued_at', 'passport_code', 'reg_address'],
        'ip' => ['name', 'inn', 'ogrn', 'legal_address', 'bank_name', 'bik', 'account', 'corr_account'],
        'company' => ['org', 'inn', 'kpp', 'ogrn', 'legal_address', 'director', 'bank_name', 'bik', 'account', 'corr_account'],
    ];

    /** @param  bool  $keepKind  контрагент менеджера-ИП или самозанятого для выплат: паспорт дописываем, вид не меняем */
    public function __invoke(Party $party, array $data, bool $keepKind = false): Party
    {
        $kind = in_array($data['kind'] ?? null, self::KINDS, true) ? $data['kind'] : 'person';
        $fields = array_map(fn ($v) => is_string($v) ? (trim($v) === '' ? null : trim($v)) : $v, array_intersect_key($data, array_flip(self::FIELDS[$kind])));
        if (array_key_exists('org', $fields)) {
            $fields['name'] = $fields['org'];
            unset($fields['org']);
        }
        // Адрес ИП — его прописка, если отдельно не вписан.
        if ($kind === 'ip' && empty($fields['legal_address'])) {
            $fields['legal_address'] = $party->legal_address ?: $party->reg_address;
        }
        $as = match ($kind) {
            'ip' => PartyKind::Entrepreneur,
            'company' => PartyKind::Company,
            default => PartyKind::Person,
        };
        $keep = $keepKind && $kind === 'person' && in_array($party->kind, [PartyKind::Entrepreneur, PartyKind::SelfEmployed], true);
        $party->fill(($keep ? [] : ['kind' => $as]) + $fields)->save();
        // Покупатель, заведённый плиткой вида без имени («Новый покупатель», `Cabinet\DealController::buyerNew`), — имя из
        // полей (13.11.2026): ФИО «Фамилия Имя Отчество» → «Имя Фамилия», организация — названием.
        $name = trim((string) $party->name);
        $user = $name !== '' ? User::where('party_id', $party->id)->where('name', AddPerson::UNNAMED)->first() : null;
        if ($user) {
            $w = preg_split('/\s+/u', $name);
            $user->update(['name' => $as === PartyKind::Company || count($w) < 2 ? $name : trim($w[1].' '.$w[0])]);
        }

        return $party;
    }
}
