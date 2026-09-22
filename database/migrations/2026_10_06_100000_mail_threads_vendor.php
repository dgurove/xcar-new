<?php

use App\Mail\Thread;
use App\Mail\Threads;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Вендор у ветки писем: фильтр «Вендор» в почте, бейдж в строке, правило «Прочее» (не-вендоры и автоответы). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_threads', function (Blueprint $table) {
            $table->foreignId('vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
        });
        Thread::with('account')->chunkById(500, function ($threads) {
            foreach ($threads as $thread) {
                $vendor = Threads::vendorOf($thread, $thread->participants ?? []);
                if ($vendor) {
                    $thread->forceFill(['vendor_id' => $vendor->id])->saveQuietly();
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('mail_threads', fn (Blueprint $t) => $t->dropConstrainedForeignId('vendor_id'));
    }
};
