<?php

namespace App\Offers\Actions;

use App\Mail\Message;
use App\Mail\Template;
use App\Notifications\ExtraAskDecisionNotice;
use App\Offers\Deal;
use App\Offers\ExtraAsk;
use App\Offers\ExtraRequest;
use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Users\User;
use App\Workflow\Actions\EnterStage;
use App\Workflow\Outcome;
use App\Workflow\Position;
use App\Workflow\Requirement;
use App\Workflow\Stage;
use App\Workflow\Track;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ход доп. просьбы (`Offers\ExtraAsk`, 18.11.2026, владелец: «пока активна дополнительная просьба, ход сделки замораживается», «нам
 * прислали запрошенные данные, мы их менеджеру показали — должны подождать, когда он окнет»; «он окнул и сразу нажал „Беру в
 * работу“ либо отказ, чтобы не спрашивать его заново»). Пока просьба открыта, сроки этапа стоят (`TickStages`), снялась —
 * срок этапа сдвигается на время паузы (`thaw`).
 */
final class SettleExtraAsk
{
    public function __construct(private EnterStage $enter) {}

    /**
     * Просьба ушла письмом (`LogExtraRequest`). Шаг менеджера — у сделки с менеджером, пока поставщику не заплачено (после оплаты
     * отказываться не от чего), и не у просьбы подтвердить нашу оплату.
     */
    public function asked(Offer $offer, Message $message, ?User $by, string $text): ExtraAsk
    {
        $deal = $offer->deal;
        $deal = $deal?->isActive() && ! $deal->isParallel() ? $deal : null;
        $paying = ExtraRequest::picked($message->requests['ids'] ?? [])->contains(fn (Template $t) => $t->attach === Template::PAY_PROOF);
        $at = $message->date_at ?? now();
        // Менеджер сам просил «Доп. запрос…» (27.11.2026, `ExtraWant`) — письмо и есть его просьба: та же запись идёт дальше, пауза — с
        // его просьбы. Любое письмо с галками по этой сделке её закрывает (что отметить — решаем мы, его галки — подсказка).
        if ($deal && ($wanted = ExtraAsk::wanted($deal))) {
            $wanted->update(['message_id' => $message->id, 'text' => $text, 'asked_by' => $by?->id, 'asked_at' => $at, 'since_at' => $at]);
            Disclose::publish($deal);

            return $wanted;
        }
        $ask = ExtraAsk::create([
            'offer_id' => $offer->id, 'deal_id' => $deal?->id, 'message_id' => $message->id, 'text' => $text,
            'asks_manager' => $deal && $deal->buyer_id && ! $deal->supplierPaid() && ! $paying,
            'asked_by' => $by?->id, 'asked_at' => $at, 'since_at' => $at, 'frozen_at' => $at,
        ]);
        $deal && Disclose::publish($deal);

        return $ask;
    }

    /**
     * Первый ответ поставщика по машине (любая ветка, не автоответ — `OnMessage`): ждавшие его просьбы отвечены. На согласовании —
     * наш ход «Показать менеджеру»; иначе просьба решена ответом, этап идёт дальше.
     */
    public function answered(Offer $offer, Message $message): void
    {
        $at = $message->date_at ?? now();
        $asks = ExtraAsk::open()->where('offer_id', $offer->id)->whereNull('answered_at')->where('since_at', '<', $at)->with('deal')->get();
        foreach ($asks as $ask) {
            $manager = $ask->asks_manager && $ask->deal?->isActive();
            $ask->update(['answered_at' => $at, 'answer_message_id' => $message->id] + ($manager ? [] : ['decided_at' => now(), 'decision' => ExtraAsk::ANSWERED]));
            $offer->log(OfferEventType::ExtraRequest, null, ['state' => 'answered', 'letter' => $message->id, 'ask' => $ask->id]);
            $manager || $this->thaw($ask);
        }
        $asks->isNotEmpty() && $offer->deal && Disclose::publish($offer->deal);
    }

    /** Ответ показали менеджеру (`Disclose`): ход его — «Беру в работу» / «Я передумал» (уведомление с кнопками). */
    public function shown(Deal $deal, bool $notify = true): void
    {
        $asks = ExtraAsk::open()->where('deal_id', $deal->id)->where('asks_manager', true)->whereNotNull('answered_at')->whereNull('shown_at')->get();
        foreach ($asks as $ask) {
            $ask->update(['shown_at' => now()]);
        }
        $notify && $asks->isNotEmpty() && $deal->buyer?->notify(new ExtraAskDecisionNotice($asks->last()->setRelation('deal', $deal)));
    }

