<?php

use App\Mail\Scope;
use App\Mail\Template;
use App\Vendors\Vendor;
use App\Workflow\Actions\RevalidateWorkflow;
use App\Workflow\Presets\Alfa;
use App\Workflow\Requirement;
use App\Workflow\Stage;
use App\Workflow\Track;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Альфа, 11.11.2026 (BMW X6 № 2610041185): после «Оплата получена» Альфа ещё несколько дней готовит договор — ждём её, а не
 * менеджера; потом спрашиваем свободное время, менеджер выбирает с покупателем, записываем, подписание. «Передача договора»
 * переиспользуется как «Подготовка договора» (журнал и позиции остаются), за ней — четыре новых этапа (`Alfa::signing`).
 * Открытые просьбы «Получите договор» закрываются — кнопки «Договор у покупателя» в чате гаснут.
 */
return new class extends Migration
{
    public function up(): void
    {
        $preset = new Alfa;
        $letters = collect(['slots', 'appointment'])->mapWithKeys(fn ($k) => [$k => Template::firstOrCreate(
            ['name' => $preset->letters()[$k]['name'], 'scope' => Scope::Offers], $preset->letters()[$k])->id]);
        $rows = Alfa::signing();

        foreach (Vendor::where('pay_before_pickup', true)->whereNull('crm_vendor_id')->get() as $vendor) {
            $workflow = $vendor->workflow(Track::Sale);
            if (! $workflow) {
                continue;
            }
            $all = Stage::where('workflow_id', $workflow->id)->get()->keyBy('name');
            $prep = $all['Передача договора'] ?? null;
            $closed = $all['Сделка закрыта'] ?? null;
            if (! $prep || ! $closed || isset($all[$rows['slots']['name']])) {
                continue;
            }
            DB::transaction(function () use ($workflow, $prep, $closed, $rows, $letters) {
                DB::table('workflow_blocks')->where('id', $prep->block_id)->update(['text' => Alfa::SIGNING_TEXT]);
                $c = $rows['contract'];
                $prep->update(['name' => $c['name'], 'position' => 0, 'waits_for' => $c['waits_for'], 'limit_minutes' => null, 'asks' => 'nothing',
                    'ask_title' => null, 'ask_text' => $c['ask_text'], 'fields' => [], 'staff_fields' => [], 'template_id' => null]);
                Stage::where('block_id', $prep->block_id)->where('id', '!=', $prep->id)->increment('position', 10);

                $stages = ['contract' => $prep, 'closed_won' => $closed];
                $position = 1;
                foreach (['slots', 'signing_time', 'appointment', 'signing'] as $key) {
                    $s = $rows[$key];
                    $stages[$key] = Stage::create([
                        'workflow_id' => $workflow->id, 'block_id' => $prep->block_id, 'position' => $position++, 'name' => $s['name'],
                        'waits_for' => $s['waits_for'], 'limit_minutes' => $s['limit_minutes'] ?? null, 'deadline_source' => 'own',
                        'asks' => $s['asks'] ?? 'nothing', 'ask_title' => $s['ask_title'] ?? null, 'ask_text' => $s['ask_text'] ?? null,
                        'fields' => Stage::keyFields($s['fields'] ?? []), 'staff_fields' => [],
                        'template_id' => isset($s['letter']) ? $letters[$s['letter']] : null,
                    ]);
                }
                $prep->exits()->delete();
                foreach (['contract', 'slots', 'signing_time', 'appointment', 'signing'] as $key) {
                    foreach ($rows[$key]['exits'] as $i => [$label, $actor, $to]) {
                        $stages[$key]->exits()->create(['label' => $label, 'actor' => $actor, 'to_stage_id' => $stages[$to]->id, 'position' => $i]);
                    }
                }

                // Сделки на этапе — ждут Альфу без срока; просьбы «Получите договор» — закрыты этапом.
                DB::table('offer_positions')->where('stage_id', $prep->id)->update(['deadline_at' => null, 'overdue_at' => null]);
                $open = Requirement::where('stage_id', $prep->id)->whereNull('done_at')->pluck('id');
                Requirement::whereIn('id', $open)->update(['done_at' => now(), 'answer' => json_encode(['closed_by' => 'stage'])]);
                Requirement::redrawFor($open);
                app(RevalidateWorkflow::class)($workflow);
            });
        }
    }

    public function down(): void {}
};
