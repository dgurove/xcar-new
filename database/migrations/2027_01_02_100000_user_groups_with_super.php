<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Супер-покупатели (10.10.2026, владелец: «пусть все супер-покупатели будут как один менеджер»): в волнах показа они
 * одной строкой, а в группу менеджеров входят все разом — галкой у группы, а не строками `user_group_user`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_groups', function (Blueprint $table) {
            $table->boolean('with_super')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('user_groups', function (Blueprint $table) {
            $table->dropColumn('with_super');
        });
    }
};
