<?php

use App\Offers\Deal;
use App\Offers\DealState;
use App\Vendors\Vendor;
use App\Workflow\Actions\RevalidateWorkflow;
use App\Workflow\Outcome;
use App\Workflow\Stage;
use App\Workflow\Track;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * АльфаСтрахование: забирать — только после оплаты, с НДС — через ООО «Р.О.С.» (10.10.2026, владелец).
 * — `vendors.pay_before_pickup` (Альфа Москва и СПб) и `deals.prepaid_at` — когда маршрут прошёл проверку оплаты;
 * — у шага оплаты Альфы развилка: Р.О.С. (`DealScheme::Ros`) — сначала «Перевод поставщику с Р.О.С.» (наш), остальные —
 *   сразу к подтверждению оплаты Альфой;
 * — строка реквизитов Р.О.С. (`billing_parties`, seller = ros) — её заполнят в «Реквизитах»;
 * — идущим сделкам Альфы, что уже прошли оплату, — `prepaid_at` по журналу. Схемы идущих сделок не меняем.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('vendors', 'pay_before_pickup')) {
            Schema::table('vendors', fn (Blueprint $t) => $t->boolean('pay_before_pickup')->default(false));
        }
        if (! Schema::hasColumn('deals', 'prepaid_at')) {
            Schema::table('deals', fn (Blueprint $t) => $t->timestamp('prepaid_at')->nullable());
        }
        // Строку Р.О.С. в `billing_parties` заводит `Party::seller` при первом обращении (здесь через модель нельзя: у неё
        // колонки поздних миграций, на свежей базе их ещё нет).

        foreach (Vendor::whereRaw("name ilike '%альфа%'")->get() as $vendor) {
            DB::table('vendors')->where('id', $vendor->id)->update(['pay_before_pickup' => true]);
            $workflow = $vendor->workflow(Track::Sale);
            if ($workflow) {
                DB::transaction(fn () => $this->route($workflow));
            }
            $this->prepaid($vendor);
        }
    }

    private function route($workflow): void
    {
        $stages = Stage::where('workflow_id', $workflow->id)->with('exits')->get()->keyBy('name')->toBase();
        $invoice = $stages['Счёт выставлен менеджеру'] ?? null;
        $check = $stages['Подтверждение оплаты поставщиком'] ?? null;
        if (! $invoice || ! $check || $stages->has('Перевод поставщику с Р.О.С.')) {
            return;
        }
        $pay = $invoice->exits->first(fn (Outcome $e) => $e->to_stage_id === $check->id && str_starts_with(mb_strtolower($e->label), 'платёжное поручение'));
        if (! $pay) {
            return;
        }
        $ros = Stage::create([
            'workflow_id' => $workflow->id, 'block_id' => $invoice->block_id, 'position' => $invoice->position + 1, 'name' => 'Перевод поставщику с Р.О.С.',
            'waits_for' => 'us', 'limit_minutes' => 1440, 'deadline_source' => 'own', 'asks' => 'nothing',
            'fields' => [], 'staff_fields' => Stage::keyFields([['label' => 'Дата перевода'], ['label' => 'Номер платёжки']]),
        ]);
        $ros->exits()->create(['label' => 'Переведено поставщику', 'actor' => 'staff', 'to_stage_id' => $check->id, 'position' => 0]);
        // Проверка оплаты Альфой — после перевода: сдвигаем её и всё, что за счётом в том же блоке.
        Stage::where('block_id', $invoice->block_id)->where('id', '!=', $ros->id)->where('position', '>', $invoice->position)->increment('position');
        $pay->update(['branch' => Outcome::NOT_ROS]);
        $invoice->exits()->create(['label' => $pay->label, 'actor' => $pay->actor, 'to_stage_id' => $ros->id, 'position' => $pay->position, 'branch' => Outcome::ROS]);
        app(RevalidateWorkflow::class)($workflow);
    }

    /** Прошли «Оплата получена» («Поставщику оплачено» у гаражной «платим мы») — можно забирать. */
    private function prepaid(Vendor $vendor): void
    {
        $deals = Deal::where('state', DealState::Active)->whereNull('prepaid_at')
            ->whereHas('offer', fn ($q) => $q->where('vendor_id', $vendor->id))->get();
        foreach ($deals as $deal) {
            $at = DB::table('offer_events')->where('offer_id', $deal->offer_id)->where('type', 'stage_entered')
                ->where('created_at', '>=', $deal->created_at)
                ->whereRaw("lower(payload->>'exit') in ('оплата получена', 'поставщику оплачено')")->min('created_at');
            if ($at) {
                DB::table('deals')->where('id', $deal->id)->update(['prepaid_at' => $at]);
            }
        }
    }

    public function down(): void {}
};
