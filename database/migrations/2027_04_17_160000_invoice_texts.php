<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Текст счёта парковки правится (17.11.2026, владелец: «невозможно изменить текст наименования услуги — нужно дату акта
 * скорректировать»; счёт Альфе за октябрь от 17.11 с актом № 20261109-01 от 09.11.2026): номер и дата акта, основание и строка
 * целиком. Пусто — как прежде (номер и дата акта — счёта, основание — договор вендора, строка собирается). У черновика месяца,
 * пока он не записан, — `billing_closing_texts` по месяцу и группе (`Billing\ClosingText`); отправка переносит их в счёт.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_invoices', function (Blueprint $t) {
            $t->string('act_no', 30)->nullable();
            $t->date('act_at')->nullable();
            $t->string('basis', 255)->nullable();
            $t->text('line_title')->nullable();
        });
        Schema::create('billing_closing_texts', function (Blueprint $t) {
            $t->id();
            $t->date('month');
            $t->string('group', 60);
            $t->string('act_no', 30)->nullable();
            $t->date('act_at')->nullable();
            $t->string('basis', 255)->nullable();
            $t->text('line_title')->nullable();
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['month', 'group']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_closing_texts');
        Schema::table('billing_invoices', fn (Blueprint $t) => $t->dropColumn(['act_no', 'act_at', 'basis', 'line_title']));
    }
};
