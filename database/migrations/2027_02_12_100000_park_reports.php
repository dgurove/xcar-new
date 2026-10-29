<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Отчёт о приёме — своей записью (29.10.2026, Никита: «работает через раз, Альфа не видит вложений»): форма сохраняет, что
 * уйдёт, письма собирает очередь (`Park\Jobs\SendIntakeReport`), шаг «Отчёт» ставится, когда письма ушли по SMTP
 * (`Park\Listeners\ReportDelivery`), не ушли — «Повторить». Альфе СПб — список сканов, как просит Колбасина: «акт п/п ГОТС,
 * акт хранения, ПТС/СТС» (у вендора парковки он был пуст — в отчётах не было ни одного документа).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('park_reports', function (Blueprint $t) {
            $t->id();
            $t->foreignId('vehicle_id')->constrained('park_vehicles')->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('parent_id')->nullable()->constrained('mail_messages')->nullOnDelete();
            $t->string('to', 500);
            $t->string('cc', 500)->nullable();
            $t->text('text')->nullable();
            $t->jsonb('photos');
            $t->jsonb('docs');
            $t->string('state', 10)->default('queued');
            $t->text('error')->nullable();
            $t->jsonb('messages')->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->timestamps();
            $t->index(['vehicle_id', 'state']);
        });

        DB::table('vendors')->where('name', 'АльфаСтрахование СПб')->whereNull('intake_report_docs')
            ->update(['intake_report_docs' => json_encode(['act', 'storage', 'sts', 'pts'])]);
    }

    public function down(): void
    {
        Schema::dropIfExists('park_reports');
    }
};
