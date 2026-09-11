<?php

namespace App\Push\Console;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

class MakeKeys extends Command
{
    protected $signature = 'push:keys';

    protected $description = 'Пара ключей VAPID для .env.app';

    public function handle(): int
    {
        $keys = VAPID::createVapidKeys();
        $this->line('VAPID_PUBLIC_KEY='.$keys['publicKey']);
        $this->line('VAPID_PRIVATE_KEY='.$keys['privateKey']);

        return self::SUCCESS;
    }
}
