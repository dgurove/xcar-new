<?php

use App\Mail\Scope;
use App\Mail\Template;
use App\Vendors\Vendor;
use App\Workflow\Actions\RevalidateWorkflow;
use App\Workflow\Block;
use App\Workflow\Outcome;
use App\Workflow\Presets\Alfa;
use App\Workflow\Stage;
use App\Workflow\Track;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * АльфаСтрахование: на «Подтвердили покупку поставщику» Альфа отвечает «Просьба прислать реквизиты для выставления счёта»
 * (03.11.2026, владелец: кнопка «Ответ поставщика получен» вела сразу в «Счёт выставлен менеджеру», а реквизиты брать было
 * неоткуда). Вместо неё:
 * — «Поставщик просит реквизиты»: «страховой напрямую» → «Данные покупателя для счёта» (ход менеджера, `asks = buyer`),
 *   платим мы (Р.О.С., ПРАЙМ) → сразу «Реквизиты поставщику» (письмо с нашими реквизитами);
 * — «Поставщик сразу выставил счёт» — прежний путь;
 * — у гаражной ветки — «Реквизиты поставщику, в гараж».
 * Шаги — новым блоком «Счёт от поставщика» перед «Оплатой». Подпись трогаем, только если она прежняя: правленое руками
 * остаётся. Идущие сделки стоят, где стояли, и видят новые кнопки.
 */
return new class extends Migration
{
    public function up(): void
    {
        $preset = new Alfa;
        $letter = $preset->letters()['requisites'];
        $template = Template::firstOrCreate(['name' => $letter['name'], 'scope' => Scope::Offers], $letter);
        $stages = Alfa::requisites();

        foreach (Vendor::where('pay_before_pickup', true)->whereNull('crm_vendor_id')->get() as $vendor) {
            $workflow = $vendor->workflow(Track::Sale);
            if (! $workflow || Stage::where('workflow_id', $workflow->id)->where('name', $stages['buyer_data']['name'])->exists()) {
                continue;
            }
            DB::transaction(function () use ($workflow, $template, $stages) {
                $all = Stage::where('workflow_id', $workflow->id)->with('exits')->get()->keyBy('name');
                $confirmed = $all['Подтвердили покупку поставщику'] ?? null;
                $invoice = $all['Счёт выставлен менеджеру'] ?? null;
                $answer = $confirmed?->exits->first(fn (Outcome $e) => mb_strtolower(trim($e->label)) === 'ответ поставщика получен');
                if (! $confirmed || ! $invoice || ! $answer) {
                    return;
                }

                // Блок «Счёт от поставщика» — сразу перед блоком оплаты.
                $payBlock = Block::find($invoice->block_id);
                Block::where('workflow_id', $workflow->id)->where('position', '>=', $payBlock->position)->increment('position');
                $block = Block::create(['workflow_id' => $workflow->id, 'position' => $payBlock->position] + Alfa::REQUISITES_BLOCK);

                $row = fn (array $s, int $position, ?int $templateId) => [
                    'workflow_id' => $workflow->id, 'block_id' => $block->id, 'position' => $position, 'name' => $s['name'],
                    'waits_for' => $s['waits_for'], 'limit_minutes' => $s['limit_minutes'], 'deadline_source' => 'own', 'asks' => $s['asks'] ?? 'nothing',
                    'ask_title' => $s['ask_title'] ?? null, 'ask_text' => $s['ask_text'] ?? null, 'fields' => [], 'staff_fields' => [], 'template_id' => $templateId,
                ];
                $data = Stage::create($row($stages['buyer_data'], 0, null));
                $letter = Stage::create($row($stages['requisites'], 1, $template->id));
                $data->exits()->create(['label' => 'Данные покупателя заполнены', 'actor' => 'manager', 'to_stage_id' => $letter->id, 'position' => 0]);
                $letter->exits()->create(['label' => 'Счёт от поставщика получен', 'actor' => 'staff', 'to_stage_id' => $invoice->id, 'position' => 0]);

                Outcome::where('stage_id', $confirmed->id)->where('id', '!=', $answer->id)->increment('position', 2);
                $confirmed->exits()->create(['label' => Alfa::ASKS_REQUISITES, 'actor' => 'staff', 'to_stage_id' => $data->id, 'position' => 0, 'branch' => Outcome::NOT_ROS]);
                $confirmed->exits()->create(['label' => Alfa::ASKS_REQUISITES, 'actor' => 'staff', 'to_stage_id' => $letter->id, 'position' => 1, 'branch' => Outcome::ROS]);
                $answer->update(['label' => 'Поставщик сразу выставил счёт', 'position' => 2]);

                $this->garage($all, $template);
                app(RevalidateWorkflow::class)($workflow);
            });
        }
    }

    /** Гаражная «платим мы»: письмо с нашими реквизитами между «Подтвердили покупку» и «Оплатой поставщику». */
    private function garage($all, Template $template): void
    {
        $confirmed = $all['Подтвердили покупку поставщику, в гараж'] ?? null;
        $answer = $confirmed?->exits->first(fn (Outcome $e) => mb_strtolower(trim($e->label)) === 'ответ поставщика получен');
        if (! $confirmed || ! $answer || ! $answer->to_stage_id) {
            return;
        }
        Stage::where('block_id', $confirmed->block_id)->where('position', '>', $confirmed->position)->increment('position');
        $letter = Stage::create([
            'workflow_id' => $confirmed->workflow_id, 'block_id' => $confirmed->block_id, 'position' => $confirmed->position + 1,
            'name' => 'Реквизиты поставщику, в гараж', 'waits_for' => 'supplier', 'limit_minutes' => 2880, 'deadline_source' => 'own',
            'asks' => 'nothing', 'fields' => [], 'staff_fields' => [], 'template_id' => $template->id,
        ]);
        $letter->exits()->create(['label' => 'Счёт от поставщика получен', 'actor' => 'staff', 'to_stage_id' => $answer->to_stage_id, 'position' => 0]);
        Outcome::where('stage_id', $confirmed->id)->where('id', '!=', $answer->id)->increment('position');
        $confirmed->exits()->create(['label' => Alfa::ASKS_REQUISITES, 'actor' => 'staff', 'to_stage_id' => $letter->id, 'position' => 0]);
        $answer->update(['label' => 'Поставщик сразу выставил счёт', 'position' => 1]);
    }

    public function down(): void {}
};
