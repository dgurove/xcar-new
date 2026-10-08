<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Галерея — окно показа черновика, а не состояние (владелец 08.10.2026): черновик Т-Страхования на 3 дня (до 23:59
 * третьего дня) виден в галерее всем менеджерам и покупателям, а сам идёт своим путём — закупочная, продажная,
 * публикация. `vendors.to_gallery` — чьи черновики туда идут сами. Предложений в состоянии `gallery` на проде нет;
 * если есть — становятся черновиками с окном от сегодня.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offers', function (Blueprint $t) {
            $t->timestamp('gallery_from')->nullable();
            $t->timestamp('gallery_until')->nullable()->index();
        });
        Schema::table('vendors', function (Blueprint $t) {
            $t->boolean('to_gallery')->default(false);
        });
        DB::table('vendors')->where('name', 'Т-Страхование')->update(['to_gallery' => true]);
        DB::table('offers')->where('state', 'gallery')->update([
            'state' => 'draft',
            'gallery_from' => DB::raw('coalesce(published_at, now())'),
            'gallery_until' => now()->startOfDay()->addDays(3)->setTime(23, 59),
        ]);
    }

    public function down(): void
    {
        Schema::table('offers', fn (Blueprint $t) => $t->dropColumn(['gallery_from', 'gallery_until']));
        Schema::table('vendors', fn (Blueprint $t) => $t->dropColumn('to_gallery'));
    }
};
