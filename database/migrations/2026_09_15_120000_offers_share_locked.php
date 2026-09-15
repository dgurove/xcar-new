<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** «Запретить шеринг»: кнопка «Поделиться» неактивна, кадры под водяным знаком, пока администратор не согласовал цену менеджера. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            $table->boolean('share_locked')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            $table->dropColumn('share_locked');
        });
    }
};
