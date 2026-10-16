<?php

namespace App\Vendors\Actions;

use App\Mail\Direction;
use App\Mail\Message;
use App\Mail\Scope;
use App\Mail\Thread;
use App\Mail\Threads;
use App\Offers\Offer;
use App\Vendors\Vendor;

/**
 * Предложению из письма поставили вендора руками, а разбор отправителя не узнал (новая страховая «ТИТ» пишет с
 * 4959678612@mail.ru, 16.10.2026): адрес из «От:» письма — вендору в `senders` точным адресом, не доменом (у mail.ru
 * домен общий). Следующее письмо этого сотрудника узнаётся само; ветки предложения получают вендора сразу.
 */
final class LearnSenders
{
    public function __construct(private Threads $threads) {}

    public function __invoke(Offer $offer): int
    {
        $vendor = $offer->vendor;
        if (! $vendor) {
            return 0;
        }
        $threads = Thread::withoutGlobalScopes()->where('offer_id', $offer->id)
            ->whereHas('account', fn ($a) => $a->where('scope', Scope::Offers))->get();
        $learned = [];
        foreach ($threads as $thread) {
            foreach (Message::where('thread_id', $thread->id)->where('direction', Direction::In)->get() as $message) {
                $sender = mb_strtolower(trim((string) $message->field('sender')));
                if ($sender === '' || ! $message->isFromVendor() || $message->field('vendor_id') || Vendor::forSender($sender, Scope::Offers)) {
                    continue;
                }
                $learned[$sender] = true;
            }
        }
        if ($learned) {
            $vendor->forceFill(['senders' => array_values(array_unique([...($vendor->senders ?? []), ...array_keys($learned)]))])->save();
            foreach ($threads as $thread) {
                $this->threads->refresh($thread);
            }
        }

        return count($learned);
    }
}
