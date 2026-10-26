<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Отклонённое значение бывает длинным (адрес, описание из документа): varchar(255) ронял «Отклонить». Колонка — текст,
 * уникальность — по отпечатку значения (индекс на text длиннее страницы не строится).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('alter table field_rejections drop constraint if exists field_rejections_subject_source_field_value_unique');
        DB::statement('drop index if exists field_rejections_subject_source_field_value_unique');
        DB::statement('alter table field_rejections alter column value type text');
        DB::statement('create unique index field_rejections_value_unique on field_rejections (subject, source, field, md5(value))');
    }

    public function down(): void
    {
        DB::statement('drop index if exists field_rejections_value_unique');
        DB::statement('alter table field_rejections alter column value type varchar(255) using left(value, 255)');
        DB::statement('alter table field_rejections add constraint field_rejections_subject_source_field_value_unique unique (subject, source, field, value)');
    }
};
