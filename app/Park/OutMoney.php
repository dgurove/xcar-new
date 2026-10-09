<?php

namespace App\Park;

/**
 * Деньги выданной ТС одним словом (владелец 09.10.2026: выданные смотрят как архив дел — закрыты ли деньги): счёт по ней
 * не оплачен (свой или реестр, хоть частично) → «не оплачено»; хранение не выставлено по день выдачи → «не выставлено»;
 * есть оплаченный счёт → «оплачено». Выданные до системы (по таблице, счетов в ней нет) — без слова. Одно выражение SQL
 * на чип «Деньги», строку «Выданных», карточку и выгрузку.
 */
final class OutMoney
{
    public const LABELS = ['unpaid' => 'Не оплачено', 'unbilled' => 'Не выставлено', 'paid' => 'Оплачено'];

    /** Тон слова в строке: не оплачено — дело, не выставлено — ждёт закрытия месяца, оплачено — хорошо. */
    public const TONES = ['unpaid' => 'text-urgent', 'unbilled' => 'text-ink-muted', 'paid' => 'text-accent-text'];

    public static function sql(string $t = 'park_vehicles'): string
    {
        $invoice = fn (string $state) => "exists (select 1 from billing_charges c join billing_invoices i on i.id = c.invoice_id
            where c.vehicle_id = {$t}.id and c.voided_at is null and i.direction = 'issued' and i.state = '{$state}')";

        return "(case when {$t}.state <> 'released' then null
            when {$invoice('issued')} then 'unpaid'
            when {$t}.storage_billed_until is null or {$t}.storage_billed_until < {$t}.released_at::date then 'unbilled'
            when {$invoice('paid')} then 'paid' end)";
    }

    public static function of(Vehicle $vehicle): ?string
    {
        if ($vehicle->state !== VehicleState::Released) {
            return null;
        }

        return $vehicle->getAttribute('money_state')
            ?? Vehicle::withoutGlobalScopes()->whereKey($vehicle->id)->selectRaw(self::sql().' as v')->value('v');
    }
}
