<?php

namespace App\Logistics\Listeners;

use App\Live\Publisher;
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
        $topics = self::topics($e->transport, $e instanceof TransportAssigned ? $e->previous?->id : null);
        $offer = $e->transport->saleOffer();
        // События идут изнутри транзакции действия — перечитывать страницу раньше записи бессмысленно.
        DB::afterCommit(function () use ($topics, $offer) {
            $this->publish->refresh($topics, ['/transports', '/documents']);
            $this->publish->badges($topics);
            // Дорожка «Вывоз» в CRM и получение у менеджера сделки — та же задача.
            if ($offer) {
                $this->publish->refreshSoon(Topics::STAFF, ["/offers/{$offer->number}"]);
                if (($deal = $offer->deal) && $deal->buyer_id) {
                    $this->publish->refreshSoon(Topics::user($deal->buyer_id), ['/deals', "/deals/{$deal->id}", '/garage', "/garage/cars/{$offer->number}"]);
                }
            }
        });
    }

    /** Кто видит вывоз: ведущие логистику, логист задачи, исполнитель (и прежний — его список тоже меняется). */
    public static function topics(Transport $transport, ?int $previous = null): array
    {
        return User::logisticsLeads()->pluck('id')
            ->push($transport->executor_id, $transport->coordinator_id, $transport->executorUser?->logist_id, $previous)
            ->filter()->unique()->map(fn (int $id) => Topics::user($id))->values()->all();
    }
}
