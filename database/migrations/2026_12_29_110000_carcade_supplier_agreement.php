<?php

use App\Vendors\Vendor;
use App\Workflow\Actions\RevalidateWorkflow;
use App\Workflow\Block;
use App\Workflow\Stage;
use App\Workflow\Track;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Каркаде оптом (09.10.2026, владелец): после «Принять» — не «Согласие менеджера», а «Согласование с поставщиком»:
 * ждём, что Каркаде отдаёт машину (отвечает списком), «да» — сразу счёт менеджеру ПРАЙМ (гаражной «платим мы» — оплата
 * поставщику), «нет» — «Отказ поставщика». Этап правится на месте: на нём уже стоят сделки. Формат вендора — «Оптом»:
 * выкупаем мы, схема одна (ПРАЙМ). Поля выдачи — без «площадки».
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (Vendor::whereRaw("name ilike '%каркаде%' or name ilike '%carcade%'")->get() as $vendor) {
            $vendor->update(['deal_format' => 'wholesale']);
            $this->route($vendor);
        }
    }

    private function route(Vendor $vendor): void
    {
        $workflow = $vendor->workflow(Track::Sale);
        $stage = $workflow ? Stage::where('workflow_id', $workflow->id)->where('name', 'Согласие менеджера')->first() : null;
        $invoice = $workflow ? Stage::where('workflow_id', $workflow->id)->where('name', 'Счёт выставлен менеджеру')->first() : null;
        if (! $stage || ! $invoice) {
            return;
        }
        $garage = Stage::where('workflow_id', $workflow->id)->where('name', 'Оплата поставщику, в гараж')->first();

        DB::transaction(function () use ($workflow, $stage, $invoice, $garage) {
            $stage->block?->update(['name' => 'Согласование с поставщиком', 'text' => 'Согласовываем покупку с поставщиком. Счёт придёт, когда поставщик подтвердит']);
            $stage->update(['name' => 'Согласование с поставщиком', 'waits_for' => 'supplier', 'limit_minutes' => 1440, 'ask_title' => null, 'ask_text' => null]);

            $declinedBlock = Block::create(['workflow_id' => $workflow->id, 'name' => 'Отказ поставщика', 'text' => 'Поставщик снял автомобиль с продажи. Сделка не состоится',
                'position' => (int) Block::where('workflow_id', $workflow->id)->where('name', 'Оформляем на нас')->value('position') + 1]);
            $declined = Stage::create(['workflow_id' => $workflow->id, 'block_id' => $declinedBlock->id, 'position' => 0, 'name' => 'Отказ поставщика',
                'waits_for' => 'nobody', 'deadline_source' => 'own', 'offer_state' => 'cancelled', 'asks' => 'nothing', 'fields' => [], 'staff_fields' => []]);

            $stage->exits()->delete();
            $stage->exits()->create(['label' => 'Поставщик согласовал', 'actor' => 'staff', 'to_stage_id' => $invoice->id, 'position' => 0, 'branch' => 'buyer']);
            if ($garage) {
                $stage->exits()->create(['label' => 'Поставщик согласовал', 'actor' => 'staff', 'to_stage_id' => $garage->id, 'position' => 1, 'branch' => 'garage']);
            }
            $stage->exits()->create(['label' => 'Поставщик отказал', 'actor' => 'staff', 'to_stage_id' => $declined->id, 'position' => 2]);

            foreach (Stage::where('workflow_id', $workflow->id)->get() as $s) {
                $fields = $s->staff_fields ?? [];
                $renamed = array_map(fn ($f) => ['label' => match ($f['label'] ?? '') {
                    'Адрес площадки' => 'Адрес выдачи',
                    'Контакт на площадке' => 'Контакт на выдаче',
                    default => $f['label'] ?? '',
                }] + $f, $fields);
                if ($renamed !== $fields) {
                    $s->update(['staff_fields' => $renamed]);
                }
            }
        });
        app(RevalidateWorkflow::class)($workflow->fresh());
    }

    public function down(): void {}
};
