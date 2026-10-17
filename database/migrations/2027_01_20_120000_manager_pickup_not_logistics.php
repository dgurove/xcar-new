<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Менеджер, что забирает сам, — не логистика (17.10.2026, владелец): «Забрал» он жмёт в своей сделке, логистику не видит.
 * Уведомления логистики, что успели уйти менеджерам («Вам вывоз», «Не вывезли в срок»), ведут на скрытую задачу — долой.
 */
return new class extends Migration
{
    public function up(): void
    {
        $logistics = "jsonb_exists_any(roles, array['admin','logist','driver','contractor','moderator'])";
        DB::table('notifications')->where('type', 'App\\Logistics\\TransportNotice')
            ->whereIn('notifiable_id', DB::table('users')->whereRaw("not ({$logistics})")->select('id'))
            ->delete();
    }

    public function down(): void {}
};
