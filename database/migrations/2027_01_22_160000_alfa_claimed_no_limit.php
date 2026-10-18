<?php

use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use App\Workflow\Actions\SetStageLimit;
use App\Workflow\Stage;
use App\Workflow\Track;
use Illuminate\Database\Migrations\Migration;

/**
 * «Уведомили поставщика о покупке» у Альфы — без срока (18.10.2026, владелец): Альфа на deal@ не отвечает, все сделки на
 * этапе горели просроченными. Срок снимается и у сделок, что уже стоят на этапе (`SetStageLimit` → `RetimeStage`).
 */
return new class extends Migration
{
    public function up(): void
    {
        $by = User::withRole(Role::Admin)->orderBy('id')->first();
        if (! $by) {
            return;
        }
        foreach (Vendor::where('pay_before_pickup', true)->get() as $vendor) {
            $workflow = $vendor->workflow(Track::Sale);
            $stage = $workflow ? Stage::where('workflow_id', $workflow->id)->where('name', 'Уведомили поставщика о покупке')->first() : null;
            if ($stage && $stage->limit_minutes !== null) {
                app(SetStageLimit::class)($stage, null, false, true, $by);
            }
        }
    }
};