    /**
     * «Не запрашивать…» (27.11.2026): менеджер просил доп. запрос, а писать поставщику не будем — просьба снята, ход сделки снова
     * идёт (его вопрос «Берёте в работу?» остаётся), ему в чат — почему (`$text`, необязательно).
     */
    public function skip(ExtraAsk $ask, User $by, ?string $text = null): void
    {
        $text = trim((string) $text) ?: null;
        DB::transaction(function () use ($ask, $by, $text) {
            $ask = ExtraAsk::whereKey($ask->id)->lockForUpdate()->firstOrFail();
            if ($ask->phase() !== ExtraAsk::WANTED) {
                throw ValidationException::withMessages(['extra' => 'Доп. запрос уже отправлен или снят']);
            }
            $ask->update(['decided_at' => now(), 'decision' => ExtraAsk::SKIPPED, 'decided_by' => $by->id]);
            $ask->offer->log(OfferEventType::ExtraRequest, $by, ['state' => ExtraAsk::SKIPPED, 'ask' => $ask->id] + ($text ? ['answer' => $text] : []));
            $this->thaw($ask);
        });
        $ask = $ask->fresh(['deal.buyer', 'offer']);
        $deal = $ask?->deal;
        if ($deal?->buyer && $ask->offer->chatOpenFor($deal->buyer)) {
            app(\App\Chats\Actions\PlatformMessage::class)(app(\App\Chats\Actions\OpenChat::class)($ask->offer, $deal->buyer, greet: false),
                'Доп. запрос поставщику отправлять не будем'.($text ? ': '.$text : ''));
        }
        $deal && Disclose::publish($deal);
        \App\Offers\DealTabs::forget();
    }

    /** «Вопрос не решён» (ответ был пустым — «отсутствую на рабочем месте»): снова ждём первого ответа поставщика с этой минуты. */
    public function reopen(ExtraAsk $ask, User $by): void
    {
        DB::transaction(function () use ($ask, $by) {
            $ask = ExtraAsk::whereKey($ask->id)->lockForUpdate()->firstOrFail();
            if (! $ask->answered_at || ! in_array($ask->decision, [null, ExtraAsk::ANSWERED], true)) {
                throw ValidationException::withMessages(['extra' => 'Поставщик ещё не ответил или менеджер уже решил']);
            }
            // Решённая ответом (этап уже шёл дальше) — пауза начинается заново.
            $frozen = $ask->decided_at ? ['frozen_at' => now()] : [];
            $ask->update(['since_at' => now(), 'answered_at' => null, 'answer_message_id' => null, 'phone_answer' => null, 'shown_at' => null, 'decided_at' => null, 'decision' => null, 'decided_by' => null] + $frozen);
            $ask->offer->log(OfferEventType::ExtraRequest, $by, ['state' => 'reopened', 'ask' => $ask->id]);
        });
        $ask->deal && Disclose::publish($ask->deal);
    }

    /**
     * «Ответили не письмом» (по телефону): как ответ; на согласовании — сразу ход менеджера, ему уведомление. `$text` — что ответили
     * (24.11.2026, необязательно): строкой в шаге, в «Доп. просьбах» и в «Истории».
     */
    public function byPhone(ExtraAsk $ask, User $by, ?string $text = null): void
    {
        $ask = $ask->fresh('deal');
        if (! $ask || $ask->phase() !== ExtraAsk::SUPPLIER) {
            throw ValidationException::withMessages(['extra' => 'Поставщик уже ответил']);
        }
        $manager = $ask->asks_manager && $ask->deal?->isActive();
        $text = trim((string) $text) ?: null;
        $ask->update(['answered_at' => now(), 'phone_answer' => $text] + ($manager ? ['shown_at' => now()] : ['decided_at' => now(), 'decision' => ExtraAsk::ANSWERED, 'decided_by' => $by->id]));
        $ask->offer->log(OfferEventType::ExtraRequest, $by, ['state' => 'phone', 'ask' => $ask->id, ...($text ? ['answer' => $text] : [])]);
        $manager ? $ask->deal->buyer?->notify(new ExtraAskDecisionNotice($ask)) : $this->thaw($ask);
        $ask->deal && Disclose::publish($ask->deal);
    }

