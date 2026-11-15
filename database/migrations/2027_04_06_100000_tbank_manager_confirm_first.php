<?php

use App\Mail\Scope;
use App\Mail\Template;
use App\Offers\DealState;
use App\Vendors\Vendor;
use App\Workflow\Actions\EnterStage;
use App\Workflow\Actions\RevalidateWorkflow;
use App\Workflow\Position;
use App\Workflow\Presets\TBank;
use App\Workflow\Stage;
use App\Workflow\Track;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Т-Страхование, ПСБ, РСХБ, ЗЕСТ (маршрут `TBank`): сначала согласие менеджера, потом письмо поставщику (15.11.2026,
 * владелец: «как и у Альфы СНАЧАЛА у менеджера спросить, берёт ли он в работу, и ТОЛЬКО после этого письмо»). «Подтверждение
 * принято» ведёт в новый этап «Согласие менеджера» перед «Письмом о покупке»; письмо этапа — одинаковый текст «Прошу прислать
 * контакты и данные для организации сделки» (шаблон «Поставщику: запрос контактов»). Сделки на «Письме о покупке», где письмо
 * не ушло, — на вопрос менеджеру; кроме AUT-26-920056 (владелец: «там менеджер уже согласился, его не надо спрашивать»).
 * Отправленные и ответы поставщика не трогаем.
 */
return new class extends Migration
{
    private const VENDORS = ['Т-Страхование', 'ПСБ Страхование', 'РСХБ-Страхование', 'ЗЕСТ'];

    private const AGREED = ['AUT-26-920056'];

    public function up(): void
    {
        $letter = (new TBank)->letters()['contacts'];
        $template = Template::firstOrCreate(['name' => $letter['name'], 'scope' => Scope::Offers], $letter);

        foreach (Vendor::whereIn('name', self::VENDORS)->get() as $vendor) {
            $workflow = $vendor->workflow(Track::Sale);
            if (! $workflow) {
                continue;
            }
            $stages = Stage::where('workflow_id', $workflow->id)->with('exits')->get()->keyBy('name')->toBase();
            $claimed = $stages['Письмо о покупке'] ?? null;
            $bidding = $stages['Приём подтверждений'] ?? null;
            if (! $claimed || ! $bidding || isset($stages['Согласие менеджера'])) {
                continue;
            }
            DB::transaction(function () use ($workflow, $claimed, $bidding, $template) {
                $claimed->update(['template_id' => $template->id]);
                // Согласие — в блоке письма, перед ним.
                Stage::where('block_id', $claimed->block_id)->where('position', '>=', $claimed->position)->increment('position');
                $consent = Stage::create([
                    'workflow_id' => $workflow->id, 'block_id' => $claimed->block_id, 'position' => $claimed->position,
                    'name' => 'Согласие менеджера', 'waits_for' => 'manager', 'deadline_source' => 'next_day_17', 'offer_state' => 'sold',
                    'ask_title' => 'Подтвердите, что берёте в работу',
                    'ask_text' => 'Подтверждение принято. Подтвердите, что берёте автомобиль в работу, после этого напишем поставщику',
                    'asks' => 'nothing', 'fields' => [], 'staff_fields' => [],
                ]);
                $consent->exits()->create(['label' => 'Беру в работу', 'actor' => 'manager', 'to_stage_id' => $claimed->id, 'position' => 0]);
                $consent->exits()->create(['label' => 'Я передумал', 'actor' => 'manager', 'to_stage_id' => $bidding->id, 'position' => 1]);
                DB::table('workflow_exits')->where('to_stage_id', $claimed->id)->where('label', 'Подтверждение принято')->update(['to_stage_id' => $consent->id]);

                $enter = app(EnterStage::class);
                Position::where('stage_id', $claimed->id)->where('track', Track::Sale->value)->with(['stage', 'offer.deal'])->get()
                    ->filter(fn (Position $p) => $p->letterStatus() === 'unsent' && empty($p->payload['letter_by_hand'])
                        && $p->offer?->deal?->state === DealState::Active && ! in_array($p->offer->claim_ref, self::AGREED, true))
                    ->each(fn (Position $p) => $enter($p->offer, $consent->fresh('exits')));

                app(RevalidateWorkflow::class)($workflow);
            });
        }
    }

    public function down(): void {}
};
