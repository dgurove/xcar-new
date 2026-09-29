<?php

use App\Offers\Actions\SyncViewers;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Кому показывать: волны [{delay, all, groups, users}] → строки «кто → когда» [{type, id, delay}] с «Остальными»
 * последней строкой. Волна «все» становится «Остальными»; её не было — остальные не видели, так и остаётся.
 */
return new class extends Migration
{
    public function up(): void
    {
        $convert = function (?string $json): ?string {
            $waves = json_decode($json ?? 'null', true);
            if (! is_array($waves) || isset($waves[0]['type'])) {
                return $json;
            }
            $rows = [];
            $rest = null;
            foreach ($waves as $w) {
                if (! empty($w['all'])) {
                    $rest = $w['delay'];
                }
                foreach (['group' => 'groups', 'user' => 'users'] as $type => $key) {
                    foreach ($w[$key] ?? [] as $id) {
                        $rows["$type:$id"] ??= ['type' => $type, 'id' => (int) $id, 'delay' => $w['delay']];
                    }
                }
            }

            return json_encode([...array_values($rows), ['type' => 'rest', 'id' => null, 'delay' => $rest]]);
        };

        foreach (DB::table('offers')->whereNotNull('audience_rules')->get(['id', 'audience_rules']) as $o) {
            DB::table('offers')->where('id', $o->id)->update(['audience_rules' => $convert($o->audience_rules)]);
        }
        foreach (DB::table('audiences')->get(['id', 'rules']) as $a) {
            DB::table('audiences')->where('id', $a->id)->update(['rules' => $convert($a->rules)]);
        }
        DB::table('audiences')->where('name', 'Все сразу')->update(['name' => 'Всем сразу']);

        app(SyncViewers::class)->all();
    }

    public function down(): void {}
};
