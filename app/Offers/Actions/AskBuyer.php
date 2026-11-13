<?php

namespace App\Offers\Actions;

use App\Notifications\BuyerAskNotice;
use App\Offers\Deal;
use App\Offers\DealState;
use App\Workflow\Asks;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Покупатель — дело менеджера сразу после «Беру в работу» (10.11.2026, владелец: «реквизиты требовать, как только взял в работу,
 * а не после того, как страховая запросила»; письмо страховой «пришлите реквизиты» — наша задача: отправить то, что он дал).
 * `ask` — после согласия: покупателя нет или он без данных — `buyer_asked_at` без срока (11.11.2026, владелец: «Альфа может попросить реквизиты
 * через месяц, зачем зазря торопить»), менеджеру
 * `BuyerAskNotice`. `settle` — после каждой правки покупателя и конца сделки: всё есть — дело гаснет.
 */
final class AskBuyer
{
    public function ask(Deal $deal, bool $notify = true, ?Carbon $now = null): bool
    {
        $deal->refresh();
        if ($deal->buyer_asked_at || ! $deal->buyer || $deal->buyerGap() === null) {
            return false;
        }
        $now ??= now();
        $deal->update(['buyer_asked_at' => $now]);
        if ($notify) {
            $id = $deal->id;
            DB::afterCommit(function () use ($id) {
                $deal = Deal::with(['buyer', 'offer'])->find($id);
                // Реквизиты уже прислал фотографией — ход наш, просить нечего (13.11.2026).
                if ($deal?->buyer_asked_at && $deal->buyer && ! $deal->buyer_scans_at) {
                    $deal->buyer->notify(new BuyerAskNotice($deal));
                }
            });
        }

        return true;
    }

    /**
     * Сверка (11.11.2026, владелец: у Макарова Volvo XC60 после «Беру в работу» — «Ждём поставщика», дела нет): отметку
     * ставил только живой переход «Беру в работу», а идущие и согласованные иначе (`AgreeDeal`, параллельные) оставались без
     * неё. Все согласованные, где покупатель нужен и его нет, получают дело молча — уведомление только у живого согласия.
     * Не на шаге «Нет реквизитов» (там покупатель — сама просьба шага), не на оплате и не на письме с реквизитами.
     *
     * @return Collection<int, Deal> те, кому дело поставили бы (`$apply = false`) или поставили
     */
    public function sweep(bool $apply = true): Collection
    {
        $deals = Deal::with(['buyer', 'offer', 'contract.buyer.party'])->where('state', DealState::Active)->whereNotNull('agreed_at')
            ->whereNull('buyer_asked_at')->whereNotNull('buyer_id')->get()->filter(function (Deal $d) {
                $stage = $d->offer?->stage();

                return $stage && $stage->asks !== Asks::Buyer && ! $stage->isPayStep() && ! $stage->isRequisitesStep() && $d->buyerGap() !== null;
            })->values();
        if ($apply) {
            $deals->each(fn (Deal $d) => $this->ask($d, notify: false));
        }

        return $deals;
    }

    /**
     * Реквизиты с фото внесли, а покупателю не хватает того, чего на фото не было (13.11.2026: ИП прислал карточку, паспорта
     * нет): фото больше не у нас, дело менеджера — словами недостающего (`Deal::buyerNeed`: «Приложите паспорт ИП»), ему
     * важным уведомлением, чтобы не искал, что «заполнить».
     */
    public function need(Deal $deal): void
    {
        $deal->forceFill(['buyer_scans_at' => null, 'buyer_asked_at' => $deal->buyer_asked_at ?? now()])->save();
        $id = $deal->id;
        DB::afterCommit(function () use ($id) {
            $deal = Deal::with(['buyer', 'offer', 'contract.buyer.party'])->find($id);
            if ($deal?->buyer && ($need = $deal->buyerNeed())) {
                $deal->buyer->notify(new BuyerAskNotice($deal, $need['title']));
            }
        });
    }

    public static function settle(Deal $deal): void
    {
        if ($deal->buyer_asked_at && (! $deal->isActive() || $deal->buyerGap() === null)) {
            $deal->forceFill(['buyer_asked_at' => null])->saveQuietly();
        }
    }
}
