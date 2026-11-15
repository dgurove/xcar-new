<?php

use App\Offers\DealState;
use App\Offers\SupplierLetterWait;
use App\Vendors\Vendor;
use App\Workflow\Actions\EnterStage;
use App\Workflow\Actions\RevalidateWorkflow;
use App\Workflow\Position;
use App\Workflow\Stage;
use App\Workflow\Track;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Т-Страхование, ПСБ, РСХБ, ЗЕСТ: перед «Согласием менеджера» — «Ждём письмо поставщика» (15.11.2026, владелец: «ждать письма,
 * менеджеру для решения нужен VIN, а он только в письме, которое мы ждём»; по Kaiyi AUT-26-881707 и Touareg AUT-26-909476
 * вопрос ушёл без письма Т-Страхования и удалён руками). «Подтверждение принято» ведёт в него; письмо уже есть — проходит само
 * (`SupplierLetterWait`). Сделки на «Письме о покупке», где наше письмо не ушло, а письма поставщика нет, — на ожидание.
 */
return new class extends Migration
{
    private const VENDORS = ['Т-Страхование', 'ПСБ Страхование', 'РСХБ-Страхование', 'ЗЕСТ'];

    private const AGREED = ['AUT-26-920056'];

    public function up(): void
    {
        // Письмо шага — как на скриншоте владельца: «Здравствуйте», машина, VIN, номер и закупочная (`{{ floor }}`).
        $letter = (new \App\Workflow\Presets\TBank)->letters()['contacts'];
        DB::table('mail_templates')->where('name', $letter['name'])->where('scope', 'offers')->update(['body' => $letter['body']]);

        foreach (Vendor::whereIn('name', self::VENDORS)->get() as $vendor) {
            $workflow = $vendor->workflow(Track::Sale);
            if (! $workflow) {
                continue;
            }
            $stages = Stage::where('workflow_id', $workflow->id)->with('exits')->get()->keyBy('name')->toBase();
            $consent = $stages['Согласие менеджера'] ?? null;
            $claimed = $stages['Письмо о покупке'] ?? null;
            if (! $consent || ! $claimed || isset($stages[SupplierLetterWait::STAGE])) {
                continue;
            }
            DB::transaction(function () use ($workflow, $consent, $claimed) {
                Stage::where('block_id', $consent->block_id)->where('position', '>=', $consent->position)->increment('position');
                $wait = Stage::create([
                    'workflow_id' => $workflow->id, 'block_id' => $consent->block_id, 'position' => $consent->position,
                    'name' => SupplierLetterWait::STAGE, 'waits_for' => 'supplier', 'deadline_source' => 'own', 'offer_state' => 'sold',
                    'asks' => 'nothing', 'fields' => [], 'staff_fields' => [],
                ]);
                $wait->exits()->create(['label' => SupplierLetterWait::EXIT, 'actor' => 'staff', 'to_stage_id' => $consent->id, 'position' => 0]);
                DB::table('workflow_exits')->where('to_stage_id', $consent->id)->where('label', 'Подтверждение принято')->update(['to_stage_id' => $wait->id]);

                $enter = app(EnterStage::class);
                Position::where('stage_id', $claimed->id)->where('track', Track::Sale->value)->with(['stage', 'offer.deal'])->get()
                    ->filter(fn (Position $p) => $p->letterStatus() === 'unsent' && empty($p->payload['letter_by_hand'])
                        && $p->offer?->deal?->state === DealState::Active && ! in_array($p->offer->claim_ref, self::AGREED, true)
                        && ! SupplierLetterWait::letter($p->offer))
                    ->each(fn (Position $p) => $enter($p->offer, $wait->fresh('exits')));

                app(RevalidateWorkflow::class)($workflow);
            });
        }
    }

    public function down(): void {}
};
