<?php

namespace App\Offers;

use App\Mail\Candidate;
use App\Mail\Chains\NodeTitle;
use App\Mail\Message;
use App\Mail\Scope;
use App\Mail\Thread;
use App\Media\ForManagers;
use App\Users\User;
use Illuminate\Support\Collection;

/**
 * Письма и документы предложения — одно место на редактор, карточка строки и страницу сделки: карточка «Письма»
 * (все письма строками, число, ждущие ответа) и шторка документов (письмо вендора, документы, файлы писем, фото).
 */
final class OfferFiles
{
    /**
     * Карточка «Письма» (x-mail.letters): все письма по машине строками, как в «Почте», — те же ветки, что окно писем
     * (`Thread::ofCar`: ящики продаж, у того, кто ведёт парковку, ещё ветки её ТС), поэтому число в заголовке и окно
     * сходятся. asks — последнее входящее ветки, что ждёт ответа (одно правило почты — `needs_reply_at`), stages —
     * письма этапов цепочки «Из писем».
     *
     * dealLetters — число писем без парковки (storage@ — своей лентой ниже, `x-mail.letters split`), для заголовка.
     *
     * @return array{messages: Collection<int, Message>, letters: int, dealLetters: int, asks: Collection, stages: Collection}
     */
    public static function letters(Offer $offer, ?User $user): array
    {
        $threads = Thread::ofCar($offer, $user)->get(['id', 'needs_reply_at']);
        $messages = $threads->isEmpty() ? collect() : Message::whereIn('thread_id', $threads->pluck('id'))
            ->with(['author', 'attachments', 'account'])->orderBy('date_at')->orderBy('id')->get();
        $waiting = $threads->whereNotNull('needs_reply_at')->pluck('id')->all();

        return [
            'messages' => $messages,
            'letters' => NodeTitle::count($messages),
            'dealLetters' => NodeTitle::count($messages->reject(fn (Message $m) => $m->account?->scope === Scope::Park)->values()),
            'asks' => $messages->filter(fn (Message $m) => in_array($m->thread_id, $waiting, true) && ! $m->isOurs())->groupBy('thread_id')->map->last()->values(),
            'stages' => $messages->isEmpty() ? collect() : collect(Candidate::where('offer_id', $offer->id)->latest('id')->first(['id', 'stages'])?->stages ?? [])->pluck('message_id'),
        ];
    }

    /** Документы ТС тому, кто с ней работает: сотруднику — все, менеджеру — отмеченные «Видно менеджеру». Писем нет. */
    public static function forManagers(Offer $offer, User $user): Collection
    {
        $papers = $offer->papers();

        // Счёт поставщика менеджеру — строкой в шаге оплаты и в «Информации по сделке» своим именем; в «Документах» он был
        // третьим разом под именем со сканера (10.11.2026 на странице сделки, 11.11.2026 — и в гараже).
        return $user->isAdmin() ? $papers : $papers->filter(fn ($m) => ForManagers::is($m) && ! $m->getCustomProperty('supplier_invoice'))->values();
    }
}
