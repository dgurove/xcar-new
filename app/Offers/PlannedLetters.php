<?php

namespace App\Offers;

use App\Mail\Account;
use App\Mail\Composer;
use App\Mail\Message;
use App\Mail\SendState;
use App\Mail\Thread;
use App\Notifications\DealEventNotice;
use App\Notifications\Notify;
use App\Offers\Jobs\ReleasePlannedLetter;
use App\Users\User;
use App\Workflow\Actions\TakeExit;
use App\Workflow\Actor;
use App\Workflow\Events\StageEntered;
use App\Workflow\Outcome;
use App\Workflow\Stage;
use App\Workflow\Track;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * «Спросить менеджера и запланировать письмо…» (27.11.2026, начальник: пришло «ГОТС поступили на реализацию» / «Просим принять
 * участие» — жмём «Спросить менеджера», он долго думает, потом снова возвращаемся писать «подтверждаем актуальность»). Письмо шага
 * после согласия пишем сразу в окне письма, вместо «Отправить» — «Запланировать»: вопрос менеджеру и запись — одной транзакцией
 * (`plan`). Уходит само, когда менеджер берёт: вход на этап с тем же шаблоном исходом менеджера (`entered`, «Беру в работу» и
 * «Забираю в гараж»), после коммита — заданием (`ReleasePlannedLetter`): согласие никогда не падает из-за письма. Надёжность:
 * задание повторяется и не шлёт дважды (замок записи, `message_id`), отправка письма сама не дублируется (`SendMessage`
 * Queued → Sending), часы `offers:tick` (`sweep`) добирают потерянное и снимают ненужное. Не уходит: менеджер отказался, сделка
 * закрыта, письмо этого шаблона уже написали руками, шаг письма пройден. Одна дверь — своих путей в обход нет.
 */
final class PlannedLetters
{
    /** Через сколько подстраховка добирает письмо, которое должно было уйти по событию. */
    private const LATE_MINUTES = 2;

    /** Этап письма после согласия: исход «Беру в работу» с этапа согласия, у его этапа есть шаблон письма. */
    public static function letterAfter(?Stage $consent, ?Deal $deal): ?Stage
    {
        if (! $consent?->asksConsent()) {
            return null;
        }
        $take = $consent->exitsFor(Actor::Manager, $deal)->first(fn (Outcome $x) => $x->takes());
        $to = $take?->loadMissing('to')->to;

        return $to?->template_id ? $to : null;
    }

    /** Этап письма для «Спросить менеджера» (исход сотрудника на согласие). */
    public static function letterFor(Outcome $ask, ?Deal $deal): ?Stage
    {
        return $ask->loadMissing('to')->to?->asksConsent() ? self::letterAfter($ask->to, $deal) : null;
    }

    public static function pending(?Deal $deal): ?PlannedLetter
    {
        return $deal?->id ? PlannedLetter::where('deal_id', $deal->id)->where('state', PlannedLetter::PENDING)->latest('id')->first() : null;
    }

    /** Последнее по сделке, что показать в шаге: ждёт или не ушло. */
    public static function shown(?Deal $deal): ?PlannedLetter
    {
        return $deal?->id ? PlannedLetter::where('deal_id', $deal->id)->whereIn('state', [PlannedLetter::PENDING, PlannedLetter::FAILED])->latest('id')->first() : null;
    }

