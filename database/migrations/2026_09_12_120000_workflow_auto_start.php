<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Когда маршрут запускается: с каждым предложением или по кнопке на
        // карточке. У продажи всегда с предложением; у вывоза — настройка:
        // Совкомбанк обязывает вывозить каждую машину, у остальных это
        // редкое исключение по решению сотрудника.
        Schema::table('workflows', function (Blueprint $table) {
            $table->boolean('auto_start')->default(true)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('workflows', fn (Blueprint $t) => $t->dropColumn('auto_start'));
    }
};
