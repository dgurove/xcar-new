<?php

namespace App\Offers;

use App\Mail\Template;
use App\Users\User;
use Illuminate\Support\Collection;

/**
 * «Доп. запрос…» у менеджера рядом с «Беру в работу» (27.11.2026, Роман Корабельников: «везде рядом с „Беру в работу“ — „Доп.
 * запрос…“, галочкой отмечаю, что прошу: обычно осмотр и ПТС, иногда только ПТС»; владелец выбрал из макетов: кнопка в ряд с
 * «Беру в работу» (А), шторка с отмеченным в прошлый раз (2б), в CRM — шагом «Доп. запрос менеджера» в «Ходе сделки» (4б)).
 * Кнопка — только у менеджера с признаком `users.extra_ask_button` (шторка менеджера в CRM). Просьба — запись `ExtraAsk` без
 * письма (`Actions\WantExtra`): сделка во вкладке «Доп. просьба», группа «Менеджер просит», мы пишем письмо с его галками.
 */
final class ExtraWant
{
    /** Первыми — что просят почти всегда (Роман): ПТС и осмотр, по названию шаблона. */
    public const FIRST = ['Запросить ПТС', 'Согласовать осмотр'];

    /** Есть ли у менеджера кнопка в этой сделке: признак, его идущая сделка, доп. просьба её ещё не держит. */
    public static function can(?Deal $deal, ?User $user): bool
    {
        return $deal && $user && $user->extra_ask_button && $deal->buyer_id === $user->id && $deal->isActive() && ! $deal->isParallel()
            && ! ExtraAsk::holding($deal);
    }

    /**
     * Галки менеджера: шаблоны доп. запроса, кроме нашего (подписание в городе — город выбираем мы, подтвердить оплату — наша
     * оплата); ПТС и осмотр — первыми.
     *
     * @return Collection<int, Template>
     */
    public static function choices(): Collection
    {
        return once(fn () => Template::requests()->get()
            ->reject(fn (Template $t) => $t->attach === Template::PAY_PROOF || ExtraRequest::cityTemplate(collect([$t])) !== null)
            ->sortBy(fn (Template $t) => [($i = array_search($t->name, self::FIRST, true)) === false ? 9 : $i, $t->position ?? 999, $t->id])->values());
    }

    /** Только галки из `choices`, без повторов. @param array<int|string> $ids @return list<int> */
    public static function only(array $ids): array
    {
        $valid = self::choices()->pluck('id')->all();

        return array_values(array_unique(array_filter(array_map('intval', $ids), fn (int $id) => in_array($id, $valid, true))));
    }

    /** Галки подтверждения словами: «ПТС, осмотр». @param list<int>|null $ids */
    public static function wordsOf(?array $ids, bool $lower = false): string
    {
        return self::words(new ExtraAsk(['picks' => $ids ?? []]), $lower);
    }

    /** Галка словами менеджера: «Запросить ПТС» → «ПТС», «Согласовать осмотр» → «Осмотр». */
    public static function label(Template $t): string
    {
        return \Illuminate\Support\Str::ucfirst((string) preg_replace('/^(Запросить|Согласовать)\s+/u', '', $t->name));
    }

    /** Что отмечено в прошлый раз (вариант 2б): галки его последней просьбы. @return list<int> */
    public static function last(User $user): array
    {
        $ids = (array) (ExtraAsk::where('wanted_by', $user->id)->latest('id')->value('picks') ?? []);
        $valid = self::choices()->pluck('id')->all();

        return array_values(array_intersect(array_map('intval', is_string($ids) ? (json_decode($ids, true) ?: []) : $ids), $valid));
    }

    /** Окно письма доп. запроса с его галками (`MailController::compose` с `extra`). */
    public static function letterUrl(ExtraAsk $ask, Offer $offer): string
    {
        return '/work/mail/new?offer='.$offer->number.'&extra=1'.collect($ask->picks ?? [])->map(fn ($id) => '&pick[]='.(int) $id)->implode('');
    }

    /** Отмеченное словами через запятую: «ПТС, осмотр»; `lower` — внутри фразы («просит запросить: ПТС, осмотр»). */
    public static function words(ExtraAsk $ask, bool $lower = false): string
    {
        // Сокращение («ПТС», «ДКП» в начале) строчной не становится.
        $low = fn (string $w) => preg_match('/^\p{Lu}{2}/u', $w) ? $w : mb_lcfirst($w);

        // Порядок — как в шторке (ПТС и осмотр первыми), а не как в письме.
        $order = self::choices()->pluck('id')->flip();

        return ExtraRequest::picked($ask->picks ?? [])->sortBy(fn (Template $t) => $order[$t->id] ?? 99)->values()
            ->map(fn (Template $t, int $i) => $i === 0 && ! $lower ? self::label($t) : $low(self::label($t)))->implode(', ');
    }
}
