<?php

namespace App\Park;

use App\Cars\HasLabels;

enum RequestType: string
{
    use HasLabels;

    case Intake = 'intake';
    case Release = 'release';

    public function label(): string
    {
        return match ($this) {
            self::Intake => 'Приём',
            self::Release => 'Выдача',
        };
    }

    public function verb(): string
    {
        return match ($this) {
            self::Intake => 'Принять',
            self::Release => 'Выдать',
        };
    }

    /** Какие заявки заводятся при каком состоянии ТС: выдать ожидаемую нельзя, забрать выданную — тоже. */
    public function allowedFor(VehicleState $state): bool
    {
        return match ($this) {
            self::Intake => $state === VehicleState::Expected,
            self::Release => $state === VehicleState::Stored,
        };
    }
}
