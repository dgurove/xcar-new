<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Доступ менеджеров (07.10.2026): одно устройство на человека и коды от бота.
 *
 * `devices` — устройство, с которого человек работает: метка в сессии (здесь её sha256), публичный ключ браузера
 * (WebCrypto, закрытый лежит в IndexedDB и наружу не выходит) и срок окна. Живое у человека одно, прежние — история с
 * `revoked_at`. `access_codes` — код от бота: шесть цифр хэшем, попытки, кнопка «Продолжить» (`approved_at`) и
 * сообщение в Telegram, которое правится после ответа. `app_settings` — переключатели из CRM, первый — сам замок.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->jsonb('public_key');
            $table->string('label', 80);
            $table->string('ip', 45)->nullable();
            // Тема хаба этой сессии: туда уходит «вас выкинули» при входе с другого устройства.
            $table->string('topic', 40);
            $table->timestampTz('verified_until')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();
        });
        DB::statement('CREATE UNIQUE INDEX devices_live_user ON devices (user_id) WHERE revoked_at IS NULL');

        Schema::create('access_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('purpose', 10);
            $table->string('topic', 40);
            $table->string('code_hash', 64);
            $table->string('nonce', 64);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('label', 80);
            $table->bigInteger('chat_id')->nullable();
            $table->bigInteger('message_id')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('denied_at')->nullable();
            $table->timestampTz('used_at')->nullable();
            $table->timestampTz('expires_at');
            $table->timestampsTz();
            $table->index(['user_id', 'created_at']);
        });

        // Переключатели площадки из CRM. Замок менеджеров после выкладки выключен (владелец 07.10.2026): включают в
        // Настройки → «Пользователи» → «Менеджеры».
        Schema::create('app_settings', function (Blueprint $table) {
            $table->string('key', 60)->primary();
            $table->jsonb('value');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('updated_at')->nullable();
        });
        DB::table('app_settings')->insert(['key' => 'manager_lock', 'value' => 'false', 'updated_at' => now()]);
    }

    public function down(): void
    {
        Schema::dropIfExists('app_settings');
        Schema::dropIfExists('access_codes');
        Schema::dropIfExists('devices');
    }
};
