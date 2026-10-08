<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Уведомления, что ведут в CRM, — только в CRM (08.10.2026, владелец): прежним строкам ленты ставим `data.surface = crm`,
 * как теперь пишет `Notice::surface()`. Адрес CRM, `/work/…`, `/settings/…`; сотрудникам о подтверждении, интересе и
 * сроке без сделки (`/offers/{n}` — их редактор); чаты площадки сотруднику (вторая сторона, не сам участник).
 */
return new class extends Migration
{
    public function up(): void
    {
        $crm = 'https://'.config('xcar.crm_host').'%';
        $base = fn () => DB::table('notifications')->whereRaw("data->>'surface' is null");

        $base()->where(fn ($q) => $q->whereRaw("data->>'href' like ?", [$crm])
            ->orWhereRaw("data->>'href' like ?", ['http://'.config('xcar.crm_host').'%'])
            ->orWhereRaw("data->>'href' like '/work/%'")
            ->orWhereRaw("data->>'href' like '/settings/%'"))
            ->update(['data' => DB::raw("jsonb_set(data::jsonb, '{surface}', '\"crm\"')")]);

        $base()->whereIn('type', ['App\\Notifications\\BidPlacedNotice', 'App\\Notifications\\InterestNotice', 'App\\Notifications\\StageDueNotice'])
            ->whereRaw("data->>'href' like '/offers/%'")
            ->update(['data' => DB::raw("jsonb_set(data::jsonb, '{surface}', '\"crm\"')")]);

        // Чат площадки (без менеджера), адресат — не участник чата: это сотрудник.
        DB::statement(<<<'SQL'
            update notifications n set data = jsonb_set(n.data::jsonb, '{surface}', '"crm"')
            from chats c
            where n.type = 'App\Notifications\ChatNotice' and n.data->>'surface' is null
              and n.data->>'subject' = '/account/chats/' || c.id and c.manager_id is null
              and (c.user_id is null or c.user_id <> n.notifiable_id)
            SQL);
    }

    public function down(): void
    {
        DB::table('notifications')->whereRaw("data->>'surface' = 'crm'")
            ->update(['data' => DB::raw("(data::jsonb - 'surface')")]);
    }
};
