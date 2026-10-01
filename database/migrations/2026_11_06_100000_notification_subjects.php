<?php

use App\Support\Paths;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Строка на объект в ленте уведомлений (01.10.2026): старым строкам — тема (`data.subject`, путь объекта без хоста;
 * деньги по сделке — строка сделки), у каждого человека остаётся последняя строка темы. Строкам сотрудников про
 * «Деньги» списком и обращениям с сайта объекта не узнать — у них тема своя, они не сворачиваются. Адреса транслитом —
 * по нынешним (Support\Paths).
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement(<<<'SQL'
            update notifications set data = data || jsonb_build_object('subject', case
                when data->>'href' ~ '^/account/money/deals/\d+$' then regexp_replace(data->>'href', '^/account/money/deals/', '/deals/')
                when regexp_replace(data->>'href', '^https?://[^/]+', '') ~ '^/(work/money|contacts)([?]|$)' then 'legacy:' || id
                else regexp_replace(coalesce(data->>'href', ''), '^https?://[^/]+', '')
            end)
            where data->>'subject' is null
            SQL);
        // Старые строки ведут на адреса транслитом и переехавшие разделы (`/lk/sdelki/5`) — тема по нынешнему адресу,
        // иначе открытая сделка их не погасит: на старый адрес никто уже не попадает, его отбивает 301.
        foreach (DB::table('notifications')->distinct()->pluck(DB::raw("data->>'subject' as subject")) as $subject) {
            $path = strtok((string) $subject, '?');
            $new = str_starts_with((string) $subject, '/') ? Paths::translate($path) : null;
            if ($new !== null) {
                DB::table('notifications')->where('data->subject', $subject)
                    ->update(['data' => DB::raw("jsonb_set(data, '{subject}', to_jsonb(".DB::getPdo()->quote($new.substr((string) $subject, strlen($path))).'::text))')]);
            }
        }
        DB::statement(<<<'SQL'
            delete from notifications n using (
                select id, row_number() over (partition by notifiable_type, notifiable_id, data->>'subject' order by created_at desc, id desc) as n
                from notifications
            ) ranked
            where n.id = ranked.id and ranked.n > 1
            SQL);
        DB::statement("create index concurrently if not exists notifications_subject_index on notifications (notifiable_id, (data->>'subject'))");
    }

    public function down(): void
    {
        DB::statement('drop index concurrently if exists notifications_subject_index');
    }
};
