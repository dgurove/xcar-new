<?php

namespace App\WhatsApp\Console;

use App\WhatsApp\Sender;
use Illuminate\Console\Command;

/** Для `deploy/check.sh`: что не так со службой WhatsApp — строками и кодом 1; всё в порядке — молча. */
final class Status extends Command
{
    protected $signature = 'whatsapp:status';

    protected $description = 'WhatsApp: служба в Цюрихе в порядке?';

    public function handle(Sender $sender): int
    {
        $problems = $sender->problems();
        foreach ($problems as $problem) {
            $this->line($problem);
        }

        return $problems ? self::FAILURE : self::SUCCESS;
    }
}
