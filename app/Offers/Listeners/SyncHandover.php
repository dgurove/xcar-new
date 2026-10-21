<?php

namespace App\Offers\Listeners;

use App\Logistics\Actions\SelfPickup;
use App\Logistics\Events\TransportAssigned;
use App\Logistics\Events\TransportMoved;
use App\Logistics\Events\TransportRequested;
use App\Logistics\Point;
use App\Notifications\YourTurnNotice;
use App\Offers\CarPlace;
use App\Offers\Deal;
use App\Offers\Handover;
use App\Offers\Offer;
use App\Users\Role;
use App\Users\User;
use App\Workflow\Actions\TakeExit;
use App\Workflow\Actor;
use App\Workflow\Events\StageEntered;
use App\Workflow\Outcome;
use App\Workflow\Requirement;
use App\Workflow\Track;
use App\Workflow\WaitsFor;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Шаг получения продажи («Менеджер забирает автомобиль» → «Автомобиль забрал», у гаража «Автомобиль передан») и вывоз в
 * логистике — одно событие (17.10.2026: вывоз — задача логистики, а не маршрут). Шаг проходит сам:
 * — менеджер сделки сам вывозит и нажал в логистике «Забрал» — машина у него;
 * — вывоз привёз машину в гараж менеджера сделки («Привёз» с точкой `keeper`);
 * — продажа пришла на шаг получения, а машина уже у него.
 * Обратно: менеджер нажал в сделке «Автомобиль забрал» — задача вывоза закрывается «у него» (`SelfPickup`; вёл логист — отменена).
 * Конец пути гаражной ставит машину на «Подготовку» (`CloseGarageDeal`, `StartPrepOnArrival`).
 * Чей ход на шаге получения — того, кто везёт (`turn`, 17.10.2026, прогон этапов): везёт логистика — ход наш, без часов и
 * просьбы менеджеру; вывоз отменили или привезли к нам — снова его «Заберите автомобиль».
 */
final class SyncHandover
{
    public function __construct(private TakeExit $take, private SelfPickup $self) {}

    public function subscribe(Dispatcher $events): array
    {
        return [
            TransportMoved::class => 'moved',
            TransportAssigned::class => 'assigned',
            TransportRequested::class => 'assigned',
            StageEntered::class => 'entered',
        ];
    }

    public function moved(TransportMoved $e): void
    {
        $t = $e->transport;
        $offer = $t->saleOffer();
        $deal = $offer?->deal()->first();
        if (! $deal?->isActive()) {
            return;
        }
        $deal->setRelation('offer', $offer);
        $own = $deal->buyer_id !== null && $t->executor_id === $deal->buyer_id;
        $handed = $e->step === 'delivered' && $t->destination === Point::Keeper && $offer->keeper()?->id === $deal->buyer_id;
        if (($e->step === 'picked' && $own) || $handed) {
            $this->pressSale($deal, $e->by ?? $this->system());
        }
        $this->turn($offer->fresh());
    }

    /** Назначили, сняли или попросили вывоз — ход на шаге получения за тем, кто везёт. */
    public function assigned(TransportAssigned|TransportRequested $e): void
    {
        if ($offer = $e->transport->saleOffer()) {
            $this->turn($offer);
        }
    }

    /** Продажа пришла на шаг получения, а машина уже у менеджера сделки. */
    public function entered(StageEntered $e): void
    {
        if ($e->track !== Track::Sale) {
            return;
        }
        $offer = $e->offer;
        $deal = $e->deal ?? $offer->deal()->first();
        // «Автомобиль забрал» в сделке — машина у менеджера: вывоз в логистике закрыт, ему туда ходить не нужно.
        if ($e->exit && $e->exit->actor === Actor::Manager && Handover::picked($e->exit) && $deal?->buyer) {
            ($this->self)($offer, $deal->buyer, $e->by ?? $this->system());

            return;
        }
        if (! $deal?->isActive() || $offer->car_place !== CarPlace::Keeper || $offer->standsWith()?->id !== $deal->buyer_id) {
            if ($deal && $e->to->isPickupStep()) {
                $this->turn($offer, $deal);
            }

            return;
        }
        $this->pressSale($deal->setRelation('offer', $offer), $e->by ?? $this->system());
    }

    /**
     * Шаг «Менеджер забирает автомобиль» ждёт того, кто везёт: открытый вывоз не у менеджера (логист, наши, другой менеджер,
     * ещё никто — вывоз заказан) — ход наш, часов и просьбы «Заберите автомобиль» у менеджера нет (Совкомбанк с логистом:
     * он видел «Заберите» и копил просрочку за чужую дорогу). Вывоза нет, он у менеджера или закрыт — ход его, часы от сейчас.
     */
    private function turn(Offer $offer, ?Deal $deal = null): void
    {
        $deal ??= $offer->deal()->first();
        $position = $offer->unsetRelation('positions')->position(Track::Sale);
        if (! $deal?->isActive() || ! $position?->stage->isPickupStep()) {
            return;
        }
        $ours = $deal->setRelation('offer', $offer->unsetRelation('pickupTask'))->carried();
        if ($ours && $position->waits_for !== WaitsFor::Us) {
            $position->update(['waits_for' => WaitsFor::Us, 'deadline_at' => null, 'reminded_at' => null, 'overdue_at' => null]);
            Requirement::where('deal_id', $deal->id)->where('stage_id', $position->stage_id)->whereNull('done_at')->delete();
        } elseif (! $ours && $position->waits_for === WaitsFor::Us) {
            $position->update(['waits_for' => null, 'deadline_at' => $position->stage->deadlineFor($offer, Carbon::now()), 'reminded_at' => null, 'overdue_at' => null]);
            $open = Requirement::where('deal_id', $deal->id)->where('stage_id', $position->stage_id)->whereNull('done_at')->exists();
            // Ход вернулся к нему (выбрали «Забирает менеджер» после входа в шаг, логистику сняли, привезли к нам) — «Ваш ход».
            // После коммита: событие идёт из транзакции «Принять» или назначения, а уведомление — в очереди (`after_commit`
            // выключен) — работник мог взять его раньше и не найти просьбы.
            if (! $open && $deal->buyer && $position->stage->awaitsManager($deal)) {
                $requirement = Requirement::askFor($deal, $position->stage, $position);
                $buyer = $deal->buyer;
                DB::afterCommit(fn () => $buyer->notify(new YourTurnNotice($requirement->load('offer'))));
            }
        }
    }

    private function pressSale(Deal $deal, User $by): void
    {
        /** @var Offer $offer */
        $offer = $deal->offer->unsetRelation('positions');
        $stage = $offer->position(Track::Sale)?->stage;
        // Шаг его — «Автомобиль забрал»; отдавали мы (гараж, везём ему сами) — «Автомобиль передан».
        $exit = $stage?->exitsFor(Actor::Manager, $deal)->first(fn ($x) => Handover::picked($x))
            ?? $stage?->exitsFor(Actor::Staff, $deal)->first(fn ($x) => self::handsOver($x));
        if ($exit) {
            ($this->take)($offer, $exit, $exit->actor, $by);
        }
    }

    /** Исход шага передачи: менеджер забрал или мы передали. */
    private static function handsOver(Outcome $exit): bool
    {
        return ($exit->actor === Actor::Manager && Handover::picked($exit))
            || ($exit->actor === Actor::Staff && mb_strtolower(trim($exit->label)) === 'автомобиль передан');
    }

    private function system(): User
    {
        return User::withRole(Role::Admin)->orderBy('id')->firstOrFail();
    }
}
