<?php

namespace App\WhatsApp\Console;

use App\Offers\OfferNumber;
use App\Users\User;
use App\WhatsApp\Gateway;
use App\WhatsApp\Message;
use App\WhatsApp\Sender;
use Illuminate\Console\Command;

/**
 * Проверка: прислать перечисленные предложения тем же сообщением и в том же темпе, что и сами, но не помнить их
 * отправленными (`whatsapp_messages`) — потом они придут как обычно. Без `--to` — в группу, с `--to=<id человека>` — ему в
 * личные (WhatsApp у него должен быть подключён).
 */
final class Test extends Command
{
    protected $signature = 'whatsapp:test {numbers* : номера предложений} {--to= : id человека — в личные}';

    protected $description = 'WhatsApp: прислать предложения для проверки';

    public function handle(Sender $sender, Gateway $gateway): int
    {
        if (! $gateway->configured()) {
            $this->error('WHATSAPP_VIA не задан');

            return self::FAILURE;
        }
        $to = $this->option('to') ? User::find($this->option('to')) : null;
        if ($this->option('to') && ! $to?->whatsapp_jid) {
            $this->error('У этого человека WhatsApp не подключён');

            return self::FAILURE;
        }
        foreach ($this->argument('numbers') as $number) {
            $offer = OfferNumber::find($number);
            if (! $offer) {
                $this->warn("{$number}: такого предложения нет");

                continue;
            }
            if ($trouble = Sender::trouble($gateway->status(), $to === null)) {
                $this->error($trouble);

                return self::FAILURE;
            }
            if ($wait = $sender->wait()) {
                $this->line("Пауза {$wait} с");
            }
            $key = 'test:'.$offer->id.':'.time();
            $sent = $sender->deliver($to ? Message::personal($offer, $to, $key) : Message::group($offer, $key), false);
            $this->line(($sent ? 'Ушло: ' : 'Не ушло (см. лог): ').$number.' '.$offer->titleWithYear());
        }

        return self::SUCCESS;
    }
}