    /**
     * Запланировать. `$ask` — «Спросить менеджера» этой же транзакцией; null — менеджера уже спросили (сделка на согласии).
     * `$data` — проверенная форма окна письма (`MailController::send`).
     */
    public function plan(Offer $offer, ?Outcome $ask, Account $account, array $data, User $by): PlannedLetter
    {
        return DB::transaction(function () use ($offer, $ask, $account, $data, $by) {
            $offer = Offer::whereKey($offer->id)->lockForUpdate()->firstOrFail();
            $deal = $offer->deal()->first();
            if (! $deal?->isActive() || ! $deal->buyer_id) {
                throw ValidationException::withMessages(['body' => 'Сделки нет или она закрыта']);
            }
            if (self::pending($deal)) {
                throw ValidationException::withMessages(['body' => 'Письмо уже запланировано — отмените его в шаге, чтобы написать заново']);
            }
            $stage = $offer->unsetRelation('positions')->position(Track::Sale)?->stage;
            if ($ask) {
                if ($stage?->id !== $ask->stage_id) {
                    throw ValidationException::withMessages(['body' => 'Сделка уже на другом шаге — менеджера спросили или шаг сдвинули']);
                }
                $letter = self::letterFor($ask, $deal);
            } else {
                $letter = self::letterAfter($stage, $deal);
            }
            if (! $letter) {
                throw ValidationException::withMessages(['body' => 'После согласия менеджера у этой сделки нет письма поставщику']);
            }
            // Вложения проверяем сейчас, а не через день у «Беру в работу»: файла нет — письмо не планируется.
            $disk = \Illuminate\Support\Facades\Storage::disk(\App\Mail\Parts::CACHE_DISK);
            foreach ((array) ($data['files'] ?? []) as $path) {
                if (str_starts_with((string) $path, 'outbox/') && (! $disk->exists($path) || ! $disk->size($path))) {
                    throw ValidationException::withMessages(['files' => 'Файл «'.substr(basename((string) $path), 37).'» не загрузился, приложите его ещё раз']);
                }
            }
            $planned = PlannedLetter::create([
                'offer_id' => $offer->id, 'deal_id' => $deal->id, 'template_id' => $letter->template_id, 'account_id' => $account->id,
                'created_by' => $by->id, 'data' => self::form($data), 'state' => PlannedLetter::PENDING,
            ]);
            $offer->log(OfferEventType::Note, $by, ['text' => 'Письмо поставщику запланировано: уйдёт, когда менеджер возьмёт в работу']);
            if ($ask) {
                app(TakeExit::class)($offer, $ask, Actor::Staff, $by);
            }

            return $planned;
        });
    }

    /** Только поля формы, что нужны письму (`Composer::create`). */
    private static function form(array $data): array
    {
        return array_intersect_key($data, array_flip(['to', 'cc', 'bcc', 'subject', 'body', 'files', 'inline', 'parent', 'thread', 'quote', 'forward', 'offer', 'template']));
    }

    /**
     * Вошли на этап (`StageEntered`): исход менеджера на этап с шаблоном запланированного — письмо уходит (после коммита, заданием);
     * его отказ со «Согласия» — запланированное снимается.
     */
    public function entered(StageEntered $e): void
    {
        if ($e->track !== Track::Sale || ! $e->exit) {
            return;
        }
        $deal = $e->deal ?? $e->offer->deal()->first();
        $planned = self::pending($deal);
        if (! $planned) {
            return;
        }
        if ($e->exit->isRetreat()) {
            $this->cancel($planned, 'менеджер отказался');

            return;
        }
        if ($e->exit->actor === Actor::Manager && $e->to->template_id === $planned->template_id) {
            $id = $planned->id;
            DB::afterCommit(fn () => ReleasePlannedLetter::dispatch($id));
        }
    }

    /**
     * Отправить (задание `ReleasePlannedLetter`): под замком записи — заводим письмо и отдаём почте. Повтор безопасен: письмо уже
     * заведено — только снова в очередь, если оно ещё не ушло (`SendMessage` сам не шлёт дважды).
     */
    public function release(int $id): void
    {
        $message = DB::transaction(function () use ($id) {
            $planned = PlannedLetter::whereKey($id)->lockForUpdate()->first();
            if (! $planned) {
                return null;
            }
            if ($planned->state === PlannedLetter::SENT) {
                return $planned->message;
            }
            if ($planned->state !== PlannedLetter::PENDING) {
                return null;
            }
            $deal = Deal::find($planned->deal_id);
            $offer = Offer::find($planned->offer_id);
            $stage = $offer?->position(Track::Sale)?->stage;
            $why = match (true) {
                ! $deal?->isActive() => 'сделка закрыта',
                $this->written($planned) => 'письмо уже написали',
                $stage?->template_id !== $planned->template_id => 'шаг письма уже пройден',
                default => null,
            };
            if ($why) {
                $this->cancel($planned, $why);

                return null;
            }
            $data = $planned->data;
            $parent = ! empty($data['parent']) ? Message::with(['thread.account', 'attachments', 'account'])->find($data['parent']) : null;
            $thread = ! empty($data['thread']) ? Thread::where('account_id', $planned->account_id)->find($data['thread']) : null;
            try {
                $message = app(Composer::class)->create($planned->account, $data, $parent, $planned->author, $thread, dispatch: false);
            } catch (ValidationException $ex) {
                // Не повторяется само (файла нет) — сразу «не ушло», шаг письма остаётся «Написать поставщику».
                $this->fail($planned, collect($ex->errors())->flatten()->first() ?? 'письмо не завелось');

                return null;
            }
            if ($message->thread && ! $message->thread->offer_id) {
                $message->thread->update(['offer_id' => $planned->offer_id]);
            }
            $planned->update(['state' => PlannedLetter::SENT, 'message_id' => $message->id, 'released_at' => now()]);
            $offer->log(OfferEventType::Note, null, ['text' => 'Запланированное письмо поставщику ушло: менеджер взял в работу', 'letter' => $message->id]);

            return $message;
        });
        // Почте — после коммита: письмо и его ветка уже видны воркеру отправки.
        if ($message && $message->fresh()?->send_state === SendState::Queued) {
            app(Composer::class)->dispatch($message);
        }
    }

