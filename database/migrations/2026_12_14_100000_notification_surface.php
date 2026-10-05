<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Парковка — отдельное приложение (владелец, 05.10.2026): её уведомления видны только на хосте парковки, в CRM и на
 * сайте их нет. Новые строки помечает `Notice::toArray` (`data.surface`), старым — по адресу на хост парковки.
 */
return new class extends Migration
{
    public function up(): void
    {
        $host = config('xcar.park_host');
        if (! $host) {
            return;
        }
        DB::table('notifications')->where('data->href', 'like', '%://'.$host.'%')
            ->update(['data' => DB::raw("jsonb_set(data, '{surface}', '\"park\"')")]);
    }

    public function down(): void {}
};
