<?php

namespace App\Park\Console;

use App\Park\Actions\UpdateVehicle;
use App\Park\City;
use App\Park\Vehicle;
use App\Park\VehicleState;
use Illuminate\Console\Command;

/**
 * Тип ТС тем, у кого он пуст (12.10.2026: у 59 машин на парковке не было ставки только из-за пустого типа): как у
 * связанного предложения, по марке и модели, иначе легковой (`Vehicle::guessCategory`). Без `--apply` — только список.
 */
class GuessCategoriesCommand extends Command
{
    protected $signature = 'park:guess-categories {--apply : записать, а не только показать}';

    protected $description = 'Угадать тип ТС у машин парковки без типа';

    public function handle(UpdateVehicle $update): int
    {
        $apply = (bool) $this->option('apply');
        $vehicles = Vehicle::withoutGlobalScope(City::SCOPE)->whereNull('category')->whereNotNull('brand_id')
            ->whereIn('state', [VehicleState::Expected, VehicleState::Stored])->with(['brand', 'model', 'vendor'])->orderBy('id')->get();
        $by = [];
        foreach ($vehicles as $v) {
            $category = $v->guessCategory();
            $by[$category->label()] = ($by[$category->label()] ?? 0) + 1;
            $this->line("ТС {$v->id} {$v->titleWithYear()} ({$v->vendor?->name}) → {$category->label()}");
            if ($apply) {
                $update($v, ['category' => $category->value], null);
            }
        }
        $this->info(($apply ? 'записано' : 'запишется').': '.$vehicles->count().' — '.collect($by)->map(fn ($n, $l) => "{$l} {$n}")->implode(', '));

        return self::SUCCESS;
    }
}
