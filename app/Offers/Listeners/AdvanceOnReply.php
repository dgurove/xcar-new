<?php

namespace App\Offers\Listeners;

use App\Mail\Composer;
use App\Mail\Message;
use App\Notifications\InsurerReplyNotice;
use App\Offers\Deal;
use App\Offers\Events\InsurerReplied;
use App\Offers\InsurerReplies;
use App\Offers\Jobs\ShareReplyFiles;
use App\Offers\OfferEventType;
use App\Workflow\Actions\TakeExit;
use App\Workflow\Actor;
use App\Workflow\Events\StageEntered;
use App\Workflow\Outcome;
use App\Workflow\Requirement;
use App\Workflow\Track;
use App\Workflow\WaitsFor;
use Illuminate\Events\Dispatcher;

/**
 * Страховая ответила по сделке (05.10.2026, Ford Kuga): менеджеру — текст ответа во всех каналах, а если в ответе есть
 * контакт, сделка идёт дальше без кнопки админа — шаг, что ждёт поставщика, проходит своим «Поставщик согласовал» или
 * «Ответ поставщика получен». Шаг пришёл позже письма (менеджер нажал «Покупаю», дальше снова ждём поставщика) — тоже
 * проходит. За менеджера ничего не жмём: его согласие остаётся его ходом. Контакт есть, а документов нет — просим их
 * сами; файлы ответа (вложения и облако по ссылке) — менеджеру в сделку (`ShareReplyFiles`).
 */
final class AdvanceOnReply
{
    private const EXITS = ['поставщик согласовал', 'ответ поставщика получен'];

    public const ASK_DOCUMENTS = 'Нужны документы для проверки и подготовки ДКП.';

    public function __construct(private TakeExit $take, private Composer $composer) {}

    public function subscribe(Dispatcher $events): array
    {
        return [InsurerReplied::class => 'replied', StageEntered::class => 'entered'];
    }

    public function replied(InsurerReplied $e): void
    {
        $deal = $e->deal->loadMissing(['offer', 'buyer']);
        $offer = $deal->offer;
        $message = $e->message->loadMissing(['attachments', 'account']);
        $contact = InsurerReplies::hasContact($message);
        $files = InsurerReplies::hasFiles($message);
        // Контакт дали, а документов нет — сами просим их ответом на это же письмо (05.10.2026).
        $asked = $e->live && $contact && ! $files && $this->askForDocuments($deal, $message);
        $offer->log(OfferEventType::InsurerReplied, null, array_filter(['letter' => $message->id, 'contact' => $contact, 'files' => $files, 'asked' => $asked]));
        if ($files) {
            ShareReplyFiles::dispatch($message->id);
        }
        $moved = $contact && $this->advance($deal, $message);
        if (! $deal->buyer) {
            return;
        }
        // Сдвинулся шаг — его ход в том же уведомлении: «Ваш ход» по этому шагу `Notify` не шлёт (`StageEntered::$letter`).
        $turn = $moved ? Requirement::where('deal_id', $deal->id)->whereNull('done_at')->latest('id')->first() : null;
        $deal->buyer->notify(new InsurerReplyNotice($deal, mb_substr($e->message->replyText(), 0, 1500), $turn));
    }

    public function entered(StageEntered $e): void
    {
        if ($e->track !== Track::Sale || $e->to->waits_for !== WaitsFor::Supplier || ! $e->exit) {
            return;
        }
        $deal = $e->deal ?? $e->offer->deal()->first();
        if (! $deal?->isActive()) {
            return;
        }
        $letter = InsurerReplies::for($deal)->first(fn (Message $m) => InsurerReplies::hasContact($m));
        if ($letter) {
            $this->advance($deal->setRelation('offer', $e->offer), $letter, quiet: false);
        }
    }

    /**
     * «Нужны документы для проверки и подготовки ДКП.» — ответом на письмо, с ящика, который пишет (deal@), всем, кто в
     * переписке. Не просим, если документы по сделке уже приходили или мы уже просили.
     */
    private function askForDocuments(Deal $deal, Message $letter): bool
    {
        if (InsurerReplies::for($deal)->contains(fn (Message $m) => InsurerReplies::hasFiles($m->loadMissing('attachments')))
            || $deal->offer->events()->where('type', OfferEventType::InsurerReplied)->where('created_at', '>=', $deal->created_at)->where('payload->asked', true)->exists()) {
            return false;
        }
        $reply = $this->composer->reply($letter, all: true);
        if (! $reply['to']) {
            return false;
        }
        $this->composer->create($letter->account->sender(), [...$reply, 'body' => '<p>'.self::ASK_DOCUMENTS.'</p>'.$reply['body']], $letter, null);

        return true;
    }

    /** Шаг ждёт поставщика — пройти его исходом «ответ получен». $quiet — «Ваш ход» скажет уведомление о письме. */
    private function advance(Deal $deal, Message $letter, bool $quiet = true): bool
    {
        $offer = $deal->offer->unsetRelation('positions');
        $stage = $offer->position(Track::Sale)?->stage;
        if ($stage?->waits_for !== WaitsFor::Supplier) {
            return false;
        }
        $exit = $stage->exitsFor(Actor::Staff, $deal)->first(fn (Outcome $x) => in_array(mb_strtolower(trim($x->label)), self::EXITS, true));
        if (! $exit) {
            return false;
        }
        ($this->take)($offer, $exit, Actor::Staff, null, $quiet ? ['letter' => $letter->id] : []);

        return true;
    }
}
