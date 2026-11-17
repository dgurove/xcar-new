<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * История «Оценить из текста» (17.11.2026, владелец): каждое сохранение окна и бота — кто, откуда текст, оценочные с наценкой
 * или уже закупочные (`markup`), вендоры с числом ТС и сами ТС с суммами (`App\Offers\ValuationRun`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('valuation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source', 8);
            $table->boolean('markup')->default(true);
            $table->jsonb('vendors');
            $table->unsignedInteger('cars');
            $table->jsonb('items');
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('valuation_runs');
    }
};
