<?php

namespace App\Offers;

use Illuminate\Support\Facades\DB;

/**
 * Номер договора — «ГГГГММДД-NN» (16.11.2026, владелец: «всегда формировались по системе годмесяцдень-01, -02, -03»; «супер
 * важная штука»). Дата — день, когда сотрудник нажал «Отправить менеджеру» (`Actions\ShowToManager::contract`), по Москве, а не
 * готовность и не подписание. Очередь одна на ПРАЙМ и Р.О.С. Выдаётся один раз и больше не меняется никогда: ни повторной
 * отправкой, ни сменой продавца, цены или плательщика. ДКП собственника (форма `owner`) номера не имеет.
 *
 * Надёжность: число дня — одним атомарным запросом Postgres к `contract_days` (две отправки в одну секунду получат разные
 * числа, без кэш-замков и `max()+1`), `deal_contracts.code` уникален — дубль упадёт ошибкой, а не станет вторым договором;
 * зовётся внутри транзакции отправки под замком строки договора — сорвалась отправка, номер откатывается вместе с ней.
 */
final class ContractNumber
{
    public static function assign(DealContract $contract): ?string
    {
        if ($contract->code || $contract->form() === 'owner') {
            return $contract->code;
        }
        $day = now('Europe/Moscow');
        $n = (int) DB::selectOne('insert into contract_days (day, last) values (?, 1) on conflict (day) do update set last = contract_days.last + 1 returning last',
            [$day->toDateString()])->last;
        $code = $day->format('Ymd').'-'.str_pad((string) $n, 2, '0', STR_PAD_LEFT);
        $contract->forceFill(['code' => $code, 'numbered_on' => $day->toDateString()])->save();

        return $code;
    }
}
