<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Оплата по ссылке (эквайринг ЮKassa) и выписка Сбера.
 * Ссылка — наша, постоянная (`/pay/{code}`): за ней попытки оплаты у провайдера, каждая — строка,
 * успешная становится обычной оплатой счёта. Выписка — строки операций по расчётному счёту;
 * входящая, узнанная по номеру счёта, тоже становится оплатой, остальное ждёт руки.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_pay_links', function (Blueprint $t) {
            $t->id();
            $t->string('code', 16)->unique();
            $t->foreignId('invoice_id')->constrained('billing_invoices')->cascadeOnDelete();
            $t->decimal('amount', 12, 2);
            $t->string('payer_kind', 8);                                   // self | buyer | other
            $t->foreignId('payer_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('payer_name')->nullable();
            $t->string('payer_phone', 32)->nullable();                     // для чека
            $t->string('payer_email')->nullable();
            $t->string('state', 10)->default('open');                      // open | paid | canceled
            $t->foreignId('payment_id')->nullable()->constrained('billing_payments')->nullOnDelete();
            $t->timestamp('paid_at')->nullable();
            $t->timestamp('canceled_at')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['invoice_id', 'state']);
        });

        Schema::create('billing_acquiring_payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('link_id')->constrained('billing_pay_links')->cascadeOnDelete();
            $t->string('provider', 12);                                    // yookassa
            $t->string('external_id', 64)->unique();
            $t->string('status', 24);                                      // pending | waiting_for_capture | succeeded | canceled
            $t->decimal('amount', 12, 2);
            $t->decimal('income_amount', 12, 2)->nullable();               // пришло нам за вычетом комиссии
            $t->string('method', 24)->nullable();                          // bank_card | sbp | sberbank …
            $t->text('confirmation_url')->nullable();
            $t->string('receipt_status', 12)->nullable();
            $t->decimal('applied', 12, 2)->default(0);                    // легло в счёт; сверх — переплата
            $t->decimal('refunded', 12, 2)->default(0);
            $t->foreignId('payment_id')->nullable()->constrained('billing_payments')->nullOnDelete();
            $t->jsonb('payload')->nullable();
            $t->timestamp('checked_at')->nullable();
            $t->timestamps();
            $t->index(['status', 'created_at']);
        });

        // Подключение к Sber API — одна строка: токены шифруются, живут в базе, потому что refresh меняется при каждом обновлении.
        Schema::create('billing_bank_connections', function (Blueprint $t) {
            $t->id();
            $t->string('provider', 12)->unique();                          // sber
            $t->string('account', 20)->nullable();
            $t->text('client_secret')->nullable();                         // бессрочный после `bank:secret`; пусто — из .env
            $t->timestamp('secret_rotated_at')->nullable();
            $t->text('access_token')->nullable();
            $t->text('refresh_token')->nullable();
            $t->timestamp('access_expires_at')->nullable();
            $t->timestamp('refresh_expires_at')->nullable();
            $t->string('state', 64)->nullable();                           // state OAuth, пока ждём возврата
            $t->timestamp('synced_at')->nullable();
            $t->text('last_error')->nullable();
            $t->timestamp('failed_at')->nullable();
            $t->foreignId('connected_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('connected_at')->nullable();
            $t->timestamps();
        });

        Schema::create('billing_bank_transactions', function (Blueprint $t) {
            $t->id();
            $t->string('external_id', 64)->unique();
            $t->string('account', 20);
            $t->date('booked_at');
            $t->string('direction', 3);                                    // in | out
            $t->decimal('amount', 14, 2);
            $t->string('counterparty')->nullable();
            $t->string('counterparty_inn', 12)->nullable();
            $t->string('counterparty_account', 20)->nullable();
            $t->string('counterparty_bank')->nullable();
            $t->string('doc_number', 20)->nullable();
            $t->text('purpose')->nullable();
            $t->string('state', 10);                                       // matched | unmatched | ignored | outgoing
            $t->string('note')->nullable();                                // почему так: «эквайринг», «не наше»
            $t->foreignId('invoice_id')->nullable()->constrained('billing_invoices')->nullOnDelete();
            $t->foreignId('payment_id')->nullable()->constrained('billing_payments')->nullOnDelete();
            $t->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('decided_at')->nullable();
            $t->jsonb('payload')->nullable();
            $t->timestamps();
            $t->index(['state', 'booked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_bank_transactions');
        Schema::dropIfExists('billing_bank_connections');
        Schema::dropIfExists('billing_acquiring_payments');
        Schema::dropIfExists('billing_pay_links');
    }
};