    /**
     * Решение менеджера (или за него): `take` — этап идёт дальше, а на «Согласии менеджера» это и есть «Беру в работу»; `drop` — как
     * «Я передумал» на согласии: тот же исход назад в приём, сделка отменяется.
     */
    public function decide(Deal $deal, string $decision, User $by): void
    {
        DB::transaction(function () use ($deal, $decision, $by) {
            $deal = Deal::whereKey($deal->id)->lockForUpdate()->firstOrFail();
            $ask = ExtraAsk::open()->where('deal_id', $deal->id)->where('asks_manager', true)->latest('id')->first();
            if (! $ask || ! $deal->isActive()) {
                throw ValidationException::withMessages(['extra' => 'Решать уже нечего']);
            }
            $offer = $deal->offer->unsetRelation('positions');
            $stage = $offer->position(Track::Sale)?->stage;
            $consent = $stage?->asksConsent() ? $stage : ($stage ? Stage::where('workflow_id', $stage->workflow_id)->with('exits.to')->get()->first(fn (Stage $s) => $s->asksConsent()) : null);
            $exit = $decision === ExtraAsk::TAKE
                ? ($stage?->asksConsent() ? $stage->loadMissing('exits.to')->exits->first(fn (Outcome $e) => $e->takes()) : null)
                : $consent?->loadMissing('exits.to')->exits->first(fn (Outcome $e) => $e->isRetreat());
            if ($decision === ExtraAsk::DROP && ! $exit?->to) {
                throw ValidationException::withMessages(['extra' => 'Отменить сделку отсюда нельзя — отмените её в CRM']);
            }
            $for = $by->id !== $deal->buyer_id;
            $this->close($deal, $decision, $by, $for);
            if ($exit?->to) {
                // Просьба шага согласия закрыта этим же ответом — второго вопроса нет.
                Requirement::where('deal_id', $deal->id)->whereNull('done_at')
                    ->update(['done_at' => now(), 'answer' => json_encode(['exit' => $exit->label, 'fields' => [], 'extra' => true] + ($for ? ['closed_by' => 'staff', 'by' => $by->id] : []), JSON_UNESCAPED_UNICODE)]);
                ($this->enter)($offer, $exit->to, $by, $for ? ['for' => 'manager'] : [], $exit);
            }
        });
        Disclose::publish($deal);
    }

    /** Менеджер сам нажал «Беру в работу» / «Я передумал» на согласии (кнопкой шага, в чате, в Telegram) — просьба решена. */
    public function consentAnswered(Deal $deal, Outcome $exit, User $by): void
    {
        if (! $exit->takes() && ! $exit->isRetreat()) {
            return;
        }
        $this->close($deal, $exit->takes() ? ExtraAsk::TAKE : ExtraAsk::DROP, $by, $by->id !== $deal->buyer_id);
    }

    /** Открытые просьбы сделки — решены; сроки этапа — дальше с учётом паузы. */
    private function close(Deal $deal, string $decision, User $by, bool $for): void
    {
        foreach (ExtraAsk::open()->where('deal_id', $deal->id)->get() as $ask) {
            $ask->update(['decided_at' => now(), 'decision' => $decision, 'decided_by' => $by->id]);
            $deal->offer->log(OfferEventType::ExtraRequest, $by, ['state' => $decision, 'ask' => $ask->id] + ($for ? ['for' => 'manager'] : []));
            $decision === ExtraAsk::TAKE && $this->thaw($ask);
        }
    }

    /**
     * Пауза кончилась: срок этапа, на котором стояли с начала паузы, и просьба менеджера на нём сдвигаются на её длину;
     * «просрочено» и «срок подходит» снимаются, если срок снова впереди.
     */
    private function thaw(ExtraAsk $ask): void
    {
        $from = $ask->frozen_at ?? $ask->asked_at;
        $position = Position::where('offer_id', $ask->offer_id)->where('track', Track::Sale)->first();
        if (! $from || ! $position?->deadline_at || ! $position->entered_at || $position->entered_at->gt($from)) {
            return;
        }
        $pause = (int) $from->diffInSeconds(now());
        $deadline = $position->deadline_at->copy()->addSeconds($pause);
        $position->update(['deadline_at' => $deadline] + ($deadline->isFuture() ? ['reminded_at' => null, 'overdue_at' => null] : []));
        Requirement::where('offer_id', $ask->offer_id)->where('stage_id', $position->stage_id)->whereNull('done_at')->whereNotNull('due_at')
            ->get()->each(fn (Requirement $r) => $r->update(['due_at' => $r->due_at->copy()->addSeconds($pause)]));
    }
}
