<?php

use App\Workflow\Requirement;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Просьба «Оплатите счёт» в живых маршрутах (05.10.2026): «Поставщик выставил счёт» — неправда, счёт выставляем мы,
 * и про ссылку на оплату в ней не было ни слова, хотя ссылка стоит прямо под счётом. Меняем только нетронутый
 * текст пресета: свой текст админа остаётся.
 */
return new class extends Migration
{
    private const OLD = 'Поставщик выставил счёт. Оплатите его и приложите платёжное поручение%';

    public const NEW = 'Оплатите по ссылке под счётом картой, СБП или SberPay: оплата отметится сама. Или по реквизитам из счёта, тогда приложите платёжное поручение';

    public function up(): void
    {
        DB::table('workflow_stages')->where('ask_text', 'like', self::OLD)->update(['ask_text' => self::NEW, 'updated_at' => now()]);
        // Открытые просьбы несут снимок текста этапа — им тоже.
        DB::table((new Requirement)->getTable())->whereNull('done_at')->where('text', 'like', self::OLD)->update(['text' => self::NEW, 'updated_at' => now()]);
        DB::table('workflow_blocks')->where('text', 'like', 'Поставщик выставил счёт. После подтверждения оплаты%')
            ->update(['text' => 'Счёт выставлен, оплатите его по ссылке или по реквизитам. После оплаты перейдём к документам', 'updated_at' => now()]);
        DB::table('workflow_blocks')->where('text', 'like', 'Автомобиль передан Вам. Поставщик выставил счёт%')
            ->update(['text' => 'Автомобиль передан Вам. Оплатите счёт по ссылке под ним или по реквизитам', 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('workflow_stages')->where('ask_text', self::NEW)->update(['ask_text' => 'Поставщик выставил счёт. Оплатите его и приложите платёжное поручение — без него оплата не будет подтверждена']);
    }
};
