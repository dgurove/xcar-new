<?php

use App\Billing\Closing;
use App\Billing\Invoice;
use App\Mail\Message;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Переписка по документу месяца (09.11.2026, владелец: «удобно переписку видеть по этой цепочке, отвечать на их вопросы»;
 * «отправили на согласование, а статус всё ещё „не отправлен“»): ветка письма знает свой документ — «месяц|группа», как
 * ключ живого документа (`2026-10|p2:park`). Ставит отправка письма с документом (сверка и финал), ответы страховой
 * приходят в ту же ветку. Бэкфилл: ветки уже отправленных финальных документов (`sent_message_id`) и сверка Альфы
 * Москва за октябрь, отправленная 09.11.2026 до этой правки (письмо 8090).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_threads', function (Blueprint $t) {
            $t->string('closing_key', 80)->nullable()->index();
        });

        Invoice::whereNotNull('sent_message_id')->whereNotNull('month')->get()->each(function (Invoice $invoice) {
            $group = Closing::groupOfInvoice($invoice);
            $thread = Message::whereKey($invoice->sent_message_id)->value('thread_id');
            if ($group && $thread) {
                DB::table('mail_threads')->where('id', $thread)->whereNull('closing_key')->update(['closing_key' => $invoice->month->format('Y-m').'|'.$group]);
            }
        });
        // Сверка октября Альфы Москва (письмо 8090 на schet@alfastrah.ru) — только на проде, где оно есть.
        $review = Message::where('id', 8090)->where('subject', 'like', '%отчет-акт за октябрь 2026 на сверку%')->value('thread_id');
        if ($review) {
            DB::table('mail_threads')->where('id', $review)->update(['closing_key' => '2026-10|p2:park']);
        }
    }

    public function down(): void
    {
        Schema::table('mail_threads', fn (Blueprint $t) => $t->dropColumn('closing_key'));
    }
};
