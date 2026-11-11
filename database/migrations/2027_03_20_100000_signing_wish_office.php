<?php

use App\Mail\Scope;
use App\Mail\Template;
use App\Offers\Deal;
use App\Offers\DealState;
use App\Vendors\Vendor;
use App\Workflow\Actions\EnterStage;
use App\Workflow\Actions\RevalidateWorkflow;
use App\Workflow\Position;
use App\Workflow\Presets\Alfa;
use App\Workflow\Stage;
use App\Workflow\Track;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Подписание у поставщика (11.11.2026, BMW X6 № 2610041185). Альфа ответила на «покупатель может в четверг или пятницу»:
 * «напишите желаемую дату и время — свободно, запишем, нет — предложим другое»; владелец: «заранее спрашивать у менеджера не
 * только дату, но и время», «кнопками в сообщении, как „Берём?“».
 * - `deals.signing_asked_at` — спросили менеджера (`AskSigningTime`), `deals.signing_wish` — его ответ `{at, by, saved_at}`;
 * - `chat_messages.ask` — сообщение площадки с кнопками без просьбы шага (`signing` — `x-chat.signing-keys`);
 * - `vendors.signing_office` — филиал: адрес, рабочие дни и часы, «по записи», контакты (`Signing::office`);
 * - путь Альфы: «Договор готов» → «Время подписания» (без «Свободного времени»), у «Записи на подписание» — «Поставщик предложил
 *   другое время» обратно на выбор; сделки со «Свободного времени» — на «Время подписания»; письмо записи — с датой словами.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $t) {
            $t->timestamp('signing_asked_at')->nullable();
            $t->jsonb('signing_wish')->nullable();
        });
        Schema::table('vendors', function (Blueprint $t) {
            $t->jsonb('signing_office')->nullable();
        });
        Schema::table('chat_messages', function (Blueprint $t) {
            $t->string('ask', 20)->nullable();
        });

        $letter = (new Alfa)->letters()['appointment'];
        Template::where('scope', Scope::Offers)->where('name', $letter['name'])->update(['body' => $letter['body']]);
        $rows = Alfa::signing();

        foreach (Vendor::where('pay_before_pickup', true)->whereNull('crm_vendor_id')->get() as $vendor) {
            $workflow = $vendor->workflow(Track::Sale);
            $all = $workflow ? Stage::where('workflow_id', $workflow->id)->get()->keyBy('name') : collect();
            $contract = $all['Подготовка договора'] ?? null;
            $slots = $all['Свободное время для подписания'] ?? null;
            $pick = $all['Время подписания'] ?? null;
            $appointment = $all['Запись на подписание'] ?? null;
            if (! $contract || ! $slots || ! $pick || ! $appointment) {
                continue;
            }
            $moved = DB::transaction(function () use ($workflow, $contract, $slots, $pick, $appointment, $rows) {
                $contract->exits()->where('label', 'Договор готов')->update(['to_stage_id' => $pick->id]);
                $pick->update(['ask_title' => $rows['signing_time']['ask_title'], 'ask_text' => $rows['signing_time']['ask_text']]);
                $appointment->exits()->firstOrCreate(['label' => 'Поставщик предложил другое время'], ['actor' => 'staff', 'to_stage_id' => $pick->id, 'position' => 1]);
                Stage::where('block_id', $slots->block_id)->where('position', '>', $slots->position)->decrement('position');
                $offers = Position::where('stage_id', $slots->id)->pluck('offer_id');
                // Позиции — на выбор времени сразу (этап удаляется); просьба менеджеру и сообщение — ниже, `EnterStage`.
                Position::where('stage_id', $slots->id)->update(['stage_id' => $pick->id]);
                $slots->delete();
                app(RevalidateWorkflow::class)($workflow);

                return $offers;
            });
            // Сделки, что ждали «свободное время», — заново на «Время подписания»: просьба менеджеру с кнопками.
            foreach ($moved as $offerId) {
                $deal = Deal::with('offer')->where('offer_id', $offerId)->where('state', DealState::Active)->first();
                $deal && app(EnterStage::class)($deal->offer, $pick->fresh());
            }
        }
    }

    public function down(): void
    {
        Schema::table('deals', fn (Blueprint $t) => $t->dropColumn(['signing_asked_at', 'signing_wish']));
        Schema::table('vendors', fn (Blueprint $t) => $t->dropColumn('signing_office'));
        Schema::table('chat_messages', fn (Blueprint $t) => $t->dropColumn('ask'));
    }
};
