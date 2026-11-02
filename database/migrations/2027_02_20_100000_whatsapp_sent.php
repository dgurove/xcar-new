<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 03.11.2026: предложения в группу WhatsApp (`App\WhatsApp\Group`). Строка — машина взята в группу: `message_id` есть —
 * ушла, нет — ещё идёт или служба в Цюрихе отказала (`attempts`, после трёх отказов — не шлём). Строки не удаляются:
 * вернувшаяся в продажу машина второй раз не приходит (владелец: снятое в WhatsApp не трогать).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_sent', function (Blueprint $t) {
            $t->foreignId('offer_id')->primary()->constrained()->cascadeOnDelete();
            $t->string('message_id', 64)->nullable();
            $t->unsignedSmallInteger('attempts')->default(0);
            $t->timestamp('created_at')->useCurrent();
            $t->timestamp('sent_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_sent');
    }
};
