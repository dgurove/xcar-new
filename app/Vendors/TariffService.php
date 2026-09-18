<?php

namespace App\Vendors;

use App\Cars\HasLabels;

/** За что берём: хранение по суткам, эвакуация фиксом и за километр, погрузка, осмотр, простой, надбавка за негабарит. */
enum TariffService: string
{
    use HasLabels;

    case Storage = 'storage';
    case Tow = 'tow';
    case TowKm = 'tow_km';
    case Loading = 'loading';
    case Inspection = 'inspection';
    case Idle = 'idle';
    case Oversize = 'oversize';
    case Release = 'release';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Storage => 'Хранение',
            self::Tow => 'Эвакуация',
            self::TowKm => 'Километр сверх',
            self::Loading => 'Погрузка',
            self::Inspection => 'Осмотр',
            self::Idle => 'Простой',
            self::Oversize => 'Негабарит',
            self::Release => 'Выдача',
            self::Other => 'Прочее',
        };
    }

    /** Единица, за которую цена. */
    public function unit(): string
    {
        return match ($this) {
            self::Storage, self::Oversize => 'сут',
            self::TowKm => 'км',
            self::Idle => 'ч',
            default => 'раз',
        };
    }

    /** Ступени по суткам есть только у хранения и надбавки к нему. */
    public function tiered(): bool
    {
        return $this === self::Storage || $this === self::Oversize;
    }
}
