<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Гараж: машина, выведенная из продажи менеджеру на ремонт, и расходы по ней строками.
 * Сама ТС остаётся предложением (состояние «В гараже») — здесь только то, чего у него нет:
 * кому отдали, за сколько, что вложено и чем кончилось. Суммы расходов с копейками,
 * договорные цены — целыми рублями, как везде.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('garage_cars', function (Blueprint $t) {
            $t->id();
            $t->foreignId('offer_id')->unique()->constrained()->cascadeOnDelete();
            $t->foreignId('manager_id')->nullable()->constrained('users')->nullOnDelete(); // пусто — взяли под себя
            $t->string('state', 10)->default('repair');                  // repair | sold | settled
            $t->timestamp('taken_at');
            $t->unsignedInteger('cost')->nullable();                     // отдали за
            $t->timestamp('sold_at')->nullable();
            $t->unsignedInteger('sold_price')->nullable();
            $t->string('buyer_name')->nullable();
            $t->string('buyer_phone', 32)->nullable();
            $t->unsignedInteger('commission')->nullable();               // вознаграждение менеджеру
            $t->timestamp('settled_at')->nullable();
            $t->foreignId('invoice_id')->nullable()->constrained('billing_invoices')->nullOnDelete();
            $t->text('note')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['manager_id', 'state']);
        });

        Schema::create('garage_costs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('garage_car_id')->constrained('garage_cars')->cascadeOnDelete();
            $t->string('title');                                         // что: «доставка», «передние фары»
            $t->decimal('amount', 12, 2);
            $t->date('spent_at');
            $t->string('payer', 8)->default('manager');                  // manager | xcar
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['garage_car_id', 'spent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('garage_costs');
        Schema::dropIfExists('garage_cars');
    }
};
