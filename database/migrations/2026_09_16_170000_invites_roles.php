<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ссылки не только для покупателей: админ приглашает менеджеров (одноразово — по
 * ссылке человек сразу становится менеджером) и покупателей от имени выбранного
 * менеджера. `role` — кем станет пришедший, `max_uses` — сколько раз ссылка
 * сработает (пусто — без предела), `created_by` — кто её сделал.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invites', function (Blueprint $table) {
            $table->foreignId('manager_id')->nullable()->change();
            $table->string('role', 16)->default('buyer')->after('manager_id');
            $table->foreignId('created_by')->nullable()->after('role')->constrained('users')->nullOnDelete();
            $table->unsignedInteger('max_uses')->nullable()->after('uses_count');
        });
    }

    public function down(): void
    {
        Schema::table('invites', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn(['role', 'max_uses']);
        });
    }
};
