<?php

namespace App\Offers;

use App\Users\User;
use App\Workflow\Asks;
use App\Workflow\Outcome;
use App\Workflow\Requirement;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Дело менеджера и чем на него ответить — одна дверь для чата, полки «Ждут вас», строки «Сделок» и вопроса при входе
 * (10.11.2026, владелец: «пишут в чат, а не кнопку жмут»). Что сделать — `Deal::managerTask`; кнопки — исходы просьбы
 * (`Handover::managerExits`, у параллельной — `Parallels::exits`), только у шага «просто нажать». Документ, покупатель,
 * поля и цена — ссылкой на шаг сделки с подписью, что там сделать: приложить файл с чужого экрана не выйдет.
 */
final class ManagerAsk
{
    private ?Handover $handover = null;

    /**
     * @param  Collection<int, Outcome>  $moves
     * @param  Collection<int, Outcome>  $retreats
     */
    private function __construct(
        public readonly Deal $deal,
        public readonly ?Requirement $requirement,
        public readonly string $title,
        public readonly ?Carbon $due,
        public readonly string $kind,
        public readonly Collection $moves,
        public readonly Collection $retreats,
        public readonly ?string $link,
        public readonly ?string $blocker,
    ) {}

    /**
     * Дела менеджера по его сделкам — полке «Ждут вас» на «Предложениях» и вопросу при входе: просроченное и ближний срок
     * первыми. Те же сделки, что «Ваш ход» в «Сделках» (`Deal::MANAGER_TURN_SQL`, `managerTask`).
     *
     * @return Collection<int, self>
     */
    public static function of(User $me): Collection
    {
        if (! $me->hasDeals()) {
            return collect();
        }
        // Полка «Предложений» и вопрос при входе спрашивают на одной странице — один запрос на запрос.
        static $seen = [];
        $key = $me->id.':'.spl_object_id(request());

        return $seen[$key] ??= Deal::with(['offer.media', 'offer.positions.stage.block', 'offer.positions.stage.exits.to', 'openRequirement.stage.exits.to', 'garageCar.offer'])
            ->where('buyer_id', $me->id)->whereIn('state', [DealState::Active, DealState::Parallel])->whereRaw(Deal::MANAGER_TURN_SQL)->get()
            ->each(fn (Deal $d) => Parallels::dress($d->loadMissing('shadowStage')))
            ->map(fn (Deal $d) => self::for($d))->filter()
            ->sortBy(fn (self $a) => $a->due?->getTimestamp() ?? PHP_INT_MAX)->values();
    }

    public static function for(Deal $deal): ?self
    {
        $task = $deal->managerTask();
        if (! $task) {
            return null;
        }
        $req = $task['kind'] === 'ask' ? $deal->openRequirement : null;
        $stage = $req?->stage;
        $exits = $deal->managerExits($stage);
        [$retreats, $moves] = $exits->partition(fn (Outcome $e) => $e->isRetreat());
        // Закрыть сделку, пока автомобиль не забрали, нельзя — вместо кнопки на шаге написано, что сначала.
        $blocker = $moves->contains(fn (Outcome $e) => Deal::closes($e)) ? $deal->closeBlocker() : null;
        if ($blocker) {
            $moves = $moves->reject(fn (Outcome $e) => Deal::closes($e));
        }
        // Выбор времени подписания — днём и временем, а не голой кнопкой «Время согласовано» (`Signing`, 11.11.2026).
        $tap = $req && $req->asks === Asks::Nothing && $moves->isNotEmpty() && ! Signing::picks($stage);

        return new self($deal, $req, $task['title'], $task['due'], $task['kind'], $tap ? $moves->values() : collect(),
            $tap ? $retreats->values() : collect(), $tap ? null : self::linkLabel($deal, $task['kind'], $req, $task['title']), $blocker);
    }

    /** Что сделать на шаге, когда одной кнопкой не ответить. */
    private static function linkLabel(Deal $deal, string $kind, ?Requirement $req, string $title = ''): string
    {
        $stage = $req?->stage;

        return match (true) {
            $kind === 'reprice' => 'Назвать цену',
            $kind === 'signing', Signing::picks($req?->stage) => 'Выбрать время',
            $kind === 'price' => 'Указать цену',
            $kind === 'passport' => 'Приложить паспорт',
            // Покупатель выбран, а данных для договора нет — «Заполнить данные», а не «Указать покупателя» (11.11.2026).
            $kind === 'buyer' && str_starts_with($title, 'Заполните') => 'Заполнить данные',
            $kind === 'buyer', $req?->asks === Asks::Buyer => 'Указать покупателя',
            $req?->asks === Asks::Fields => 'Заполнить',
            $req?->asks === Asks::Document && (bool) preg_match('/договор/iu', $stage?->name.' '.$req->title) => 'Приложить договор',
            $req?->asks === Asks::Document && $stage && $deal->paysSupplierOn($stage) => 'Приложить чек',
            (bool) $stage?->isPayStep() => 'Оплатить',
            $req?->asks === Asks::Document => 'Приложить документ',
            default => 'Открыть сделку',
        };
    }

    /** Ответ одной кнопкой (исходы шага), а не переходом на шаг. */
    public function taps(): bool
    {
        return $this->moves->isNotEmpty();
    }

    /** Шаг сделки на её странице (гаражная — машина гаража): там приложить, выбрать, оплатить. */
    public function href(): string
    {
        return $this->deal->href().'#step';
    }

    /** Шаг получения, и менеджер может попросить логистов вместо того, чтобы забирать сам. */
    public function canAskPickup(): bool
    {
        return (bool) $this->requirement?->stage?->isPickupStep() && $this->handover()->canRequest;
    }

    public function handover(): Handover
    {
        return $this->handover ??= Handover::for($this->deal);
    }

    /** Просрочено — для цвета срока. */
    public function late(): bool
    {
        return $this->due !== null && $this->due->isPast();
    }
}
