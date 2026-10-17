<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Срок этапа в рабочих днях (выходные не считаются, `Workflow\Stage::addLimit`) — выбор у каждого этапа. Заодно: шаги, где
 * поставщик уже ответил и ход наш, больше не держат срок поставщика (`AdvanceOnReply` теперь его снимает) — краснели «на нас».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_stages', fn (Blueprint $t) => $t->boolean('limit_workdays')->default(false)->after('limit_minutes'));
        DB::table('offer_positions')->where('waits_for', 'us')->whereNotNull('deadline_at')->whereRaw("jsonb_exists(payload, 'reply_at')")
            ->update(['deadline_at' => null, 'reminded_at' => null, 'overdue_at' => null]);
    }

    public function down(): void
    {
        Schema::table('workflow_stages', fn (Blueprint $t) => $t->dropColumn('limit_workdays'));
    }
};
