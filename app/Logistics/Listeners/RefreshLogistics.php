<?php

namespace App\Logistics\Listeners;

use App\Live\Publisher;
use App\Live\RefreshCar;
use App\Live\Topics;
use App\Logistics\Events\TransportAssigned;
use App\Logistics\Events\TransportMoved;
use App\Logistics\Events\TransportRequested;
use App\Logistics\Transport;
use App\Users\User;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;

/**
 * Вывоз поменялся — у тех, кто его видит (админы и модераторы, логист задачи, исполнитель и прежний), список
 * и открытая задача перечитываются морфом и значки пересчитываются: без обновления руками и без опроса.
 */
final class RefreshLogistics
{
    public function __construct(private Publisher $publish) {}

    public function subscribe(Dispatcher $events): array
    {
        return [
            TransportRequested::class => 'refresh',
            TransportAssigned::class => 'refresh',
            TransportMoved::class => 'refresh',
        ];
    }

    public function refresh(TransportRequested|TransportAssigned|TransportMoved $e): void
    {
        $t = $e->transport;
        $topics = self::topics($t, $e instanceof TransportAssigned ? $e->previous?->id : null);
        $offer = $t->saleOffer();
        // События идут изнутри транзакции действия — перечитывать страницу раньше записи бессмысленно.
        DB::afterCommit(function () use ($t, $topics, $offer) {
            // Открытая задача — тоже (19.10.2026: сняли исполнителя, а у второго ведущего карточка висела прежней).
            $this->publish->refresh($topics, ['/transports', '/documents', "/transports/{$t->id}"]);
            $this->publish->badges($topics);
            // Дорожка «Вывоз» в CRM (редактор и окошко «Сделок» / «Гаража») и получение у менеджера сделки — та же задача.
            if ($offer) {
                $n = $offer->number;
                $this->publish->refreshSoon(Topics::STAFF, ["/offers/{$n}"]);
                $this->publish->rowsSoon(Topics::STAFF, ['/work/deals' => RefreshCar::deals($offer), '/work/garage' => $offer->garageCar ? [$n] : []]);
                if (($deal = $offer->deal) && $deal->buyer_id) {
                    $this->publish->refreshSoon(Topics::user($deal->buyer_id), ['/deals', "/deals/{$deal->id}", '/garage', "/garage/cars/{$n}"]);
                }
            }
        });
    }

    /**
     * Кто видит вывоз: ведущие логистику, логист задачи, исполнитель (и прежний — его список тоже меняется). Заявку логиста
     * от его заказчика ведущие не видят — им и не перечитываем.
     */
    public static function topics(Transport $transport, ?int $previous = null): array
    {
        return ($transport->isOrder() ? collect() : User::logisticsLeads()->pluck('id'))
            ->push($transport->executor_id, $transport->logist_id, $transport->executor?->logist_id, $previous)
            ->filter()->unique()->map(fn (int $id) => Topics::user($id))->values()->all();
    }
}
