<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Выдача по QR (24.09.2026): ТС забирает только покупатель, которого подтвердила страховая. Ссылка на анкету —
 * одна на ТС (`pickup_code`), анкета заводит пропуск `park_passes` с кодом для QR; пропуск работает после
 * подтверждения страховой и гаснет при отказе покупателя. Включается у вендора переключателем.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendors', fn (Blueprint $t) => $t->boolean('release_by_qr')->default(false)->after('release_without_payment'));
        Schema::table('park_vehicles', fn (Blueprint $t) => $t->string('pickup_code', 20)->nullable()->unique()->after('pickup_phone'));
        Schema::create('park_passes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('vehicle_id')->constrained('park_vehicles')->cascadeOnDelete();
            $t->string('code', 20)->unique();
            $t->string('name', 160);
            $t->string('phone', 20);
            $t->string('email', 160);
            $t->date('pickup_on');
            $t->timestamp('submitted_at');
            $t->timestamp('confirmed_at')->nullable();
            $t->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('confirm_note', 255)->nullable();
            $t->foreignId('request_message_id')->nullable()->constrained('mail_messages')->nullOnDelete();
            $t->timestamp('mailed_at')->nullable();
            $t->timestamp('used_at')->nullable();
            $t->foreignId('used_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('revoked_at')->nullable();
            $t->string('revoke_reason', 255)->nullable();
            $t->timestamps();
            $t->index(['vehicle_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('park_passes');
        Schema::table('park_vehicles', fn (Blueprint $t) => $t->dropColumn('pickup_code'));
        Schema::table('vendors', fn (Blueprint $t) => $t->dropColumn('release_by_qr'));
    }
};
