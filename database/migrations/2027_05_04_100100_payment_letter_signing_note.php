<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Письмо поставщику с документом об оплате называет город подписания, если менеджер его указал (25.11.2026, `{{ signing_note }}`:
 * « Подписание договора планируется в г. Белгород.», без города — пусто). Только шаблоны с прежней фразой, правленые руками — нет.
 */
return new class extends Migration
{
    private const WAS = 'Документ об оплате во вложении. Просим';

    private const NOW = 'Документ об оплате во вложении.{{ signing_note }} Просим';

    public function up(): void
    {
        DB::table('mail_templates')->where('body', 'like', '%'.self::WAS.'%')->where('body', 'not like', '%signing_note%')->get(['id', 'body'])
            ->each(fn ($t) => DB::table('mail_templates')->where('id', $t->id)->update(['body' => str_replace(self::WAS, self::NOW, $t->body)]));
    }

    public function down(): void
    {
        DB::table('mail_templates')->where('body', 'like', '%'.self::NOW.'%')->get(['id', 'body'])
            ->each(fn ($t) => DB::table('mail_templates')->where('id', $t->id)->update(['body' => str_replace(self::NOW, self::WAS, $t->body)]));
    }
};
