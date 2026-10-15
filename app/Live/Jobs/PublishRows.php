<?php

namespace App\Live\Jobs;

use App\Live\Publisher;
use App\Live\Rows;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Хвост `Publisher::rowsSoon`: строки, что поменялись за окно, — одним событием в конце. Список, где их набралось
 * больше `Rows::MAX` (импорт, команда по сотне ТС), перечитывается целиком.
 */
final class PublishRows implements ShouldQueue
{
    use Queueable;

    public function __construct(public array $topics, public string $key, public ?string $request = null) {}

    public function handle(Publisher $publish): void
    {
        // Метку снимаем до публикации: правка, пришедшая после, поставит новый хвост, а не потеряется.
        Cache::forget("live:tail:{$this->key}");
        $rows = Cache::lock("live:rows:lock:{$this->key}", 5)->block(2, fn () => Cache::pull("live:rows:{$this->key}", []));
        $whole = array_keys(array_filter($rows, fn (array $keys) => count($keys) > Rows::MAX));
        $whole && $publish->refresh($this->topics, $whole, $this->request);
        $publish->rows($this->topics, array_diff_key($rows, array_flip($whole)), $this->request);
    }
}
