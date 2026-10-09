<?php

use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Vendors\Vendor;
use App\Workflow\Actions\RevalidateWorkflow;
use App\Workflow\Block;
use App\Workflow\Position;
use App\Workflow\Stage;
use App\Workflow\Track;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Каркаде — заявка пачкой (09.10.2026, владелец: «каждый раз разные лоты, 20, 30, иногда 100 машин; приняли — отправляем
 * заявку на все, ответ через несколько дней»). Перед «Согласованием с поставщиком» — «Собираем заявку поставщику» (ход
 * наш, без срока), само согласование — «Заявка у поставщика» без срока. Блоки разные: «идёт N дн» — с отправки заявки.
 * Живые сделки на согласовании заявки ещё не ушли — встают на «Собираем заявку», без уведомлений.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (Vendor::whereRaw("name ilike '%каркаде%' or name ilike '%carcade%'")->get() as $vendor) {
            $workflow = $vendor->workflow(Track::Sale);
            $claimed = $workflow ? Stage::where('workflow_id', $workflow->id)->where('name', 'Согласование с поставщиком')->first() : null;
            $declined = $workflow ? Stage::where('workflow_id', $workflow->id)->where('name', 'Отказ поставщика')->first() : null;
            if (! $claimed || ! $declined || ! $claimed->block) {
                continue;
            }
            DB::transaction(function () use ($workflow, $claimed, $declined) {
                $at = $claimed->block->position;
                Block::where('workflow_id', $workflow->id)->where('position', '>=', $at)->increment('position');
                $block = Block::create(['workflow_id' => $workflow->id, 'name' => 'Собираем заявку поставщику', 'text' => 'Отправим поставщику заявку вместе с другими автомобилями', 'position' => $at]);
                $claimed->block->update(['name' => 'Заявка у поставщика', 'text' => 'Заявка у поставщика, ответ обычно через несколько дней. Счёт придёт, когда поставщик подтвердит']);
                $claimed->update(['name' => 'Заявка у поставщика', 'limit_minutes' => null]);

                $request = Stage::create(['workflow_id' => $workflow->id, 'block_id' => $block->id, 'position' => 0, 'name' => 'Собираем заявку поставщику',
                    'waits_for' => 'us', 'deadline_source' => 'own', 'offer_state' => 'sold', 'asks' => 'nothing', 'fields' => [], 'staff_fields' => []]);
                $request->exits()->create(['label' => 'Заявка отправлена', 'actor' => 'staff', 'to_stage_id' => $claimed->id, 'position' => 0]);
                $request->exits()->create(['label' => 'Поставщик отказал', 'actor' => 'staff', 'to_stage_id' => $declined->id, 'position' => 1]);

                $stages = Stage::where('workflow_id', $workflow->id)->pluck('id');
                DB::table('workflow_exits')->whereIn('stage_id', $stages)->where('label', 'Подтверждение принято')->where('to_stage_id', $claimed->id)
                    ->update(['to_stage_id' => $request->id]);

                $now = now();
                foreach (Position::where('stage_id', $claimed->id)->where('track', Track::Sale->value)->get() as $position) {
                    $position->forceFill(['stage_id' => $request->id, 'waits_for' => null, 'entered_at' => $now, 'block_entered_at' => $now, 'deadline_at' => null,
                        'reminded_at' => null, 'overdue_at' => null])->save();
                    Offer::find($position->offer_id)?->log(OfferEventType::StageEntered, null, ['track' => Track::Sale->value, 'from' => 'Заявка у поставщика',
                        'to' => $request->name, 'block' => $block->name, 'from_id' => $claimed->id, 'to_id' => $request->id]);
                }
            });
            app(RevalidateWorkflow::class)($workflow->fresh());
        }
    }

    public function down(): void {}
};
