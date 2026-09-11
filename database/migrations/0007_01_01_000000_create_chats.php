<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Чат — пара (оффер, участник). Сотрудники отвечают в нём, участниками не становятся.
        Schema::create('chats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('messages_count')->default(0);
            $table->unsignedInteger('unread_for_user')->default(0);
            $table->unsignedInteger('unread_for_staff')->default(0);
            $table->timestamp('last_message_at')->nullable()->index();
            $table->timestamps();
            $table->unique(['offer_id', 'user_id']);
        });

        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('seq');                       // номер в чате: догон идёт «всё после N»
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('author_kind', 12);                    // participant | staff | system
            $table->text('text')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['chat_id', 'seq']);
        });

        // Файлы чата — своя таблица на закрытом диске: медиатека раздаётся мимо PHP.
        Schema::create('chat_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('chat_messages')->cascadeOnDelete();
            $table->string('name', 255);
            $table->string('mime', 120);
            $table->unsignedBigInteger('size');
            $table->string('path', 255);
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_files');
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('chats');
    }
};
