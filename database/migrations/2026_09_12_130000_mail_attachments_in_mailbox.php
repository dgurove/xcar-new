<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Вложения писем больше не лежат на диске: строка описи с номером секции в
 * ящике; закреплённые (ветка привязана к машине) — blob по sha256. Сырые .eml
 * не хранятся вовсе.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_attachments', function (Blueprint $table) {
            $table->string('path', 255)->nullable()->change();
            $table->string('section', 40)->nullable()->after('path');
            $table->string('encoding', 20)->nullable()->after('section');
            $table->char('blob_sha', 64)->nullable()->index()->after('encoding');
            $table->timestamp('pinned_at')->nullable()->after('blob_sha');
        });
        Schema::table('mail_messages', fn (Blueprint $table) => $table->dropColumn('raw_path'));
    }

    public function down(): void
    {
        Schema::table('mail_messages', fn (Blueprint $table) => $table->string('raw_path', 255)->nullable());
        Schema::table('mail_attachments', function (Blueprint $table) {
            $table->dropColumn(['section', 'encoding', 'blob_sha', 'pinned_at']);
        });
    }
};
