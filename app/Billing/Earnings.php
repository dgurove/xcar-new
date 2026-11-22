<?php

namespace App\Billing;

use App\Users\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * «Заработал» менеджера по месяцам (22.11.2026, начальник: «чтобы менеджеры видели, сколько заработали»; владелец: по месяцу
 * полной оплаты). Заработок сделки — её вознаграждение (`deals.commission`: его получает он сам, не от нас); месяц — когда
 * оплачен последний из наших счетов сделки (все неаннулированные выставленные оплачены и положенные выставлены). Сделки без наших счетов
 * («Напрямую») и отменённые сюда не идут. Накопленной суммы нет — только месяц (сумма за год расслабляет).
 */
final class Earnings
{
    /** Месяцы с заработком, свежие первыми: `Y-m` => сумма. */
    public static function months(User $manager, int $limit = 12): Collection
    {
        return DB::table(self::deals($manager), 'e')->selectRaw("to_char(paid_at, 'YYYY-MM') as month, sum(commission) as sum")
            ->groupBy('month')->orderByDesc('month')->limit($limit)->pluck('sum', 'month')->map(fn ($v) => round((float) $v, 2));
    }

    /** Заработок за месяц. */
    public static function of(User $manager, Carbon $month): float
    {
        return round((float) DB::table(self::deals($manager), 'e')
            ->whereBetween('paid_at', [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()])->sum('commission'), 2);
    }

    /** Сделки менеджера, оплаченные целиком: вознаграждение и день последней оплаты. */
    private static function deals(User $manager)
    {
        $invoices = DB::table('billing_invoices')->whereNotNull('deal_id')->where('direction', 'issued')->where('state', '!=', InvoiceState::Void->value)
            ->groupBy('deal_id')->havingRaw("bool_and(state = 'paid')")->selectRaw('deal_id, max(paid_at) as paid_at');

        return DB::table('deals')->joinSub($invoices, 'pi', 'pi.deal_id', '=', 'deals.id')
            ->where('deals.buyer_id', $manager->id)->where('deals.parallel', false)->where('deals.state', '!=', 'cancelled')
            ->where('deals.commission', '>', 0)->whereNotNull('pi.paid_at')
            // Оплачены все, что уже есть, — мало: сделке ПРАЙМ / Р.О.С. и гаражной «платит менеджер» положен счёт за ТС, гаражной
            // «платит менеджер» — ещё и доля (`MoneyBook::expects`). Пока его нет, сделка не оплачена целиком: иначе вознаграждение
            // попало бы в месяц подбора, а с выставленным ДКП ушло бы из него задним числом.
            ->where(fn ($q) => $q->whereRaw("deals.scheme is distinct from 'prime' and deals.scheme is distinct from 'ros' and deals.garage_payer is distinct from 'manager'")
                ->orWhereRaw(MoneyBook::hasTabInvoice('dkp')))
            ->where(fn ($q) => $q->whereRaw("deals.garage_payer is distinct from 'manager'")->orWhereRaw(MoneyBook::hasTabInvoice('fees')))
            ->select('deals.id', 'deals.commission', 'pi.paid_at');
    }
}
