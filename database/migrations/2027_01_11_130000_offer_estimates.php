<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Оценка цены продажи менеджером (11.10.2026, начальник): админ отдаёт черновик без цены продажи менеджерам на 48 часов,
 * их оценка — ориентир под полем цены («Взять» / «Отклонить»), а не цена. Пока машина на оценке, шеринг запрещён —
 * `share_locked_by_estimate` помнит, что запрет поставила оценка, и снимает его она же.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offer_estimates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('offer_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('asked_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('state', 10)->default('asked');
            $t->timestamp('due_at');
            $t->unsignedBigInteger('amount')->nullable();
            $t->timestamp('estimated_at')->nullable();
            $t->unsignedBigInteger('rejected_amount')->nullable();
            $t->timestamp('rejected_at')->nullable();
            $t->timestamp('reminded_at')->nullable();
            $t->timestamps();
            $t->index(['user_id', 'state']);
            $t->index(['offer_id', 'state']);
        });
        DB::statement("create unique index offer_estimates_open_unique on offer_estimates (offer_id, user_id) where state in ('asked', 'given')");

        Schema::table('offers', function (Blueprint $t) {
            $t->boolean('share_locked_by_estimate')->default(false)->after('share_locked');
        });
    }

    public function down(): void
    {
        Schema::table('offers', fn (Blueprint $t) => $t->dropColumn('share_locked_by_estimate'));
        Schema::dropIfExists('offer_estimates');
    }
};
