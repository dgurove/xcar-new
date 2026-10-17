<?php

namespace App\Workflow;

use App\Workflow\Presets\Alfa;
use App\Workflow\Presets\Route;
use App\Workflow\Presets\Sovcombank;
use App\Workflow\Presets\Stock;
use App\Workflow\Presets\TBank;

/**
 * Заготовка маршрута: боевой процесс одной из страховых, которым заполняется
 * пустой маршрут. Новой страховой «как у Альфы» — одной кнопкой; ими же
 * сидер заводит три боевые.
 */
enum Preset: string
{
    case TBank = 'tbank';
    case Alfa = 'alfa';
    case Sovcombank = 'sovcombank';
    case Stock = 'stock';

    public function label(): string
    {
        return match ($this) {
            self::TBank => 'Как у Т-Страхования',
            self::Alfa => 'Как у АльфаСтрахования',
            self::Sovcombank => 'Как у Совкомбанка',
            self::Stock => 'Своё ТС на парковке',
        };
    }

    public function track(): Track
    {
        return Track::Sale;
    }

    /** @return list<self> */
    public static function forTrack(Track $track): array
    {
        return array_values(array_filter(self::cases(), fn (self $p) => $p->track() === $track));
    }

    public function route(): Route
    {
        return match ($this) {
            self::TBank => new TBank,
            self::Alfa => new Alfa,
            self::Sovcombank => new Sovcombank,
            self::Stock => new Stock,
        };
    }
}
