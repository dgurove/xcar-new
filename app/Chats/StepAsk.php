<?php

namespace App\Chats;

use App\Chats\Actions\OpenChat;
use App\Chats\Actions\PlatformMessage;
use App\Notifications\Notify;
use App\Offers\Deal;
use App\Offers\SupplierInvoice;
use App\Support\Ago;
use App\Support\Money;
use App\Users\Wording;
use App\Workflow\Asks;
use App\Workflow\Position;
use App\Workflow\Requirement;
use App\Workflow\Track;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Ход менеджера — и сообщением в чате сделки с кнопками шага (10.11.2026, владелец: «пишут в чат, а не кнопку жмут»; «то же
 * сообщение, которое мы отправляем, только кнопки у него сделать», «и для других этапов»): площадка пишет «Привет,
 * подтверждаешь? Берём?» (согласие) или «Привет! Заберите автомобиль, до 24.11», под текстом — кнопки (`x-chat.step-keys`).
 * Одно сообщение на просьбу; ответили — под ним ответ, а не кнопки.
 */
final class StepAsk
{
    /** Написать в чат сделки о новой просьбе — после коммита: иначе чат увидит сообщение раньше шага. */
    public static function post(Requirement $requirement): void
    {
        $id = $requirement->id;
        DB::afterCommit(function () use ($id) {
            $r = Requirement::with(['deal.buyer', 'deal.offer', 'stage.exits'])->find($id);
            $deal = $r?->deal;
            if (! $r || $r->done_at || ! $deal?->buyer || ! $deal->offer || ! $deal->offer->chatOpenFor($deal->buyer)
                || Message::where('requirement_id', $r->id)->exists()) {
                return;
            }
            // Шаг уже ушёл (счёт закрылся пришедшими деньгами в той же транзакции) или деньги по сделке уже пришли — просить
            // «оплатить» нечего (12.11.2026: пачка счетов по VIN разослала «нужно оплатить» по оплаченным, владелец: «позоришь»).
            $current = Position::where('offer_id', $deal->offer_id)->where('track', Track::Sale)->value('stage_id');
            if (($current && $current !== $r->stage_id) || ($r->stage?->isPayStep() && $deal->credited() > 0)) {
                return;
            }
            // Оплатить страховой — сразу и сам счёт вложением (11.11.2026, владелец).
            $bill = $r->stage && $deal->paysSupplierOn($r->stage) ? SupplierInvoice::shown($deal->offer)->take(1) : collect();
            app(PlatformMessage::class)(app(OpenChat::class)($deal->offer, $deal->buyer, greet: false), self::text($r), $r, media: $bill);
        });
    }

    /**
     * Текст сообщения — без «вы» и «ты» (11.11.2026, владелец: «не „Оплатите“, а „Пришёл счёт от страховой“», «не „Привет!“, а
     * „Привет,“»): на согласии — «Привет, берём в работу?»; оплата страховой — «Привет, пришёл счёт от страховой на 1 593 000 ₽»
     * без срока (счёт — вложением, `post`); остальное — дело словами без обращения (`Wording::neutral`) и срок.
     */
    public static function text(Requirement $r): string
    {
        if ($r->stage?->asksConsent()) {
            return Notify::CONSENT_ASK;
        }
        $deal = $r->deal;
        // Срок в прошлом не пишется: «до 25.10» 12 ноября — не просьба, а упрёк.
        $due = $r->due_at?->isFuture() ? Ago::day($r->due_at, true) : null;
        if ($deal && $r->stage && $deal->paysSupplierOn($r->stage)) {
            $sum = $deal->supplierPayment();

            // Без срока оплаты (11.11.2026, владелец): только что пришло и сколько, сам счёт — вложением.
            return 'Привет, пришёл счёт от страховой'.($sum ? ' на '.Money::rub($sum) : '');
        }
        $task = $deal?->managerTask();
        $title = Wording::neutral(Wording::for($deal?->buyer, $task && $task['kind'] === 'ask' ? $task['title'] : $r->title));

        return 'Привет, '.$title.($due && ! str_ends_with($title, '?') ? ', до '.$due : '');
    }

    /**
     * Просьбы пачки сообщений одним запросом, с тем, что нужно кнопкам (`ManagerAsk`): сделка, этап с исходами, вывоз.
     *
     * @param  iterable<Message>  $messages
     */
    public static function prime(iterable $messages): void
    {
        $messages = collect($messages)->filter(fn (Message $m) => $m->requirement_id && ! $m->relationLoaded('requirement'));
        if ($messages->isEmpty()) {
            return;
        }
        $requirements = Requirement::with(['stage.exits.to', 'deal.offer.positions.stage.exits.to', 'deal.openRequirement.stage.exits.to', 'deal.buyer'])
            ->whereIn('id', $messages->pluck('requirement_id')->unique())->get()->keyBy('id');
        $messages->each(fn (Message $m) => $m->setRelation('requirement', $requirements->get($m->requirement_id)));
    }

    /**
     * Открытая просьба приложить документ по сделке этого чата — под фото и файлами менеджера «Это чек об оплате» (у него)
     * и «Приложить за менеджера» (у сотрудника). Чат сделки — площадки с её менеджером (`Chat::platformWith`).
     */
    public static function documentAsk(Chat $chat): ?Requirement
    {
        if (! $chat->offer_id || ! $chat->user_id || $chat->manager_id) {
            return null;
        }
        $deal = Deal::where('offer_id', $chat->offer_id)->where('buyer_id', $chat->user_id)->whereIn('state', ['active', 'parallel'])->latest('id')->first();
        $r = $deal?->openRequirement()->with('stage.exits')->first();

        return $r && $r->asks === Asks::Document ? $r->setRelation('deal', $deal) : null;
    }

    /** Открытая просьба менеджера по сделке этого чата (чип «С кнопками» в CRM). */
    public static function openFor(Chat $chat): ?Requirement
    {
        if (! $chat->offer_id || ! $chat->user_id || $chat->manager_id) {
            return null;
        }

        return Requirement::where('offer_id', $chat->offer_id)->where('user_id', $chat->user_id)->whereNull('done_at')
            ->whereHas('deal', fn ($d) => $d->whereIn('state', ['active', 'parallel']))->latest('id')->first();
    }

    /** Что за документ — словами шага: чек об оплате, подписанный договор, иначе документ. */
    public static function documentWord(Requirement $r): string
    {
        return match (true) {
            (bool) preg_match('/договор/iu', $r->stage?->name.' '.$r->title) => 'подписанный договор',
            (bool) $r->stage?->isPayStep() => 'чек об оплате',
            default => 'документ к шагу',
        };
    }

    /** Ответ под сообщением, когда просьбу закрыли: кто и что. */
    public static function answered(Requirement $r, bool $forStaff): string
    {
        $by = $r->answer['closed_by'] ?? null;
        $label = $r->answer['label'] ?? $r->answer['exit'] ?? null;

        return match (true) {
            $by === 'staff' => $forStaff ? 'За менеджера'.($label ? ': «'.$label.'»' : '') : 'Ответили за вас'.($label ? ': «'.Wording::for($r->user, $label).'»' : ''),
            $by === null && $label !== null => $forStaff ? '«'.$label.'»' : Wording::for($r->user, $label),
            default => 'Шаг пройден',
        };
    }

    /** @return Collection<int, Message> сообщения с кнопками этой просьбы — перерисовать после ответа */
    public static function messagesOf(int $requirementId): Collection
    {
        return Message::where('requirement_id', $requirementId)->with('chat')->get();
    }
}
