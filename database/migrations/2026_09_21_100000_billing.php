<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Деньги: контрагенты с реквизитами, начисления по ТС и сделкам, счета в обе
 * стороны (нам должны / мы должны), оплаты частями. Хранение считается на лету
 * и становится начислением при выставлении счёта — `storage_billed_until` на ТС.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_parties', function (Blueprint $t) {
            $t->id();
            $t->string('kind', 8)->default('company');          // company | person
            $t->string('name', 200);
            $t->boolean('is_self')->default(false);              // ровно одна строка — мы
            $t->string('inn', 12)->nullable();
            $t->string('kpp', 9)->nullable();
            $t->string('ogrn', 15)->nullable();
            $t->string('legal_address', 255)->nullable();
            $t->string('director', 120)->nullable();
            $t->string('director_basis', 120)->nullable();
            $t->string('bank_name', 120)->nullable();
            $t->string('bik', 9)->nullable();
            $t->string('account', 20)->nullable();
            $t->string('corr_account', 20)->nullable();
            $t->string('passport', 60)->nullable();              // серия и номер
            $t->string('passport_issued', 255)->nullable();      // кем и когда
            $t->string('reg_address', 255)->nullable();
            $t->date('birth_at')->nullable();
            $t->string('phone', 20)->nullable();
            $t->string('email', 120)->nullable();
            $t->string('payment_purpose', 255)->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
        });
        DB::statement('create unique index billing_parties_self on billing_parties (is_self) where is_self');

        Schema::table('vendors', fn (Blueprint $t) => $t->foreignId('party_id')->nullable()->constrained('billing_parties')->nullOnDelete());
        Schema::table('users', fn (Blueprint $t) => $t->foreignId('party_id')->nullable()->constrained('billing_parties')->nullOnDelete());
        Schema::table('park_vehicles', function (Blueprint $t) {
            $t->foreignId('owner_party_id')->nullable()->constrained('billing_parties')->nullOnDelete();   // страхователь-комитент
            $t->string('contract_kind', 10)->default('storage');   // storage | commission
            $t->string('contract_no', 60)->nullable();
            $t->date('contract_at')->nullable();
            $t->unsignedInteger('assigned_price')->nullable();      // назначенная комитентом цена
            $t->decimal('storage_rate', 10, 2)->nullable();          // персональная ставка, поверх прайса
            $t->string('storage_rate_note', 120)->nullable();
            $t->date('storage_billed_until')->nullable();
            $t->string('pts', 40)->nullable();
            $t->string('sts', 40)->nullable();
        });

        Schema::create('billing_invoices', function (Blueprint $t) {
            $t->id();
            $t->string('direction', 6)->default('issued');       // issued — нам должны, owed — мы должны
            $t->smallInteger('year')->nullable();
            $t->unsignedInteger('number')->nullable();
            $t->string('external_no', 60)->nullable();
            $t->string('kind', 12);                               // ChargeKind главной строки
            $t->foreignId('party_id')->constrained('billing_parties')->restrictOnDelete();
            $t->foreignId('vehicle_id')->nullable()->constrained('park_vehicles')->nullOnDelete();
            $t->foreignId('deal_id')->nullable()->constrained('deals')->nullOnDelete();
            $t->foreignId('offer_id')->nullable()->constrained('offers')->nullOnDelete();
            $t->date('issued_at');
            $t->date('due_at');
            $t->boolean('vat')->default(false);
            $t->decimal('total', 12, 2)->default(0);
            $t->decimal('paid', 12, 2)->default(0);
            $t->string('state', 8)->default('issued');            // issued | paid | void
            $t->date('paid_at')->nullable();
            $t->timestamp('overdue_at')->nullable();
            $t->timestamp('reminded_at')->nullable();
            $t->timestamp('voided_at')->nullable();
            $t->string('void_reason', 255)->nullable();
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['year', 'number']);
            $t->index(['state', 'due_at']);
        });

        Schema::create('billing_charges', function (Blueprint $t) {
            $t->id();
            $t->foreignId('party_id')->constrained('billing_parties')->restrictOnDelete();
            $t->foreignId('vehicle_id')->nullable()->constrained('park_vehicles')->nullOnDelete();
            $t->foreignId('deal_id')->nullable()->constrained('deals')->nullOnDelete();
            $t->foreignId('invoice_id')->nullable()->constrained('billing_invoices')->nullOnDelete();
            $t->string('kind', 12);                               // storage | tow | inspection | idle | oversize | sale | selection | transfer | other
            $t->string('title', 160);
            $t->decimal('qty', 10, 2)->default(1);
            $t->string('unit', 4)->default('pc');                 // day | km | h | pc
            $t->decimal('price', 12, 2);
            $t->decimal('amount', 12, 2);
            $t->date('period_from')->nullable();
            $t->date('period_to')->nullable();
            $t->timestamp('voided_at')->nullable();
            $t->string('void_reason', 255)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['vehicle_id', 'invoice_id']);
        });

        Schema::create('billing_payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('invoice_id')->constrained('billing_invoices')->cascadeOnDelete();
            $t->foreignId('party_id')->constrained('billing_parties')->restrictOnDelete();
            $t->decimal('amount', 12, 2);
            $t->date('paid_at');
            $t->string('source', 10)->default('bank');           // bank | cash | acquiring | offset
            $t->string('ref', 60)->nullable();
            $t->string('note', 255)->nullable();
            $t->timestamp('voided_at')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        // Мы — из конфига, чтобы счёт печатался сразу; реквизиты дозаполняются на экране.
        $company = config('xcar.company', []);
        DB::table('billing_parties')->insert([
            'kind' => 'company', 'name' => $company['name'] ?? 'ООО «ПРАЙМ»', 'is_self' => true, 'inn' => $company['inn'] ?? null,
            'director' => $company['director'] ?? null, 'director_basis' => 'Устава', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::drop('billing_payments');
        Schema::drop('billing_charges');
        Schema::drop('billing_invoices');
        Schema::table('park_vehicles', function (Blueprint $t) {
            $t->dropConstrainedForeignId('owner_party_id');
            $t->dropColumn(['contract_kind', 'contract_no', 'contract_at', 'assigned_price', 'storage_rate', 'storage_rate_note', 'storage_billed_until', 'pts', 'sts']);
        });
        Schema::table('users', fn (Blueprint $t) => $t->dropConstrainedForeignId('party_id'));
        Schema::table('vendors', fn (Blueprint $t) => $t->dropConstrainedForeignId('party_id'));
        Schema::drop('billing_parties');
    }
};
