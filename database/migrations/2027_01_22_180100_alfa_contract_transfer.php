<?php

use App\Vendors\Vendor;
use App\Workflow\Actions\RevalidateWorkflow;
use App\Workflow\Stage;
use App\Workflow\Track;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Альфа, 18.10.2026 (владелец): документы ходят по-разному — оригинал нам, скан, напрямую покупателю; «обще назвать
 * передача договора, а дальше через чат». Место подписания, запись на приём, оригиналы СДЭКом и подписание — один этап
 * «Передача договора» без срока: менеджер «Договор у покупателя» или мы «Договор передан» → «Сделка закрыта». Строка
 * «Подписание документов» переиспользуется (журнал и просьбы остаются), остальные этапы уходят.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (Vendor::where('pay_before_pickup', true)->whereNull('crm_vendor_id')->get() as $vendor) {
            $workflow = $vendor->workflow(Track::Sale);
            if (! $workflow) {
                continue;
            }
            $stages = Stage::where('workflow_id', $workflow->id)->get()->keyBy('name')->toBase();
            $signing = $stages['Подписание документов'] ?? null;
            $closed = $stages['Сделка закрыта'] ?? null;
            if (! $signing || ! $closed) {
                continue;
            }
            $doomed = $stages->only(['Место подписания документов', 'Запись на приём', 'Адрес для отправки оригиналов', 'Отправка оригиналов'])->pluck('id');
            DB::transaction(function () use ($workflow, $signing, $closed, $doomed) {
                DB::table('workflow_blocks')->where('id', $signing->block_id)->update(['text' => 'Договор передаст поставщик или XCar, детали в чате']);
                $signing->update([
                    'name' => 'Передача договора', 'position' => 0, 'waits_for' => 'manager', 'limit_minutes' => null, 'asks' => 'nothing',
                    'ask_title' => 'Получите договор', 'ask_text' => 'Оплата принята. Договор передаст поставщик или XCar, детали в чате',
                    'fields' => [], 'staff_fields' => [],
                ]);
                $signing->exits()->delete();
                $signing->exits()->create(['label' => 'Договор у покупателя', 'actor' => 'manager', 'to_stage_id' => $closed->id, 'position' => 0]);
                $signing->exits()->create(['label' => 'Договор передан', 'actor' => 'staff', 'to_stage_id' => $closed->id, 'position' => 1]);

                // Входы в убранные этапы («Оплата получена») — в передачу договора; их сделки и просьбы — туда же.
                DB::table('workflow_exits')->whereIn('to_stage_id', $doomed)->whereNotIn('stage_id', $doomed)->update(['to_stage_id' => $signing->id]);
                DB::table('offer_positions')->whereIn('stage_id', $doomed->push($signing->id))->update(['stage_id' => $signing->id, 'deadline_at' => null, 'overdue_at' => null]);
                $doomed->pop();
                DB::table('requirements')->whereIn('stage_id', $doomed)->update(['stage_id' => $signing->id]);
                Stage::whereIn('id', $doomed)->delete();
                app(RevalidateWorkflow::class)($workflow);
            });
        }
    }

    public function down(): void {}
};
