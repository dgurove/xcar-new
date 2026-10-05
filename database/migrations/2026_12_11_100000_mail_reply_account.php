<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ящик, который только принимает (05.10.2026): на offer@ страховые присылают предложения, а вся переписка — ответы,
 * письма этапов, пересылки — уходит с deal@. У offer@ «Отвечаем с ящика» — deal@.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_accounts', function (Blueprint $table) {
            $table->foreignId('reply_account_id')->nullable()->after('scope')->constrained('mail_accounts')->nullOnDelete();
        });
        $offer = DB::table('mail_accounts')->where('slug', 'offer')->value('id');
        $deal = DB::table('mail_accounts')->where('slug', 'deal')->value('id');
        if ($offer && $deal) {
            DB::table('mail_accounts')->where('id', $offer)->update(['reply_account_id' => $deal]);
            DB::table('vendors')->where('mail_account_id', $offer)->update(['mail_account_id' => null]);
        }
    }

    public function down(): void
    {
        Schema::table('mail_accounts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reply_account_id');
        });
    }
};
