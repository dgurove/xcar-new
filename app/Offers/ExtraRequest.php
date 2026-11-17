<?php

namespace App\Offers;

use App\Mail\Direction;
use App\Mail\Message;
use App\Mail\Template;
use App\Offers\Documents\PaymentProofPdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Доп. запрос поставщику (17.11.2026, владелец по CTTM Cargoline 5491/046/00861/26: Роман не давал покупателя, пока не будет
 * ПТС, места и контактов, Андрей написал руками «Просьба прислать ПТС и согласовать осмотр перед оплатой»; «мы часто
 * запрашиваем ПТС и согласование осмотра перед подтверждением актуальности, просто в системе нет такой функции»).
 *
 * Галки — шаблоны вида `request` (`Template::requests`, правятся в «Настройки → Шаблоны»): фраза каждой — после «Просьба …»,
 * в порядке из кабинета, одинаковый глагол подряд не повторяется («прислать ПТС, справку о закрытии лизинга и согласовать
 * осмотр»). Письмо — ответом в ветку поставщика тем же окном, что письмо этапа (`MailController::compose` с `extra`), что
 * спросили — `mail_messages.requests`; письмом этапа оно не считается (`MarkSupplierLetter`).
 */
final class ExtraRequest
{
    public const GREETING = 'Здравствуйте!';

    /** Хвост фразы, пока поставщику не заплачено: «…согласовать осмотр перед оплатой» (как писал Андрей). */
    public const BEFORE_PAY = 'перед оплатой';

    /**
     * Отмеченные галки в порядке фраз.
     *
     * @param  array<int|string>  $ids
     * @return Collection<int, Template>
     */
    public static function picked(array $ids): Collection
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));

        return $ids ? Template::requests()->whereKey($ids)->get() : collect();
    }

    /** Фраза без «Просьба»: «прислать ПТС и согласовать осмотр». Город — у шаблона с `{{ city }}` («в Москве»). */
    public static function phrase(Collection $picked, ?string $city = null): string
    {
        $groups = [];
        foreach ($picked as $template) {
            $text = trim((string) preg_replace('/\s+/u', ' ', strtr((string) $template->body, ['{{ city }}' => (string) $city, '{{city}}' => (string) $city])));
            $text = rtrim($text, " .,;");
            if ($text === '') {
                continue;
            }
            [$verb, $rest] = array_pad(preg_split('/\s+/u', $text, 2), 2, '');
            $last = array_key_last($groups);
            if ($last !== null && $rest !== '' && $groups[$last][0] === $verb && $groups[$last][1] !== []) {
                $groups[$last][1][] = $rest;
            } else {
                $groups[] = [$verb, $rest === '' ? [] : [$rest]];
            }
        }
        $parts = [];
        foreach ($groups as [$verb, $rests]) {
            $parts[] = trim($verb.' '.array_shift($rests));
            array_push($parts, ...$rests);
        }

        return self::join($parts);
    }

    /** Галка с городом (`{{ city }}` в фразе) — её варианты из кабинета («в Москве», «в Санкт-Петербурге»). */
    public static function cityTemplate(Collection $templates): ?Template
    {
        return $templates->first(fn (Template $t) => preg_match('/\{\{ ?city ?\}\}/u', (string) $t->body) === 1);
    }

    /** Город по умолчанию: вариант, в котором узнаётся город машины («Москва» → «в Москве»); не узнался — пусто, выберут. */
    public static function cityOf(Offer $offer, Collection $picked): ?string
    {
        $name = (string) $offer->settlement?->name;
        if ($name === '' || ! ($template = self::cityTemplate($picked))) {
            return null;
        }
        $stem = mb_strtolower(mb_substr($name, 0, max(4, mb_strlen($name) - 1)));

        return collect($template->choices ?? [])->first(fn ($c) => str_contains(mb_strtolower((string) $c), $stem));
    }

    /** «A», «A и B», «A, B и C». */
    public static function join(array $parts): string
    {
        $last = array_pop($parts);

        return $parts ? implode(', ', $parts).' и '.$last : (string) $last;
    }

    /** Хвост «перед оплатой»: идёт сделка, поставщику не заплачено и мы не просим подтвердить нашу оплату. */
    public static function tail(Offer $offer, Collection $picked): string
    {
        $deal = $offer->deal;
        $paying = $picked->contains(fn (Template $t) => $t->attach === Template::PAY_PROOF);

        return $deal && $deal->isActive() && ! $deal->supplierPaid() && PaymentProofPdf::files($deal)->isEmpty() && ! $paying ? ' '.self::BEFORE_PAY : '';
    }

    /** Текст письма: приветствие, просьба, подпись. Без галок — заготовка, которую дописывают руками. */
    public static function text(Offer $offer, Collection $picked, ?string $city = null): string
    {
        $phrase = self::phrase($picked, $city);
        $ask = $phrase === '' ? 'Просьба ' : 'Просьба '.$phrase.self::tail($offer, $picked).'.';

        return self::GREETING."\n\n".$ask."\n\n".Template::OFFERS_SIGN;
    }

    /**
     * Платёжка во вложение («Подтвердить оплату»): PDF оплаты сделки, если он есть и ещё не уходил ни одним нашим письмом по
     * этой машине (по имени файла — `PaymentProofPdf::fileName`).
     *
     * @param  Collection<int, Message>  $messages  письма машины с вложениями
     */
    public static function proofNeeded(Offer $offer, Collection $picked, Collection $messages): bool
    {
        $deal = $offer->deal;
        if (! $deal || ! $picked->contains(fn (Template $t) => $t->attach === Template::PAY_PROOF) || PaymentProofPdf::files($deal)->isEmpty()) {
            return false;
        }
        $name = mb_strtolower(PaymentProofPdf::fileName($deal));

        return ! $messages->contains(fn (Message $m) => $m->direction === Direction::Out
            && $m->attachments->contains(fn ($a) => mb_strtolower((string) $a->filename) === $name));
    }

    /** Что записать письму: галки и фраза словами на момент отправки (`mail_messages.requests`). */
    public static function record(Collection $picked, ?string $city = null): ?array
    {
        return $picked->isEmpty() ? null : ['ids' => $picked->pluck('id')->all(), 'names' => $picked->pluck('name')->all(), 'text' => self::phrase($picked, $city)];
    }

    /**
     * Последний запрос по машине для блока над письмами: когда, что спросили, ответили ли — входящее письмо по машине после
     * него в любой ветке (Альфа ответила новой веткой филиала, 16.11.2026).
     *
     * @param  Collection<int, Message>  $messages  письма машины (`OfferFiles::letters`)
     * @return array{message: Message, at: Carbon, text: string, answered: ?Carbon}|null
     */
    public static function last(Collection $messages): ?array
    {
        $asked = $messages->filter(fn (Message $m) => $m->direction === Direction::Out && ! empty($m->requests['text']))
            ->sortBy(fn (Message $m) => $m->date_at?->getTimestamp() ?? 0)->last();
        if (! $asked) {
            return null;
        }
        $answer = $messages->first(fn (Message $m) => $m->direction === Direction::In && ! $m->isOurs() && $m->date_at && $asked->date_at && $m->date_at->gt($asked->date_at));

        return ['message' => $asked, 'at' => $asked->date_at ?? $asked->created_at, 'text' => (string) $asked->requests['text'], 'answered' => $answer?->date_at];
    }
}
