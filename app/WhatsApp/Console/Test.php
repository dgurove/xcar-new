<?php

namespace App\WhatsApp\Console;

use App\Offers\OfferNumber;
use App\Users\User;
use App\WhatsApp\Gateway;
use App\WhatsApp\Group;
use Illuminate\Console\Command;

/**
 * Проверка: прислать в группу перечисленные предложения тем же сообщением и в том же темпе, что и сами, но не помнить их
 * отправленными (`whatsapp_sent`) — в группу менеджеров они потом придут как обычно. Доступ тот же: открытое не каждому
 * в группе не уходит.
 */
final class Test extends Command
{
    protected $signature = 'whatsapp:test {numbers* : номера предложений}';

    protected $description = 'WhatsApp: прислать в группу предложения для проверки';

    public function handle(Group $group, Gateway $gateway): int
    {
        if (! $gateway->configured()) {
            $this->error('WHATSAPP_VIA не задан');

            return self::FAILURE;
        }
        foreach ($this->argument('numbers') as $number) {
            $offer = OfferNumber::find($number);
            if (! $offer) {
                $this->warn("{$number}: такого предложения нет");

                continue;
            }
            $status = $gateway->status();
            if ($trouble = Group::trouble($status)) {
                $this->error($trouble);

                return self::FAILURE;
            }
            [$people, $outsiders] = $group->members($status);
            if ($outsiders) {
                $this->error('В группе не из xcar или с закрытым доступом: '.implode(', ', $outsiders));

                return self::FAILURE;
            }
            if ($people->contains(fn (User $user) => ! $offer->isVisibleTo($user))) {
                $this->warn("{$number}: открыто не всем в группе — пропускаю");

                continue;
            }
            if ($wait = $group->wait()) {
                $this->line("Пауза {$wait} с");
                sleep($wait);
            }
            $sent = $group->deliver($offer, 'test:'.$offer->id.':'.time(), false);
            $this->line(($sent ? 'Ушло: ' : 'Не ушло (см. лог): ').$number.' '.$offer->titleWithYear());
        }

        return self::SUCCESS;
    }
}
