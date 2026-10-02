<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Группа модераторов (02.10.2026, владелец: Елизавета и Алина работают над одними предложениями и должны видеть и
 * править предложения друг друга). Группа без названия — общий номер `crm_team_id`; его же задаёт ссылка-приглашение.
 * Кто уже модератор, тот работает вместе: все в одной группе, номер — меньший id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('crm_team_id')->nullable()->index();
        });
        Schema::table('invites', function (Blueprint $table) {
            $table->unsignedBigInteger('crm_team_id')->nullable();
        });
        $ids = DB::table('users')->where('role', 'moderator')->pluck('id');
        if ($ids->count() > 1) {
            DB::table('users')->whereIn('id', $ids)->update(['crm_team_id' => $ids->min()]);
        }
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('crm_team_id'));
        Schema::table('invites', fn (Blueprint $table) => $table->dropColumn('crm_team_id'));
    }
};