    /** Письмо этого шаблона по машине уже ушло руками после планирования — второе не нужно. */
    private function written(PlannedLetter $planned): bool
    {
        return OfferEvent::where('offer_id', $planned->offer_id)->where('type', OfferEventType::SupplierLetter)
            ->where('created_at', '>=', $planned->created_at)->whereRaw("payload->>'template_id' = ?", [(string) $planned->template_id])->exists();
    }

    public function cancel(PlannedLetter $planned, string $why, ?User $by = null): void
    {
        $done = PlannedLetter::whereKey($planned->id)->where('state', PlannedLetter::PENDING)
            ->update(['state' => PlannedLetter::CANCELLED, 'cancel_reason' => mb_substr($why, 0, 200), 'cancelled_at' => now(), 'updated_at' => now()]);
        if ($done) {
            Offer::find($planned->offer_id)?->log(OfferEventType::Note, $by, ['text' => 'Запланированное письмо поставщику отменено: '.$why]);
        }
    }

    /** Не смогли завести письмо — «не ушло», громко тем, кто ведёт сделки. */
    public function fail(PlannedLetter $planned, string $why): void
    {
        $done = PlannedLetter::whereKey($planned->id)->whereIn('state', [PlannedLetter::PENDING])
            ->update(['state' => PlannedLetter::FAILED, 'error' => mb_substr($why, 0, 500), 'updated_at' => now()]);
        $deal = Deal::with('offer')->find($planned->deal_id);
        if ($done && $deal) {
            $deal->offer->log(OfferEventType::Note, null, ['text' => 'Запланированное письмо поставщику не ушло: '.$why]);
            DB::afterCommit(fn () => Notify::toDeals(DealEventNotice::plannedFailed($deal, $why)));
        }
    }

    /**
     * Подстраховка (`offers:tick`, раз в минуту): менеджер взял, а письмо не ушло (событие или задание потерялось) — отправить; сделка
     * закрыта или ушла с согласия не к письму — снять; передано почте, а всё ещё в очереди — снова в очередь.
     */
    public function sweep(): int
    {
        $n = 0;
        foreach (PlannedLetter::where('state', PlannedLetter::PENDING)->with('deal')->get() as $planned) {
            $deal = $planned->deal;
            if (! $deal?->isActive()) {
                $this->cancel($planned, 'сделка закрыта');
                $n++;

                continue;
            }
            $stage = Offer::find($planned->offer_id)?->position(Track::Sale)?->stage;
            if ($stage?->template_id === $planned->template_id) {
                if ($planned->updated_at->lt(now()->subMinutes(self::LATE_MINUTES))) {
                    ReleasePlannedLetter::dispatch($planned->id);
                    $n++;
                }

                continue;
            }
            // Ждём согласия — на этапе согласия; иначе вопрос менеджеру отменили или сделку увели — письмо не нужно.
            if (! $stage?->asksConsent() && $planned->created_at->lt(now()->subMinutes(self::LATE_MINUTES))) {
                $this->cancel($planned, 'сделка ушла с шага согласия менеджера');
                $n++;
            }
        }
        foreach (PlannedLetter::where('state', PlannedLetter::SENT)->where('released_at', '>', now()->subDay())->where('released_at', '<', now()->subMinutes(5))
            ->whereHas('message', fn ($q) => $q->where('send_state', SendState::Queued->value))->get() as $planned) {
            app(Composer::class)->dispatch($planned->message);
            $n++;
        }

        return $n;
    }
}
