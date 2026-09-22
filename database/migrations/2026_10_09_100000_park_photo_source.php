<?php

use App\Park\Vehicle;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * У кадра ТС теперь две метки: `stage` — что за кадр (от страховой, при приёме, при выдаче, при погрузке),
 * `source` — откуда он (`mail` из письма, `app` снят в приложении). Раньше всё, что приехало из писем, лежало
 * одной кучей `mail`, и наши же фото приёма были подписаны «Из письма».
 *
 * Кто чей — по письму вложения: отпечаток кадра (`sha`) равен `mail_attachments.blob_sha`. Наше письмо — то, что
 * ушло с ящика или пришло с личного адреса сотрудника (`Message::isOurs()` на SQL). Кадр, который есть и в чужом
 * письме, — от страховой, даже если мы его потом пересылали.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Пустые свойства спатя пишет массивом `[]`, а `jsonb || {...}` дописал бы к массиву элемент вместо ключей.
        // Приводим к объекту всё, что лежит массивом: пустой — в `{}`, собранный по ошибке — слиянием его элементов.
        DB::statement("
            update media set custom_properties = coalesce((
                select jsonb_object_agg(kv.key, kv.value)
                  from jsonb_array_elements(custom_properties::jsonb) e, jsonb_each(e) kv
            ), '{}'::jsonb)
             where jsonb_typeof(custom_properties::jsonb) = 'array'
        ");

        // Снято в приложении: стадия у кадра уже правильная, «хранение» отдельной стадией больше не живёт.
        DB::statement("
            update media set custom_properties = coalesce(custom_properties, '{}')::jsonb || jsonb_build_object(
                'source', 'app',
                'stage', case when custom_properties->>'stage' = 'storage' then 'intake' else custom_properties->>'stage' end)
             where collection_name = 'photos' and model_type = ?
               and custom_properties->>'stage' in ('intake', 'release', 'pickup', 'storage')
        ", [Vehicle::class]);

        DB::statement("
            with src as (
                select a.blob_sha,
                       bool_or(not o.ours) as theirs,
                       bool_or(o.ours and m.intent = 'released') as released
                  from mail_attachments a
                  join mail_messages m on m.id = a.message_id
                  cross join lateral (select (m.direction = 'out'
                       or lower(m.from_email) in (select lower(email) from users where email is not null)) as ours) o
                 where a.blob_sha is not null
                 group by a.blob_sha
            )
            update media p set custom_properties = coalesce(p.custom_properties, '{}')::jsonb || jsonb_build_object(
                'source', 'mail',
                'stage', case when src.theirs then 'vendor' when src.released then 'release' else 'intake' end)
              from src
             where p.collection_name = 'photos' and p.model_type = ?
               and src.blob_sha = p.custom_properties->>'sha'
               and coalesce(p.custom_properties->>'stage', 'mail') = 'mail'
        ", [Vehicle::class]);

        // Письма-источника не нашлось: кадры из архивов и вырезки из листов-сканов — это заявки страховых.
        DB::statement("
            update media set custom_properties = coalesce(custom_properties, '{}')::jsonb || '{\"source\": \"mail\", \"stage\": \"vendor\"}'::jsonb
             where collection_name = 'photos' and model_type = ?
               and coalesce(custom_properties->>'stage', 'mail') = 'mail'
        ", [Vehicle::class]);
    }

    public function down(): void {}
};
