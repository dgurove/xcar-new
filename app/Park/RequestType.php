<?php

namespace App\Park;

use App\Cars\HasLabels;

enum RequestType: string
{
    use HasLabels;

    case Intake = 'intake';
    case Release = 'release';
    // Осмотр (13.11.2026, Сергей с парковки: «вкладку осмотры, как приём и выдача, чтоб сюда запросы на осмотр падали»):
    // покупатель или страховая хотят посмотреть стоящую ТС. Заводит письмо с запросом осмотра (`InspectByLetter`) и «⋯»
    // дела; закрывает «Осмотрели».
    case Inspect = 'inspect';

    public function label(): string
    {
        return match ($this) {
            self::Intake => 'Приём',
            self::Release => 'Выдача',
            self::Inspect => 'Осмотр',
        };
    }

    /** Значок типа (`x-park.request-type`): приём — стрелка к нам, выдача — от нас, осмотр — лупа. */
    public function icon(): string
    {
        return match ($this) {
            self::Intake => 'req-intake',
            self::Release => 'req-release',
            self::Inspect => 'req-inspect',
        };
    }

    public function verb(): string
    {
        return match ($this) {
            self::Intake => 'Принять',
            self::Release => 'Выдать',
            self::Inspect => 'Осмотрели',
        };
    }

    /** Какие заявки заводятся при каком состоянии ТС: выдать ожидаемую нельзя, забрать выданную — тоже. */
    public function allowedFor(VehicleState $state): bool
    {
        return match ($this) {
            self::Intake => $state === VehicleState::Expected,
            self::Release, self::Inspect => $state === VehicleState::Stored,
        };
    }
}
