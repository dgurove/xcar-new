<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Группы — в «Пользователях» (02.10.2026, владелец): группы менеджеров (волны показа) и группы модераторов (видят и правят
 * предложения друг друга) — одна таблица с видом `kind`, раздельные. Безымянный `users.crm_team_id` переезжает сюда:
 * первая группа модераторов — «XCar Москва» (Елизавета и Алина), остальные — «Группа N». Ссылка-приглашение сразу в
 * группу — `invites.user_group_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('manager_groups', 'user_groups');
        Schema::rename('manager_group_user', 'user_group_user');
        Schema::table('user_groups', function (Blueprint $table) {
            $table->string('kind', 20)->default('managers')->index();
        });

        $teams = DB::table('users')->where('role', 'moderator')->whereNotNull('crm_team_id')->orderBy('crm_team_id')->get(['id', 'crm_team_id'])->groupBy('crm_team_id');
        $n = 0;
        foreach ($teams as $members) {
            $n++;
            $group = DB::table('user_groups')->insertGetId([
                'name' => $n === 1 ? 'XCar Москва' : 'Группа '.$n, 'kind' => 'moderators', 'position' => $n, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('user_group_user')->insert($members->map(fn ($u) => ['group_id' => $group, 'user_id' => $u->id])->all());
        }

        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('crm_team_id'));
        Schema::table('invites', function (Blueprint $table) {
            $table->dropColumn('crm_team_id');
            $table->foreignId('user_group_id')->nullable()->constrained('user_groups')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invites', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_group_id');
            $table->unsignedBigInteger('crm_team_id')->nullable();
        });
        Schema::table('users', fn (Blueprint $table) => $table->unsignedBigInteger('crm_team_id')->nullable()->index());
        DB::table('user_groups')->where('kind', 'moderators')->delete();
        Schema::table('user_groups', fn (Blueprint $table) => $table->dropColumn('kind'));
        Schema::rename('user_group_user', 'manager_group_user');
        Schema::rename('user_groups', 'manager_groups');
    }
};
