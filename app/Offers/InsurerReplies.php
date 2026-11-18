<?php

namespace App\Offers;

use App\Mail\Account;
use App\Mail\Direction;
use App\Mail\Extraction\CloudShare;
use App\Mail\Extraction\Intent;
use App\Mail\Extraction\Patterns;
use App\Mail\Message;
use App\Mail\Scope;
use App\Mail\Thread;
use Illuminate\Support\Collection;

/**
 * Ответы страховой по сделке (05.10.2026): письма вендора на ящик, который пишет (deal@), с начала сделки. offer@ только
 * принимает — там предложения и их пересылки, это не ответы. Менеджеру из них — ничего сам: только выбранное админом
 * (`Disclosures`, `Actions\Disclose`, 03.11.2026).
 */
final class InsurerReplies
{
    /** @return Collection<int, Message> новые сверху */
    public static function for(Deal $deal): Collection
    {
        $threads = Thread::where('offer_id', $deal->offer_id)
            ->whereIn('account_id', Account::where('scope', Scope::Offers)->whereNull('reply_account_id')->select('id'))->pluck('id');
        if ($threads->isEmpty()) {
            return collect();
        }

        return Message::whereIn('thread_id', $threads)->where('direction', Direction::In)->where('date_at', '>=', $deal->created_at)
            ->orderByDesc('date_at')->orderByDesc('id')->get()
            ->filter(fn (Message $m) => self::counts($m))->values();
    }

    /**
     * Поставщик молчит после нашего письма (18.11.2026, 8771/046/00635/26: «Поставщик просит реквизиты» нажали через две минуты
     * после «Подтверждаем актуальность», Альфа ничего не просила): время нашего последнего письма по сделке, если после него
     * ответа поставщика нет (`for`); писем нет или поставщик ответил — null. Кнопки ответа поставщика тогда переспрашивают.
     */
    public static function silentSince(Deal $deal): ?\Carbon\CarbonInterface
    {
        return once(function () use ($deal) {
            $threads = Thread::where('offer_id', $deal->offer_id)->pluck('id');
            $ours = $threads->isEmpty() ? null : Message::whereIn('thread_id', $threads)->where('direction', Direction::Out)
                ->where('date_at', '>=', $deal->created_at)->max('date_at');
            if (! $ours) {
                return null;
            }
            $ours = \Illuminate\Support\Carbon::parse($ours);
            $answered = self::for($deal)->contains(fn (Message $m) => $m->date_at && $m->date_at->gt($ours));

            return $answered ? null : $ours;
        });
    }

    /** Кнопка шага — это ответ поставщика («Поставщик просит реквизиты», «Счёт от поставщика получен»…): её жмёт сотрудник за поставщика. */
    public static function isSupplierAnswer(\App\Workflow\Outcome $exit): bool
    {
        $label = mb_strtolower(trim($exit->label));

        return str_starts_with($label, 'поставщик ') || str_starts_with($label, 'счёт от поставщика');
    }

    /** Ответ страховой, а не автоответ и не наше. */
    public static function counts(Message $message): bool
    {
        return $message->isFromVendor() && ! Intent::silent($message->intent) && $message->replyText() !== '';
    }

    /** В ответе есть файлы: вложения или ссылка на облако (`CloudShare`). */
    public static function hasFiles(Message $message): bool
    {
        return $message->files()->isNotEmpty() || CloudShare::links($message->replyText(marks: true)) !== [];
    }

    /**
     * Просят реквизиты для счёта (03.11.2026, Альфа: «Просьба прислать реквизиты для выставления счета. Без НДС.») — шаг
     * «Подтвердили покупку» — «Поставщик просит реквизиты» отмечена галкой в «Показать менеджеру» (`Disclose::stepExit`). Только текст ответа, без цитаты.
     */
    public static function asksRequisites(Message $message): bool
    {
        $text = mb_strtolower($message->replyText());

        return str_contains($text, 'реквизит') && preg_match('/сч[её]т/u', $text) === 1;
    }

    /**
     * Осмотр согласован (17.11.2026, Альфа по CTTM 5491/046/00861/26: «ЭПТС во вложении. Согласован визуальный осмотр ТС, ТС стоит
     * по адресу …») — ход менеджера, а не наше письмо (`Presets\Alfa::INSPECTION_AGREED`). «Не согласован» — нет. Только текст ответа.
     */
    public static function approvesInspection(Message $message): bool
    {
        $text = mb_strtolower($message->replyText());

        // Явное «согласован (визуальный) осмотр» / «осмотр … согласован», без «не». Шаблон Альфы «ГОТС поступили на реализацию»
        // («можно осмотреть ТС по согласованию с сотрудником филиала») — не согласие: это ещё до подтверждения актуальности.
        return preg_match('/(?<!не )согласован[аоы]?\s+(?:[а-яё]+\s+){0,2}осмотр|осмотр[а-яё]*\s+(?:[а-яё]+\s+){0,3}(?<!не )согласован[аоы]?(?![а-яё])/u', $text) === 1;
    }

    /** В ответе есть, кому звонить: телефон в тексте до подписи. */
    public static function hasContact(Message $message): bool
    {
        return Patterns::phones($message->replyText()) !== [];
    }
}
