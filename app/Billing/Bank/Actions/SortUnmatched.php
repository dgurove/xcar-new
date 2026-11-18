<?php

namespace App\Billing\Bank\Actions;

use App\Billing\Bank\Transaction;
use App\Support\Nav;

/**
 * Непривязанное поступление — само на место, без человека (18.11.2026, владелец: «висят тупо»; из 8 висящих за ноябрь
 * у трёх VIN машины без сделки в CRM, три — реестры АЙТИГРУПП, которые 14 раз отмечали руками «Вне системы»):
 * - VIN в назначении есть, а идущей сделки по ним нет — «Нет в CRM» (владелец: «если нет в CRM,
 *   то и ладно, не привязывай»); раз в час `bank:link` пробует снова (`retry`): сделка завелась — встаёт зачётом;
 * - машины в назначении нет, а этот ИНН люди уже не меньше двух раз отмечали одинаково — так же (`decided_by` пуст: сама).
 * Без машины и без прошлого (займ, счёт вне системы без VIN) — остаётся человеку.
 */
final class SortUnmatched
{
    /** Пометка строки с машиной, которой нет в работе CRM. */
    public const NO_CRM = 'Нет в CRM';

    /** ИНН банков-посредников: физлица платят через Сбербанк, по ИНН банка не узнать, кто это. */
    private const BANKS = ['7707083893'];

    public function __construct(private MatchTransaction $match) {}

    public function __invoke(Transaction $tx): Transaction
    {
        if ($tx->state !== Transaction::UNMATCHED || ! $tx->isIncoming() || MatchTransaction::isPayout($tx)) {
            return $tx;
        }
        if (self::namesCar((string) $tx->purpose)) {
            // Машина названа, а встать некуда: сделки нет или их несколько. Привязка к закрытой сделке (`deal_id`) — тоже не наше дело.
            if ($tx->deal_id === null) {
                $tx->update(['state' => Transaction::IGNORED, 'note' => self::NO_CRM, 'decided_by' => null, 'decided_at' => now()]);
                Nav::forgetStaffCounts();
            }

            return $tx;
        }
        if (($note = self::likePast($tx)) !== null) {
            $tx->update(['state' => Transaction::IGNORED, 'note' => $note, 'decided_by' => null, 'decided_at' => now()]);
            Nav::forgetStaffCounts();
        }

        return $tx;
    }

    /** «Нет в CRM» снова в работу: сделка по VIN завелась — зачётом (`MatchByVin` через `MatchTransaction`). */
    public function retry(Transaction $tx): Transaction
    {
        if ($tx->state !== Transaction::IGNORED || $tx->note !== self::NO_CRM || ! MatchByVin::dealFor($tx)) {
            return $tx;
        }
        $tx->update(['state' => Transaction::UNMATCHED, 'note' => null, 'decided_at' => null]);

        return ($this->match)($tx);
    }

    /** В назначении — VIN (17 знаков, буквы и цифры, как его ищет `CarMatch`). Номер убытка и госномер — нет: их вид ловит и номера счетов. */
    public static function namesCar(string $purpose): bool
    {
        preg_match_all('/(?<![A-Z0-9])[A-HJ-NPR-Z0-9]{17}(?![A-Z0-9])/', mb_strtoupper($purpose), $m);

        return collect($m[0])->contains(fn ($v) => preg_match('/\d/', $v) && preg_match('/[A-Z]/', $v));
    }

    /**
     * Как люди отмечали этот ИНН раньше: не меньше двух раз одинаково и ни разу не в счёт — та же пометка; иначе null.
     */
    public static function likePast(Transaction $tx): ?string
    {
        $inn = $tx->counterparty_inn;
        if (! $inn || in_array($inn, self::BANKS, true)) {
            return null;
        }
        $past = Transaction::where('direction', 'in')->where('counterparty_inn', $inn)->whereKeyNot($tx->id)
            ->whereIn('state', [Transaction::MATCHED, Transaction::IGNORED])->get(['state', 'note', 'decided_by']);
        if ($past->contains('state', Transaction::MATCHED)) {
            return null;
        }
        $byHand = $past->whereNotNull('decided_by');
        $notes = $byHand->pluck('note')->unique();

        return $byHand->count() >= 2 && $notes->count() === 1 && $notes->first() !== self::NO_CRM ? $notes->first() : null;
    }
}
