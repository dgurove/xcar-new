<?php

use App\Users\Role;
use App\Users\User;
use App\Workflow\Actions\SetStageLimit;
use App\Workflow\Position;
use App\Workflow\Stage;
use Illuminate\Database\Migrations\Migration;

/**
 * Наш ход без срока не виден нигде (18.10.2026, владелец: «свой срок»): письмо поставщику, что не ушло, — срок от входа в
 * этап (`Stage::letterDeadline`); «Собираем заявку поставщику» (Каркаде) — двое суток, и у сделок, что уже стоят на этапе.
 */
return new class extends Migration
{
    public function up(): void
    {
        Position::whereNull('deadline_at')->whereHas('stage', fn ($q) => $q->whereNotNull('template_id')->where('waits_for', 'supplier'))
            ->whereHas('offer.deal', fn ($q) => $q->where('state', 'active'))->with('stage')->get()
            ->filter(fn (Position $p) => $p->letterStatus() === 'unsent')
            ->each(fn (Position $p) => $p->update(['deadline_at' => Stage::letterDeadline($p->entered_at), 'reminded_at' => null, 'overdue_at' => null]));

        $by = User::withRole(Role::Admin)->orderBy('id')->first();
        if (! $by) {
            return;
        }
        foreach (Stage::where('name', 'Собираем заявку поставщику')->whereNull('limit_minutes')->get() as $stage) {
            app(SetStageLimit::class)($stage, 2 * 24 * 60, false, true, $by);
        }
    }
};
