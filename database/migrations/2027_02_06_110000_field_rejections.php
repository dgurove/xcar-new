<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * «Отклонить» значение источника — одна таблица вместо трёх хранилищ (26.10.2026, приборка, `Cars\Rejections`): колонка
 * `offers.migtorg_rejected`, кэш читалки `scan:rejected:{o|v|c}:{id}` и кэш парковки `park:docdiff:rejected:{ТС}`
 * переносятся; у продажи и ТС предмет — их машина.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('field_rejections', function (Blueprint $t) {
            $t->id();
            $t->string('subject', 40);
            $t->string('source', 16);
            $t->string('field', 40);
            $t->string('value', 255);
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamp('created_at')->nullable();
            $t->unique(['subject', 'source', 'field', 'value']);
        });
        $rows = [];
        $add = function (?string $subject, string $source, string $field, string $value) use (&$rows) {
            if ($subject && $field !== '' && $value !== '') {
                $rows[] = ['subject' => $subject, 'source' => $source, 'field' => mb_substr($field, 0, 40), 'value' => mb_substr($value, 0, 255), 'created_at' => now()];
            }
        };
        foreach (DB::table('offers')->whereNotNull('migtorg_rejected')->get(['car_id', 'migtorg_rejected']) as $offer) {
            foreach ((array) json_decode($offer->migtorg_rejected, true) as $field => $text) {
                $add('car:'.$offer->car_id, 'migtorg', (string) $field, (string) $text);
            }
        }
        $prefix = (string) config('cache.prefix');
        $car = fn (string $table, int $id) => ($c = DB::table($table)->where('id', $id)->value('car_id')) ? 'car:'.$c : null;
        foreach (DB::table('cache')->where('key', 'like', $prefix.'scan:rejected:%')->orWhere('key', 'like', $prefix.'park:docdiff:rejected:%')->pluck('key') as $key) {
            $key = substr($key, strlen($prefix));
            $subject = match (true) {
                (bool) preg_match('/^scan:rejected:o:(\d+)$/', $key, $m) => $car('offers', (int) $m[1]),
                (bool) preg_match('/^scan:rejected:v:(\d+)$/', $key, $m) => $car('park_vehicles', (int) $m[1]),
                (bool) preg_match('/^scan:rejected:c:(\d+)$/', $key, $m) => 'candidate:'.$m[1],
                (bool) preg_match('/^park:docdiff:rejected:(\d+)$/', $key, $m) => $car('park_vehicles', (int) $m[1]),
                default => null,
            };
            foreach ((array) Cache::get($key, []) as $pair) {
                [$field, $value] = array_pad(explode('|', (string) $pair, 2), 2, '');
                $add($subject, 'docs', $field, $value);
            }
            Cache::forget($key);
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('field_rejections')->insertOrIgnore($chunk);
        }
        Schema::table('offers', fn (Blueprint $t) => $t->dropColumn('migtorg_rejected'));
    }

    public function down(): void
    {
        Schema::table('offers', fn (Blueprint $t) => $t->jsonb('migtorg_rejected')->nullable());
        Schema::dropIfExists('field_rejections');
    }
};
