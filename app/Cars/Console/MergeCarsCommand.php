<?php

namespace App\Cars\Console;

use App\Cars\Actions\MergeCars;
use Illuminate\Console\Command;

/**
 * Разовая чистка справочника машин (01.10.2026): дубль марки «Lada» и модели, вписанные людьми по-русски. Дальше
 * дубли не появятся — `Brand::resolve` и `CarModel::resolve` сначала спрашивают словарь `Names`. Без `--apply` —
 * только список.
 */
class MergeCarsCommand extends Command
{
    protected $signature = 'cars:merge {--apply : слить, а не только показать}';

    protected $description = 'Слить дубли марок и моделей в записи справочника';

    public function handle(MergeCars $merge): int
    {
        $apply = (bool) $this->option('apply');
        $n = 0;
        foreach ($merge->brandPairs() as [$from, $into]) {
            $this->line("марка «{$from->name}» ({$from->slug}, ссылок {$merge->uses('brand_id', $from->id)}, моделей {$from->models()->count()}) → «{$into->name}» ({$into->slug})");
            $apply && $merge->brand($from, $into);
            $n++;
        }
        foreach ($merge->modelPairs() as [$from, $into]) {
            $this->line("модель {$from->brand->name} «{$from->name}» (ссылок {$merge->uses('model_id', $from->id)}) → «{$into->name}»");
            $apply && $merge->model($from, $into);
            $n++;
        }
        $this->info($apply ? "слито {$n}" : "сольётся {$n}; с --apply сольёт");

        return self::SUCCESS;
    }
}
