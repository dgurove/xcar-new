<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Слова «убыток» в темах и текстах писем нет (10.11.2026, владелец: «не „убыток 0790/046/09068/26“, а просто
 * „0790/046/09068/26“»): «…, убыток {{ ref }}» → «…, {{ ref }}».
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('mail_templates')->where('subject', 'ilike', '%убыт%')->orWhere('body', 'ilike', '%убыт%')->get() as $t) {
            $clean = fn (?string $s) => $s === null ? null : preg_replace('/[Уу]быток\s+(\{\{\s*(?:ref|claim_ref)\s*\}\})/u', '$1', $s);
            DB::table('mail_templates')->where('id', $t->id)->update(['subject' => $clean($t->subject), 'body' => $clean($t->body)]);
        }
    }

    public function down(): void {}
};
