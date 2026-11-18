<?php

use App\Billing\Robot;
use App\Offers\Deal;
use App\Workflow\Actions\PlaceOnStage;
use App\Workflow\Asks;
use App\Workflow\Outcome;
use App\Workflow\Position;
use App\Workflow\Presets\Alfa;
use App\Workflow\Stage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Реквизиты для счёта поставщика — одним шагом (18.11.2026, владелец: «сначала „Отправить реквизиты“, потом этап, где у
 * менеджера запрашиваются реквизиты, хотя он их уже заполнил, и только потом отправка — остаток старой системы»; покупателя
 * менеджер заполняет сразу после «Беру в работу»). «Поставщик просит реквизиты» и «Осмотрел, берём» при любой схеме ведут на
 * «Письмо с реквизитами» (наш ход: покупатель в шаге, «Отправить реквизиты» или «Запросить реквизиты»). Сделки на «Нет
 * реквизитов» — туда же. Сам шаг «Нет реквизитов» остаётся для параллельных машин (`Parallels`).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (Stage::where('asks', Asks::Buyer->value)->get() as $data) {
            $letter = Stage::where('workflow_id', $data->workflow_id)->where('block_id', $data->block_id)->where('id', '!=', $data->id)
                ->whereNotNull('template_id')->orderBy('position')->first();
            if (! $letter) {
                continue;
            }
            DB::transaction(function () use ($data, $letter) {
                foreach (Outcome::where('to_stage_id', $data->id)->where('stage_id', '!=', $data->id)->get() as $exit) {
                    $twin = Outcome::where('stage_id', $exit->stage_id)->where('label', $exit->label)->where('to_stage_id', $letter->id)->first();
                    if ($twin) {
                        $exit->delete();
                        $twin->update(['branch' => null]);
                    } else {
                        $exit->update(['to_stage_id' => $letter->id, 'branch' => null]);
                    }
                }
                $by = Robot::user();
                foreach (Position::where('stage_id', $data->id)->where('track', 'sale')->with('offer')->get() as $position) {
                    $deal = Deal::where('offer_id', $position->offer_id)->active()->first();
                    if (! $deal || $deal->isParallel() || ! $position->offer) {
                        continue;
                    }
                    app(PlaceOnStage::class)($position->offer, $letter, $by, payload: ['label' => Alfa::ASKS_REQUISITES]);
                }
            });
        }
    }
};
