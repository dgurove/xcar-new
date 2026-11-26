<?php

use App\Vendors\DealFormat;
use App\Vendors\Vendor;
use App\Workflow\Actions\RevalidateWorkflow;
use App\Workflow\Stage;
use App\Workflow\Track;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Оригиналы документов сделки Совкомбанка (26.11.2026, владелец: «сделка не закрыта, пока оригиналы не получили»). Какие
 * документы и куда — `Offers\Originals::kit` (по письмам сначала было «договор комиссии и акт — в Совкомбанк», Катя поправила
 * в тот же день: к нам). `deal_originals` — документ, куда он должен попасть (`to`), где сейчас (`state`), трек СДЭК. Шаг
 * «Оригиналы» — после «Документов поставщику», перед закрытием: всё дошло — сделка закрывается.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deal_originals', function (Blueprint $t) {
            $t->id();
            $t->foreignId('deal_id')->constrained()->cascadeOnDelete();
            $t->string('kind', 20);
            $t->string('title', 200);
            $t->string('to', 10);
            $t->string('state', 10)->default('waiting');
            $t->string('track', 60)->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->timestamp('done_at')->nullable();
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index('deal_id');
        });

        $vendor = Vendor::where('deal_format', DealFormat::Commission)->whereRaw("name ilike '%совком%'")->first();
        $workflow = $vendor?->workflow(Track::Sale);
        $stages = $workflow ? Stage::where('workflow_id', $workflow->id)->with('exits')->get()->keyBy('name') : collect();
        $docs = $stages['Документы поставщику'] ?? null;
        $closed = $stages['Сделка закрыта'] ?? null;
        if (! $docs || ! $closed || isset($stages[\App\Offers\Originals::STAGE])) {
            return;
        }
        DB::transaction(function () use ($workflow, $docs, $closed) {
            Stage::where('block_id', $docs->block_id)->where('position', '>', $docs->position)->increment('position');
            $originals = Stage::create([
                'workflow_id' => $workflow->id, 'block_id' => $docs->block_id, 'position' => $docs->position + 1, 'name' => \App\Offers\Originals::STAGE,
                'waits_for' => 'us', 'limit_minutes' => 14 * 1440, 'deadline_source' => 'own', 'asks' => 'nothing', 'fields' => [], 'staff_fields' => [],
            ]);
            $originals->exits()->create(['label' => \App\Offers\Originals::DONE, 'actor' => 'staff', 'to_stage_id' => $closed->id, 'position' => 0]);
            $docs->exits()->where('to_stage_id', $closed->id)->update(['to_stage_id' => $originals->id]);
            app(RevalidateWorkflow::class)($workflow);
        });
    }

    public function down(): void
    {
        $vendor = Vendor::where('deal_format', DealFormat::Commission)->whereRaw("name ilike '%совком%'")->first();
        $workflow = $vendor?->workflow(Track::Sale);
        $stages = $workflow ? Stage::where('workflow_id', $workflow->id)->get()->keyBy('name') : collect();
        if (($originals = $stages[\App\Offers\Originals::STAGE] ?? null) && ($closed = $stages['Сделка закрыта'] ?? null) && ($docs = $stages['Документы поставщику'] ?? null)) {
            $docs->exits()->where('to_stage_id', $originals->id)->update(['to_stage_id' => $closed->id]);
            \App\Workflow\Position::where('stage_id', $originals->id)->update(['stage_id' => $docs->id]);
            $originals->exits()->delete();
            $originals->delete();
        }
        Schema::dropIfExists('deal_originals');
    }
};
