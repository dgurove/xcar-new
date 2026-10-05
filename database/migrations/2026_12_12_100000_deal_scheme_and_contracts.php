<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Сделка «страхователю по ДКП» (05.10.2026): кому платят за ТС и сколько собственнику — у сделки, договор купли-продажи — своей строкой
 * (`DealContract`), паспорт продавца и покупателя — у контрагентов (`billing_parties`, физлицо).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->string('scheme', 16)->default('ours')->after('commission_mode');
            // По ДКП: сколько покупатель отдаёт собственнику. Меньше закупочной — взаимозачёт: страховая гасит так свой
            // долг перед нами (Kuga: закупочная 779 000, собственнику 750 000).
            $table->unsignedBigInteger('owner_price')->nullable()->after('cost');
        });
        Schema::table('offers', function (Blueprint $table) {
            // «Предлагаем забрать за N» из письма страховой — по умолчанию «собственнику» при принятии по ДКП.
            $table->unsignedBigInteger('owner_price')->nullable()->after('floor_price');
        });
        Schema::table('billing_parties', function (Blueprint $table) {
            $table->date('passport_issued_at')->nullable()->after('passport_issued');
            $table->string('passport_code', 10)->nullable()->after('passport_issued_at');
            $table->string('birth_place', 255)->nullable()->after('birth_at');
        });
        Schema::create('deal_contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deal_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('seller_party_id')->nullable()->constrained('billing_parties')->nullOnDelete();
            $table->foreignId('buyer_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('price')->nullable();
            $table->string('city', 80)->nullable();
            $table->date('signed_at')->nullable();
            $table->string('plate', 20)->nullable();
            $table->string('sts', 40)->nullable();
            $table->string('pts', 40)->nullable();
            $table->string('pts_issued', 255)->nullable();
            $table->string('body_no', 40)->nullable();
            $table->string('chassis_no', 40)->nullable();
            $table->string('engine_no', 40)->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deal_contracts');
        Schema::table('billing_parties', fn (Blueprint $t) => $t->dropColumn(['passport_issued_at', 'passport_code', 'birth_place']));
        Schema::table('offers', fn (Blueprint $t) => $t->dropColumn('owner_price'));
        Schema::table('deals', fn (Blueprint $t) => $t->dropColumn(['scheme', 'owner_price']));
    }
};
