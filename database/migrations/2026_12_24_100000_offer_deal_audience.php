<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Кому показывать предложение в сделке: белый список менеджеров и групп менеджеров.
 * По умолчанию пуст — машина в сделке скрыта из предложений. Выбранные видят её в
 * каталоге с меткой «Идёт сделка», их подтверждение встаёт резервом.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offer_deal_audience', function (Blueprint $t) {
            $t->foreignId('offer_id')->constrained()->cascadeOnDelete();
            $t->string('audience_type', 10);
            $t->unsignedBigInteger('audience_id');
            $t->primary(['offer_id', 'audience_type', 'audience_id']);
            $t->index(['audience_type', 'audience_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offer_deal_audience');
    }
};
