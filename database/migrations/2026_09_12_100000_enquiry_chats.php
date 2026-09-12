<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Обращение с сайта — чат без предложения; гостю — по токену из cookie, без учётной записи.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chats', function (Blueprint $table) {
            $table->foreignId('offer_id')->nullable()->change();
            $table->foreignId('user_id')->nullable()->change();
            $table->string('guest_name', 100)->nullable();
            $table->string('guest_token', 64)->nullable()->unique(); // sha256 токена из cookie
        });
    }

    public function down(): void
    {
        Schema::table('chats', function (Blueprint $table) {
            $table->dropColumn(['guest_name', 'guest_token']);
        });
    }
};
