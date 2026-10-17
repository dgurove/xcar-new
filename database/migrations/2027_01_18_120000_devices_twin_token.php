<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Двойной вход одним браузером (двойное нажатие «Войти через Telegram»): второй вход в те же секунды не гасит первое
 * устройство, а дописывает ему свой токен — какую из двух cookie браузер ни оставит, устройство живое.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', fn (Blueprint $t) => $t->string('twin_token_hash', 64)->nullable());
    }

    public function down(): void
    {
        Schema::table('devices', fn (Blueprint $t) => $t->dropColumn('twin_token_hash'));
    }
};
