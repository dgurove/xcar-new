<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Уведомление о новых предложениях пачкой после 5 минут тишины (16.10.2026, `NotifyNewOffers`): до какого момента
 * открывшееся человеку (`offer_viewers.notified_at`) уже посчитано. Всем — сейчас: прошлые не прилетят «237 новыми».
 * Индекс по `notified_at` — часы каждую минуту смотрят только свежие отметки.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->timestamp('offers_digest_at')->nullable();
        });
        Schema::table('offer_viewers', function (Blueprint $t) {
            $t->index('notified_at');
        });
        DB::table('users')->update(['offers_digest_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('offer_viewers', function (Blueprint $t) {
            $t->dropIndex(['notified_at']);
        });
        Schema::table('users', function (Blueprint $t) {
            $t->dropColumn('offers_digest_at');
        });
    }
};
