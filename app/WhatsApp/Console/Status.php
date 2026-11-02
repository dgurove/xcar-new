<?php

namespace App\WhatsApp\Console;

use App\WhatsApp\Group;
use Illuminate\Console\Command;

/** Для `deploy/check.sh`: что не так со службой WhatsApp и группой — строками и кодом 1; всё в порядке — молча. */
final class Status extends Command
{
    protected $signature = 'whatsapp:status';

    protected $description = 'WhatsApp: служба в Цюрихе и группа в порядке?';

    public function handle(Group $group): int
    {
        $problems = $group->problems();
        foreach ($problems as $problem) {
            $this->line($problem);
        }

        return $problems ? self::FAILURE : self::SUCCESS;
    }
}
