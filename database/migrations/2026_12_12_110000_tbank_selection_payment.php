<?php

use App\Vendors\DealFormat;
use App\Vendors\Vendor;
use App\Workflow\Actions\RevalidateWorkflow;
use App\Workflow\Block;
use App\Workflow\Outcome;
use App\Workflow\Stage;
use App\Workflow\Track;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Т-Страхование — сделка «страхователю по ДКП» (05.10.2026): формат вендора «прямая» (схема по умолчанию при принятии),
 * после «Договор приложен» сделка ждёт оплату подбора — «Оплата подбора» и «Проверка оплаты подбора» блоком «Оплата».
 */
return new class extends Migration
{
    public function up(): void
    {
        $vendor = Vendor::where('name', 'Т-Страхование')->first();
        if (! $vendor) {
            return;
        }
        $vendor->update(['deal_format' => DealFormat::Direct]);
        $workflow = $vendor->workflow(Track::Sale);
        $stages = $workflow ? Stage::where('workflow_id', $workflow->id)->get()->keyBy('name') : collect();
        $contact = $stages['Контакты владельца переданы менеджеру'] ?? null;
        $closed = $stages['Сделка закрыта'] ?? null;
        if (! $contact || ! $closed || isset($stages['Оплата подбора'])) {
            return;
        }
        DB::transaction(function () use ($workflow, $contact, $closed) {
            $block = Block::where('workflow_id', $workflow->id)->where('name', 'Оплата')->first();
            if (! $block) {
                $after = (int) $contact->block->position;
                Block::where('workflow_id', $workflow->id)->where('position', '>', $after)->increment('position');
                $block = Block::create(['workflow_id' => $workflow->id, 'name' => 'Оплата', 'position' => $after + 1,
                    'text' => 'Счёт выставлен, оплатите его по ссылке или по реквизитам. После оплаты сделка закроется']);
            }
            $pay = Stage::create(['workflow_id' => $workflow->id, 'block_id' => $block->id, 'name' => 'Оплата подбора', 'position' => 0, 'waits_for' => 'manager',
                'limit_minutes' => 3 * 1440, 'asks' => 'document', 'ask_title' => 'Оплатите подбор',
                'ask_text' => 'Оплатите по ссылке под счётом картой, СБП или SberPay: оплата отметится сама. Или по реквизитам из счёта, тогда приложите платёжное поручение']);
            $check = Stage::create(['workflow_id' => $workflow->id, 'block_id' => $block->id, 'name' => 'Проверка оплаты подбора', 'position' => 1, 'waits_for' => 'us', 'limit_minutes' => 1440]);
            Outcome::create(['stage_id' => $pay->id, 'to_stage_id' => $check->id, 'label' => 'Платёжное поручение приложено', 'actor' => 'manager', 'position' => 0]);
            Outcome::create(['stage_id' => $check->id, 'to_stage_id' => $closed->id, 'label' => 'Оплата получена', 'actor' => 'staff', 'position' => 0]);
            Outcome::create(['stage_id' => $check->id, 'to_stage_id' => $pay->id, 'label' => 'Оплата не поступила', 'actor' => 'staff', 'position' => 1]);
            Outcome::where('stage_id', $contact->id)->where('to_stage_id', $closed->id)->update(['to_stage_id' => $pay->id]);
            $contact->update(['ask_text' => 'Свяжитесь с владельцем автомобиля, подпишите с покупателем ДКП и приложите его. Без подписанного ДКП сделка не закроется']);
            app(RevalidateWorkflow::class)($workflow);
        });
    }

    public function down(): void {}
};
