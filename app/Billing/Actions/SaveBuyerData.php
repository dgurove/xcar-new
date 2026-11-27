<?php

namespace App\Billing\Actions;

use App\Billing\Party;
use App\Billing\PartyKind;
use App\Billing\Passport\PassportText;
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
        // Реквизиты в карточке организации файлами (27.11.2026) — названия ещё нет: имя у контрагента обязательно, оставляем прежнее
        // (пустое — пустой строкой), внесём по файлам.
        if (array_key_exists('name', $fields) && $fields['name'] === null) {
            $fields['name'] = $party->name ?? '';
        }
        $keep = $keepKind && $kind === 'person' && in_array($party->kind, [PartyKind::Entrepreneur, PartyKind::SelfEmployed], true);
        $party->fill(($keep ? [] : ['kind' => $as]) + $fields)->save();
        // Имя покупателя из ФИО ставит само сохранение контрагента (`Party::booted` → `renameBuyer`); здесь — на случай,
        // когда ФИО не менялось, а имя уже разошлось.
        self::renameBuyer($party);

        return $party;
    }

    /**
     * Имя покупателя — из ФИО его данных: заведённый без имени и покупатель — при каждом сохранении данных, если имя разошлось с ФИО (16.11.2026, Видов:
     * сканер прочитал «Виеов», сотрудник исправил в данных, а в «Кто заберёт» и «Кто платит» остался «Александр Виеов»).
     * ФИО «Фамилия Имя Отчество» → имя и фамилия, организация — названием. Менеджера и сотрудников не переименовываем.
     * Зовёт само сохранение контрагента при смене ФИО (`Party::booted`), так что путь правки не важен.
     */
    public static function renameBuyer(Party $party): void
    {
        $name = trim(preg_replace('/\s+/u', ' ', (string) $party->name));
        if ($name === '') {
            return;
        }
        $users = User::where('party_id', $party->id)->get()
            ->filter(fn (User $u) => $u->name === AddPerson::UNNAMED || $u->isBuyer() || $u->isSuperBuyer());
        foreach ($users as $user) {
            $parts = $party->kind === PartyKind::Company ? [$name, ''] : self::nameParts($name, (string) $user->name);
            if ($parts === null) {
                continue;
            }
            [$first, $last] = $parts;
            if ($user->first_name !== $first || (string) $user->last_name !== $last) {
                $user->update(['first_name' => mb_substr($first, 0, 60), 'last_name' => mb_substr($last, 0, 60)]);
            }
        }
    }

    /**
     * Имя и фамилия из ФИО. Паспортный порядок — «Фамилия Имя Отчество»; но два слова часто стоят «Имя Фамилия» (контрагента
     * заводили из имени аккаунта), поэтому порядок — по отчеству, словарю имён и окончанию фамилии. Не понять — `null`:
     * два слова, что уже стоят в имени в другом порядке, не переставляем («Sergei Mak», «Саулите Улдис»).
     *
     * @return array{0: string, 1: string}|null
     */
    public static function nameParts(string $fio, string $current = ''): ?array
    {
        $fio = trim(preg_replace('/^(ИНДИВИДУАЛЬНЫЙ\s+ПРЕДПРИНИМАТЕЛЬ|ИП)\s+/iu', '', trim($fio)));
        $w = array_values(array_filter(explode(' ', preg_replace('/\s+/u', ' ', $fio))));
        if (count($w) < 2) {
            return $w ? [$w[0], ''] : null;
        }
        $up = fn (string $x) => mb_strtoupper($x);
        $patronymic = fn (string $x) => (bool) preg_match('/(ВИЧ|ВНА|ИЧНА|ОГЛЫ|КЫЗЫ)$/u', $up($x));
        $surname = fn (string $x) => (bool) preg_match('/(ОВ|ЕВ|ЁВ|ИН|ЫН|СКИЙ|ЦКИЙ|ОВА|ЕВА|ЁВА|ИНА|ЫНА|СКАЯ|ЦКАЯ)$/u', $up($x));
        $name = fn (string $x) => PassportText::isName($up($x));
        if (count($w) >= 3) {
            // «Имя Отчество Фамилия» — отчество вторым; иначе паспортный порядок.
            return $patronymic($w[1]) && ! $patronymic($w[2]) ? [$w[0], $w[2]] : [$w[1], $w[0]];
        }
        [$a, $b] = $w;
        $order = match (true) {
            $name($a) && ! $name($b), $surname($b) && ! $surname($a) => [$a, $b],
            $name($b) && ! $name($a), $surname($a) && ! $surname($b) => [$b, $a],
            default => null,
        };
        if ($order) {
            return $order;
        }
        $have = preg_split('/\s+/u', $up(trim($current)));
        sort($have);
        $both = [$up($a), $up($b)];
        sort($both);

        return $have === $both ? null : [$b, $a];
    }
}
