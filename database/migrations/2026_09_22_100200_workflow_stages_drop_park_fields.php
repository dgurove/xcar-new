<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Поля этапов, которые теперь живут на стоянке и в счетах (страхователь, адрес, дата вывоза,
 * исполнитель, осмотр, площадка, номер счёта и реквизиты), убираются и из уже заведённых маршрутов —
 * `vendors:refill` занятые маршруты не трогает.
 */
return new class extends Migration
{
    private const GONE = ['Страхователь', 'Телефон', 'Адрес автомобиля', 'Дата вывоза', 'Исполнитель', 'Результат осмотра', 'Город', 'Площадка', 'Номер счёта', 'Сумма', 'Реквизиты'];

    public function up(): void
    {
        foreach (DB::table('workflow_stages')->whereRaw("staff_fields::text <> '[]'")->get(['id', 'staff_fields']) as $stage) {
            $fields = json_decode($stage->staff_fields, true) ?: [];
            $kept = array_values(array_filter($fields, fn ($f) => ! in_array($f['label'] ?? '', self::GONE, true)));
            if (count($kept) !== count($fields)) {
                DB::table('workflow_stages')->where('id', $stage->id)->update(['staff_fields' => json_encode($kept, JSON_UNESCAPED_UNICODE)]);
            }
        }
    }

    public function down(): void {}
};
