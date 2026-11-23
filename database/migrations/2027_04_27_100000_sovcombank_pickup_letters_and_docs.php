<?php

use App\Logistics\PickupLetters;
use App\Mail\Scope;
use App\Mail\Template;
use App\Vendors\DealFormat;
use App\Vendors\Vendor;
use App\Workflow\Actions\RevalidateWorkflow;
use App\Workflow\Presets\Sovcombank;
use App\Workflow\Stage;
use App\Workflow\Track;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Совкомбанк (23.11.2026, разбор писем и сделок): письма о вывозе и документы после оплаты.
 * - `vendors.pickup_letters` — дела «Сообщить поставщику дату вывоза» и «Отправить акт вывоза» (`Logistics\PickupLetters`),
 *   Совкомбанку включено; отметки на вывозе — `transports.supplier_told_at|supplier_told_for|supplier_act_at`. Прошлое закрыто:
 *   дата — у вывозов, чей день прошёл или уже забранных, акт — у забранных раньше недели (свежие — делом: Совкомбанк их и спрашивает).
 * - Этап «Документы поставщику» после «Оплаты поставщику»: письмо с договором и актом, номер платёжки — в тексте; у «Оплаты
 *   поставщику» письма больше нет (она проходит сама по выписке).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendors', fn (Blueprint $t) => $t->boolean('pickup_letters')->default(false));
        Schema::table('transports', function (Blueprint $t) {
            $t->timestamp('supplier_told_at')->nullable();
            $t->timestamp('supplier_told_for')->nullable();
            $t->timestamp('supplier_act_at')->nullable();
            // Письмо, что закрыло дело: не ушло — отметка снимается (`MarkPickupLetter::failed`).
            $t->unsignedBigInteger('supplier_told_message_id')->nullable()->index();
            $t->unsignedBigInteger('supplier_act_message_id')->nullable()->index();
        });
        DB::table('transports')->whereNotNull('pickup_at')->where(fn ($q) => $q->where('pickup_at', '<', now()->startOfDay())->orWhereNotNull('picked_at'))
            ->update(['supplier_told_at' => now(), 'supplier_told_for' => DB::raw('pickup_at')]);
        DB::table('transports')->whereNotNull('picked_at')->where('picked_at', '<', now()->subWeek())->update(['supplier_act_at' => now()]);

        foreach (PickupLetters::LETTERS as $letter) {
            Template::firstOrCreate(['name' => $letter['name'], 'scope' => Scope::Offers], $letter + ['kind' => Template::LETTER]);
        }

        $vendor = Vendor::where('deal_format', DealFormat::Commission)->whereRaw("name ilike '%совком%'")->first();
        if (! $vendor) {
            return;
        }
        $vendor->update(['pickup_letters' => true]);
        $workflow = $vendor->workflow(Track::Sale);
        $stages = $workflow ? Stage::where('workflow_id', $workflow->id)->with('exits')->get()->keyBy('name') : collect();
        $pay = $stages['Оплата поставщику'] ?? null;
        $closed = $stages['Сделка закрыта'] ?? null;
        if (! $pay || ! $closed || isset($stages['Документы поставщику'])) {
            return;
        }
        $letter = (new Sovcombank)->letters()['docs'];
        DB::transaction(function () use ($workflow, $pay, $closed, $letter) {
            $template = Template::firstOrCreate(['name' => $letter['name'], 'scope' => Scope::Offers], $letter + ['kind' => Template::LETTER]);
            Stage::where('block_id', $pay->block_id)->where('position', '>', $pay->position)->increment('position');
            $docs = Stage::create([
                'workflow_id' => $workflow->id, 'block_id' => $pay->block_id, 'position' => $pay->position + 1, 'name' => 'Документы поставщику',
                'waits_for' => 'supplier', 'limit_minutes' => 2 * 1440, 'deadline_source' => 'own', 'asks' => 'nothing',
                'fields' => [], 'staff_fields' => [], 'template_id' => $template->id,
            ]);
            $docs->exits()->create(['label' => Sovcombank::DOCS_SENT, 'actor' => 'staff', 'to_stage_id' => $closed->id, 'position' => 0]);
            $pay->exits()->where('to_stage_id', $closed->id)->update(['to_stage_id' => $docs->id]);
            $pay->update(['template_id' => null]);
            app(RevalidateWorkflow::class)($workflow);
        });
    }

    public function down(): void
    {
        // Маршрут Совкомбанка — как был: «Поставщику оплачено» снова закрывает сделку, письмо об оплате — у «Оплаты поставщику»;
        // сделки, что стоят на «Документах поставщику», — обратно на «Оплату поставщику».
        $vendor = Vendor::where('deal_format', DealFormat::Commission)->whereRaw("name ilike '%совком%'")->first();
        $workflow = $vendor?->workflow(Track::Sale);
        $stages = $workflow ? Stage::where('workflow_id', $workflow->id)->get()->keyBy('name') : collect();
        if (($docs = $stages['Документы поставщику'] ?? null) && ($pay = $stages['Оплата поставщику'] ?? null) && ($closed = $stages['Сделка закрыта'] ?? null)) {
            DB::transaction(function () use ($workflow, $docs, $pay, $closed) {
                DB::table('workflow_exits')->where('stage_id', $pay->id)->where('to_stage_id', $docs->id)->update(['to_stage_id' => $closed->id]);
                DB::table('offer_positions')->where('stage_id', $docs->id)->update(['stage_id' => $pay->id, 'payload' => null, 'waits_for' => null]);
                DB::table('requirements')->where('stage_id', $docs->id)->update(['stage_id' => $pay->id]);
                $pay->update(['template_id' => Template::where('name', 'Поставщику: документ об оплате')->where('scope', Scope::Offers)->value('id')]);
                $docs->delete();
                app(RevalidateWorkflow::class)($workflow);
            });
        }
        Schema::table('transports', fn (Blueprint $t) => $t->dropColumn(['supplier_told_at', 'supplier_told_for', 'supplier_act_at', 'supplier_told_message_id', 'supplier_act_message_id']));
        Schema::table('vendors', fn (Blueprint $t) => $t->dropColumn('pickup_letters'));
    }
};
