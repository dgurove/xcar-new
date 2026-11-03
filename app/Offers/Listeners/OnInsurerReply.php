<?php

namespace App\Offers\Listeners;

use App\Mail\Composer;
use App\Mail\Direction;
use App\Mail\Message;
use App\Offers\Actions\Disclose;
use App\Offers\Deal;
use App\Offers\Events\InsurerReplied;
use App\Offers\InsurerReplies;
use App\Offers\Jobs\ShareReplyFiles;
use App\Offers\OfferEventType;
use App\Offers\ReplyReview;
use App\Vendors\DealFormat;
use App\Workflow\Events\StageEntered;
use App\Workflow\Track;
use App\Workflow\WaitsFor;
use Illuminate\Events\Dispatcher;

/**
 * Страховая ответила по сделке (05.10.2026, Ford Kuga). С 03.11.2026 письмо для менеджера не делает ничего (владелец: «пришло
 * письмо — менеджеру вообще ничего не показываем и не говорим даже, что пришёл ответ»): ни уведомления, ни шага сделки, ни
 * телефонов. Сделка встаёт в CRM в «Разобрать ответ» (`ReplyReview`), админ показывает выбранное — `Actions\Disclose`, там же
 * галкой шаг, который раньше проходил сам. Здесь — только наше: запись в историю, файлы ответа к машине без «Видно менеджеру»
 * (`ShareReplyFiles`), просьба документов страховой, ход шага письма — наш.
 */
final class OnInsurerReply
{
    public const ASK_DOCUMENTS = 'Нужны документы для проверки и подготовки ДКП.';

    public function __construct(private Composer $composer) {}

    public function subscribe(Dispatcher $events): array
    {
        return [InsurerReplied::class => 'replied', StageEntered::class => 'entered'];
    }

    public function replied(InsurerReplied $e): void
    {
        $deal = $e->deal->loadMissing(['offer.vendor', 'buyer']);
        $offer = $deal->offer;
        // Оптом (Каркаде) поставщик отвечает на заявку списком — с закупочными и чужими машинами; ответ отмечают «Ответом по
        // списку» (09.10.2026).
        if ($offer->vendor?->deal_format === DealFormat::Wholesale) {
            return;
        }
        $message = $e->message->loadMissing(['attachments', 'account']);
        $contact = InsurerReplies::hasContact($message);
        $files = InsurerReplies::hasFiles($message);
        // Контакт дали, а документов нет — сами просим их ответом на это же письмо (05.10.2026).
        // Только по ДКП: у ПРАЙМ и «страховой напрямую» данные страхователя не нужны (05.10.2026).
        $asked = $e->live && $deal->isDkp() && $contact && ! $files && $this->askForDocuments($deal, $message);
        $offer->log(OfferEventType::InsurerReplied, null, array_filter(['letter' => $message->id, 'contact' => $contact, 'files' => $files, 'asked' => $asked]));
        if ($files) {
            ShareReplyFiles::dispatch($message->id);
        }
        // Поставщик ответил на наше письмо — ход наш: отметить исход (11.10.2026, `Position::letterStatus`). Его срок снимается
        // (19.01.2027): иначе шаг краснел «на нас» за срок поставщика. Менеджер видит прежнее «Ждём поставщика»
        // (`Position::waitsForManager`).
        if (($position = $offer->unsetRelation('positions')->position(Track::Sale)) && $position->letterStatus() === 'sent') {
            $position->update(['waits_for' => WaitsFor::Us, 'deadline_at' => null, 'reminded_at' => null, 'overdue_at' => null,
                'payload' => [...$position->payload, 'reply_at' => ($message->date_at ?? now())->toIso8601String()]]);
        }
    }

    /**
     * Ответ поставщика в любой ветке предложения — offer@, пересылка сотрудника, а не только deal@ (27.10.2026: Альфа отвечает
     * когда как, сделки висели неделями «ждём поставщика»): шаг письма ждёт его — ход наш, «поставщик ответил». Менеджеру —
     * ничего. Наше письмо могло уйти не из приложения (личная почта) — ответ всё равно считается.
     */
    public static function mark(Deal $deal, Message $message): void
    {
        $position = $deal->offer->unsetRelation('positions')->position(Track::Sale);
        if (! $deal->isActive() || ! $position || ! in_array($position->letterStatus(), ['sent', 'unsent'], true)
            || $message->date_at?->lt($deal->created_at)) {
            return;
        }
        $position->update(['waits_for' => WaitsFor::Us, 'deadline_at' => null, 'reminded_at' => null, 'overdue_at' => null,
            'payload' => [...($position->payload ?? []), 'reply_at' => ($message->date_at ?? now())->toIso8601String()]]);
    }

    /**
     * Любое письмо страховой по идущей сделке менеджера — в «Разобрать ответ» (03.11.2026): на любой ящик, пересылка
     * сотрудника тоже (`InsurerReplies::counts`), после начала сделки. Оптом и без менеджера (аукцион) — нет: показывать некому.
     * Гонку двух разборов одного письма держит уникальный `message_id` (`firstOrCreate` — через `createOrFirst`).
     */
    public static function queue(Deal $deal, Message $message): void
    {
        $deal->loadMissing('offer.vendor');
        if (! $deal->isActive() || ! $deal->buyer_id || $deal->offer->vendor?->deal_format === DealFormat::Wholesale
            || $message->direction !== Direction::In || $message->date_at?->lt($deal->created_at) || ! InsurerReplies::counts($message)) {
            return;
        }
        $review = ReplyReview::firstOrCreate(['message_id' => $message->id], ['deal_id' => $deal->id, 'offer_id' => $deal->offer_id, 'state' => ReplyReview::PENDING]);
        $review->wasRecentlyCreated && Disclose::publish($deal);
    }

    /**
     * Шаг, что ждёт поставщика, наступил, а ответ, который его проходит (телефон, просьба реквизитов), уже пришёл и разобран
     * (менеджер нажал «Беру в работу» после письма): раньше шаг проходил сам, теперь письмо снова встаёт в «Разобрать ответ» —
     * с галкой шага (`Disclose::stepExit`). Шаг сдвинул сам «Показать менеджеру» (`$letter`) — нет: он только что разобран.
     */
    public function entered(StageEntered $e): void
    {
        if ($e->track !== Track::Sale || $e->to->waits_for !== WaitsFor::Supplier || ! $e->exit || $e->letter) {
            return;
        }
        $deal = $e->deal ?? $e->offer->deal()->first();
        if (! $deal?->isActive() || ! $deal->buyer_id) {
            return;
        }
        $letter = InsurerReplies::for($deal)->first(fn (Message $m) => InsurerReplies::hasContact($m) || InsurerReplies::asksRequisites($m));
        if (! $letter || ! Disclose::stepExit($deal->setRelation('offer', $e->offer), collect([$letter]))) {
            return;
        }
        ReplyReview::updateOrCreate(['message_id' => $letter->id], ['deal_id' => $deal->id, 'offer_id' => $deal->offer_id, 'state' => ReplyReview::PENDING, 'done_by' => null, 'done_at' => null]);
        Disclose::publish($deal);
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
}
