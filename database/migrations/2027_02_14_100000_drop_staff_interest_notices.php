<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Разово (30.10.2026, владелец: «уведомления — это что-то важное»): сотрудникам об интересе больше не пишем, класс
 * `InterestNotice` удалён — его строки в ленте админов убираются. Интерес остаётся у предложения и в «Галерея → Отклики».
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('notifications')->where('type', 'App\Notifications\InterestNotice')->delete();
    }

    public function down(): void {}
};
