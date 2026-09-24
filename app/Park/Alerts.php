<?php

namespace App\Park;

use App\Billing\Accrual;
use App\Vendors\Tariff;
use App\Vendors\TariffService;

/**
 * Что не так с ТС — короткими тегами у наименования. Стоят вместо столбца «Состояние» в «Наличии»: там у
 * всех стоящих было написано одно и то же, а дырок, из-за которых ТС не считается или о ней забудут, не
 * было видно нигде. Только дырки в данных: этапы работы (бумаги вендору, отчёт) живут шагами на деле ТС и
 * в дайджесте, иначе в строке вырастает стена тегов. Одно место на таблицу, строки, плитки и окошко.
 */
final class Alerts
{
    /** У вендора нет прайса на эту ТС — списком не починить, нужны цены из договора. */
    public const NO_TARIFF = 'Нет тарифа';

    /** @return list<array{label: string, tone: string}> */
    public static function of(Vehicle $vehicle): array
    {
        $state = $vehicle->state;
        $out = [];
        // Ставки нет — сутки не считаются: это первое, что надо починить (лестница прайса из памяти запроса).
        if ($vehicle->accepted_at && in_array($state, [VehicleState::Stored, VehicleState::Released], true) && ! Accrual::hasRate($vehicle)) {
            $out[] = ['label' => self::whyNoRate($vehicle), 'tone' => 'urgent'];
        }
        if ($state === VehicleState::Stored && ! $vehicle->yard_id) {
            $out[] = ['label' => 'Нет парковки', 'tone' => 'urgent'];
        }
        // Ждёт, а заявки нет (отменили) — иначе про неё забудут.
        if ($state === VehicleState::Expected && $vehicle->relationLoaded('requests') && ! $vehicle->requests->contains(fn ($r) => $r->isOpen())) {
            $out[] = ['label' => 'без заявки', 'tone' => 'urgent'];
        }
        if (! $state->isFinal() && ($vin = $vehicle->vinProblem())) {
            $out[] = ['label' => $vin, 'tone' => 'danger'];
        }
        if (! $state->isFinal() && $vehicle->noLetters()) {
            $out[] = ['label' => 'Писем нет', 'tone' => 'urgent'];
        }

        return $out;
    }

    /**
     * Почему сутки не считаются: тариф вендора берётся по категории, а у легковых АльфаСтрахования ещё и по
     * заявленной стоимости. Причина всегда одна, самая точная — чинить надо именно её.
     */
    public static function whyNoRate(Vehicle $vehicle): string
    {
        if (! $vehicle->category) {
            return 'Нет типа';
        }
        $byValue = $vehicle->value === null
            && Tariff::ladder($vehicle->vendor_id, $vehicle->yard_id, $vehicle->category, TariffService::Storage, null, PHP_INT_MAX)->isNotEmpty();

        return $byValue ? 'Нет стоимости' : self::NO_TARIFF;
    }
}
