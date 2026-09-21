<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * История ящика: письма за всё время, файлы — только с даты `files_from` (до неё на диск не закрепляются).
 * `backfill_uid` — курсор истории по папке, отдельный от `last_uid` новых писем.
 * Вендоры СОГАЗ и Росгосстрах — чтобы старые цепочки получили заказчика.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_accounts', fn (Blueprint $t) => $t->date('files_from')->nullable()->after('sync_from'));
        Schema::table('mail_folders', fn (Blueprint $t) => $t->unsignedBigInteger('backfill_uid')->default(0)->after('last_uid'));
        DB::table('mail_accounts')->where('scope', 'park')->update(['files_from' => '2026-09-01']);

        foreach (['СОГАЗ' => 'sogaz.ru', 'Росгосстрах' => 'rgs.ru'] as $name => $domain) {
            $exists = DB::table('vendors')->get()->first(fn ($v) => in_array($domain, (array) json_decode($v->senders ?: '[]', true), true) || $v->name === $name);
            if (! $exists) {
                DB::table('vendors')->insert(['name' => $name, 'senders' => json_encode([$domain]), 'parser' => 'generic', 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('mail_accounts', fn (Blueprint $t) => $t->dropColumn('files_from'));
        Schema::table('mail_folders', fn (Blueprint $t) => $t->dropColumn('backfill_uid'));
    }
};
