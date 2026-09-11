<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 40)->unique();
            $table->string('title', 80);
            $table->string('email', 120)->unique();
            $table->string('from_name', 80)->nullable();
            $table->string('scope', 8)->default('offers');            // offers | park
            $table->string('imap_host', 120);
            $table->unsignedSmallInteger('imap_port')->default(993);
            $table->string('imap_encryption', 8)->default('ssl');      // ssl | tls | none
            $table->boolean('imap_validate_cert')->default(true);
            $table->string('imap_username', 120);
            $table->text('imap_password');
            $table->string('smtp_host', 120);
            $table->unsignedSmallInteger('smtp_port')->default(2525);
            $table->string('smtp_encryption', 8)->default('tls');
            $table->string('smtp_username', 120);
            $table->text('smtp_password');
            $table->text('signature')->nullable();
            $table->boolean('is_active')->default(true);
            $table->date('sync_from')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->timestamps();
        });

        Schema::create('mail_folders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('mail_accounts')->cascadeOnDelete();
            $table->string('path', 255);
            $table->string('name', 255);
            $table->string('delimiter', 4)->nullable();
            $table->string('kind', 8)->default('custom');
            $table->boolean('is_syncable')->default(true);
            $table->unsignedBigInteger('uid_validity')->nullable();
            $table->unsignedBigInteger('uid_next')->nullable();
            $table->unsignedBigInteger('last_uid')->default(0);
            $table->unsignedInteger('messages_count')->default(0);
            $table->unsignedInteger('unseen_count')->default(0);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->unique(['account_id', 'path']);
        });

        Schema::create('mail_threads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('mail_accounts')->cascadeOnDelete();
            $table->string('root_message_id', 512)->nullable();
            $table->string('subject', 500)->nullable();
            $table->string('subject_normalized', 255)->nullable()->index();
            $table->jsonb('participants')->default('[]');
            $table->timestamp('last_message_at')->nullable()->index();
            $table->unsignedInteger('messages_count')->default(0);
            $table->unsignedInteger('unread_count')->default(0);
            $table->boolean('has_attachments')->default(false);
            $table->foreignId('offer_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('mail_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('mail_accounts')->cascadeOnDelete();
            $table->foreignId('folder_id')->nullable()->constrained('mail_folders')->nullOnDelete();
            $table->foreignId('thread_id')->nullable()->constrained('mail_threads')->nullOnDelete();
            $table->string('direction', 8)->default('in');            // in | out
            $table->unsignedBigInteger('imap_uid')->nullable();
            $table->unsignedBigInteger('uid_validity')->nullable();
            $table->string('message_id', 512)->nullable()->index();
            $table->string('in_reply_to', 512)->nullable()->index();
            $table->text('references_header')->nullable();
            $table->string('subject', 500)->nullable();
            $table->string('subject_normalized', 255)->nullable();
            $table->string('from_email', 255)->nullable()->index();
            $table->string('from_name', 255)->nullable();
            $table->string('to_preview', 255)->nullable();
            $table->timestamp('date_at')->nullable()->index();
            $table->timestamp('internal_at')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->boolean('is_seen')->default(false);
            $table->boolean('is_answered')->default(false);
            $table->boolean('is_flagged')->default(false);
            $table->boolean('is_draft')->default(false);
            $table->boolean('is_deleted')->default(false);
            $table->string('raw_path', 255)->nullable();
            $table->text('text_body')->nullable();
            $table->text('html_body')->nullable();
            $table->string('preview', 300)->nullable();
            $table->jsonb('headers')->nullable();
            $table->string('parse_state', 8)->default('pending');     // pending | parsed | failed
            $table->text('parse_error')->nullable();
            $table->string('send_state', 8)->nullable();              // queued | sending | sent | failed
            $table->text('send_error')->nullable();
            $table->unsignedSmallInteger('send_attempts')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('appended_to_sent_at')->nullable();
            $table->boolean('has_attachments')->default(false);
            $table->unsignedSmallInteger('attachments_count')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            // UID уникален внутри папки, а не ящика.
            $table->unique(['folder_id', 'uid_validity', 'imap_uid']);
            $table->index(['thread_id', 'date_at']);
            $table->index(['account_id', 'message_id']);
        });

        Schema::create('mail_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('mail_messages')->cascadeOnDelete();
            $table->string('kind', 8);                                // from | to | cc | bcc | reply_to
            $table->string('email', 255)->index();
            $table->string('name', 255)->nullable();
            $table->unsignedSmallInteger('position')->default(0);
        });

        Schema::create('mail_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('mail_messages')->cascadeOnDelete();
            $table->string('filename', 255);
            $table->string('mime', 120)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->string('path', 255);
            $table->string('content_id', 512)->nullable();
            $table->boolean('is_inline')->default(false);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('mail_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->string('scope', 8)->default('offers');
            $table->string('subject', 255)->nullable();
            $table->text('body')->nullable();
            $table->timestamps();
        });

        Schema::table('workflow_stages', function (Blueprint $table) {
            $table->foreignId('template_id')->nullable()->after('staff_fields')->constrained('mail_templates')->nullOnDelete();
        });

        // Машина, вычитанная из письма страховой. Живёт до превращения в черновик или отказа.
        Schema::create('mail_candidates', function (Blueprint $table) {
            $table->id();
            $table->string('code', 60)->nullable()->index();
            $table->foreignId('message_id')->constrained('mail_messages')->cascadeOnDelete();
            $table->foreignId('thread_id')->nullable()->constrained('mail_threads')->nullOnDelete();
            $table->string('subject', 500)->nullable();
            $table->string('state', 10)->default('new')->index();     // new | rejected | promoted
            $table->jsonb('extracted')->default('{}');
            $table->jsonb('proposed')->nullable();
            $table->foreignId('offer_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_candidates');
        Schema::table('workflow_stages', fn (Blueprint $t) => $t->dropConstrainedForeignId('template_id'));
        foreach (['mail_templates', 'mail_attachments', 'mail_addresses', 'mail_messages', 'mail_threads', 'mail_folders', 'mail_accounts'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
