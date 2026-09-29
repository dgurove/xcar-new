<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Номер предложения — дата и порядковый слитно (2609291066), как на старом сайте: выдаётся при первом выходе
 * наружу (`OfferNumber`). До этого у черновика временный порядковый — для адреса редактора. Уже опубликованные
 * получают номер по дню публикации; прежний номер остаётся в `offer_number_aliases` — старые ссылки открываются.
 * Десять цифр в integer не влезают — bigint.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE offers ALTER COLUMN number TYPE bigint');
        Schema::create('offer_number_aliases', function (Blueprint $table) {
            $table->unsignedBigInteger('number')->primary();
            $table->foreignId('offer_id')->constrained()->cascadeOnDelete();
        });
        $offers = DB::table('offers')->whereNotNull('published_at')->where('number', '<', 1000000)->where('is_demo', false)->orderBy('published_at')->orderBy('id')->get(['id', 'number', 'published_at']);
        foreach ($offers as $o) {
            $seq = DB::selectOne("SELECT nextval('offer_numbers') AS n")->n;
            $number = (int) (\Illuminate\Support\Carbon::parse($o->published_at)->timezone(config('app.timezone'))->format('ymd').$seq);
            DB::table('offer_number_aliases')->insert(['number' => $o->number, 'offer_id' => $o->id]);
            DB::table('offers')->where('id', $o->id)->update(['number' => $number]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('offer_number_aliases')->get() as $a) {
            DB::table('offers')->where('id', $a->offer_id)->update(['number' => $a->number]);
        }
        Schema::dropIfExists('offer_number_aliases');
    }
};
