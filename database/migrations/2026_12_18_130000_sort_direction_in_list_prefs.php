<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Сортировка — поле и направление (06.10.2026, App\Support\Sort): в адресе `-ключ` — по убыванию, `ключ` — по
 * возрастанию. Запомненные голые ключи сотрудников («fresh», «amount») прежде значили «новые сверху» — без минуса список
 * у них молча перевернулся бы. Каталог, галерея сайта и избранное не трогаются: там минус был всегда. «Дольше ждут»
 * почты — то же поле по возрастанию; прежнее «Сначала новые» предложений CRM («fresh» по правке) полем не стало — снимается.
 */
return new class extends Migration
{
    private const DESC = ['fresh', 'amount', 'interest', 'number', 'best', 'final'];

    public function up(): void
    {
        DB::table('users')->whereNotNull('list_prefs')->orderBy('id')->each(function ($user) {
            $all = json_decode((string) $user->list_prefs, true);
            if (! is_array($all)) {
                return;
            }
            $next = $all;
            foreach ($all as $list => $prefs) {
                $sort = is_array($prefs) ? ($prefs['sort'] ?? null) : null;
                if (! is_string($sort) || $sort === '' || str_starts_with($sort, '-') || ! preg_match('/^(crm|park|offers|purchase)-/', (string) $list)) {
                    continue;
                }
                $value = match (true) {
                    $sort === 'waiting' => 'fresh',
                    $list === 'crm-offers' && $sort === 'fresh' => null,
                    in_array($sort, self::DESC, true) => '-'.$sort,
                    default => $sort,
                };
                if ($value === null) {
                    unset($next[$list]['sort']);
                } else {
                    $next[$list]['sort'] = $value;
                }
            }
            if ($next !== $all) {
                DB::table('users')->where('id', $user->id)->update(['list_prefs' => json_encode($next)]);
            }
        });
    }
};
