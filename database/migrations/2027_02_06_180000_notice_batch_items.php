<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Уведомления сотрудникам пачкой (27.10.2026, владелец: «по 100 предложениям одновременно заканчивается срок приёма —
 * 100 уведомлений, это бред»): массовое копится строкой на человека и вид (`Notifications\Batch`), часы отправляют одно.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notice_batch_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('key', 40);
            $t->string('line', 200);
            $t->text('notice');
            $t->timestamp('created_at')->useCurrent();
            $t->index(['user_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notice_batch_items');
    }
};
