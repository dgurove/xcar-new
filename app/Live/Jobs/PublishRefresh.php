<?php

namespace App\Live\Jobs;

use App\Live\Publisher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/** Хвост `Publisher::refreshSoon`: что поменялось за окно после первого перечитывания — одним перечитыванием в конце. */
final class PublishRefresh implements ShouldQueue
{
    use Queueable;

    public function __construct(public array $topics, public array $paths, public string $key) {}

    public function handle(Publisher $publish): void
    {
        // Метку снимаем до публикации: правка, пришедшая после, поставит новый хвост, а не потеряется.
        Cache::forget("live:tail:{$this->key}");
        $publish->refresh($this->topics, $this->paths);
    }
}
