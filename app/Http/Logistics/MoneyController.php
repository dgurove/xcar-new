<?php

namespace App\Http\Logistics;

use App\Billing\Actions\SaveRequisites;
use App\Billing\ChargeKind;
use App\Billing\Invoice;
use App\Billing\InvoiceState;
use App\Billing\Party;
use App\Billing\PartyRules;
use App\Logistics\Actions\MarkPaid;
use App\Logistics\Transport;
use App\Logistics\TransportState;
use App\Users\Role;
use App\Users\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * «Оплаты» логистики (19.01.2027; владелец 17.10.2026: «платим только Татьяне»): логисту — его реквизиты и долг по
 * вывозам (счёт `owed` вида `Tow`, ставит `SettleCost`), «Ждут выплаты» и «Выплачено»; админу — то же по каждому логисту.
 * Выплачивают в CRM «Оплаты → Мы должны», здесь только видно.
 *
 * Логисту ещё свои деньги (18.10.2026, владелец: «цена и кто должен»): пилюли «Заказчики» — кто из заказчиков не заплатил
 * за привезённое, «Исполнители» — кому из своих исполнителей не заплачено, «XCar» — прежнее. Отметки — `MarkPaid`, без счетов.
 */
class MoneyController
{
    public const PILLS = ['clients' => 'Заказчики', 'executors' => 'Исполнители', 'xcar' => 'XCar'];

    public function index(Request $request)
    {
        $me = $request->user();
        if ($me->isLogist() && ! $me->isAdmin()) {
            return $this->logist($request, $me);
        }
        $logists = $me->isAdmin() ? User::withRole(Role::Logist)->with('party')->orderBy('name')->get() : collect([$me]);

        return view('logistics.money.index', [
            'me' => $me,
            'rows' => $logists->map(fn (User $u) => (object) ['user' => $u, 'party' => $party = Party::forUser($u, false), ...self::invoices($party)]),
        ]);
    }

    /** Своё логиста: долги заказчиков и долг исполнителям — задачи, привезённые и без отметки оплаты. */
    private function logist(Request $request, User $me)
    {
        $base = fn () => Transport::where('logist_id', $me->id)->where('state', TransportState::Delivered)
            ->with(['car', 'client', 'executor'])->latest('delivered_at');
        $clients = $base()->where('origin', 'order')->whereNotNull('client_price')->whereNull('client_paid_at')->get()->groupBy('client_id');
        $executors = $base()->whereNotNull('executor_cost')->whereNull('executor_paid_at')->whereNotNull('executor_id')
            ->where('executor_id', '!=', $me->id)->get()->groupBy('executor_id');
        $party = Party::forUser($me, false);
        $xcar = self::invoices($party);
        $counts = ['clients' => $clients->flatten()->count(), 'executors' => $executors->flatten()->count(), 'xcar' => $xcar['waiting']->count()];
        $pill = array_key_exists((string) $request->query('preset'), self::PILLS) ? $request->query('preset')
            : (collect($counts)->filter()->keys()->first() ?? 'clients');

        return view('logistics.money.logist', [
            'me' => $me, 'pill' => $pill, 'counts' => $counts, 'clients' => $clients, 'executors' => $executors,
            'row' => (object) ['user' => $me, 'party' => $party, ...$xcar],
        ]);
    }

    /** «Все пришли» / «Выплачено всё» — пачкой по заказчику или исполнителю. */
    public function paid(Request $request, MarkPaid $mark)
    {
        $data = $request->validate(['side' => ['required', 'in:client,executor'], 'transports' => ['required', 'array', 'max:500'], 'transports.*' => ['integer']]);
        $n = $mark(Transport::whereKey($data['transports'])->get(), $data['side'], true, $request->user());

        return back()->with('toast', 'Отмечено '.$n);
    }

    /** Реквизиты логиста — сам себе или админ. */
    public function party(Request $request, User $user)
    {
        abort_unless($user->isLogist() && ($request->user()->isAdmin() || $request->user()->is($user)), 404);
        $data = $request->validate(PartyRules::rules());
        app(SaveRequisites::class)($user, $data);

        return back()->with('toast', 'Реквизиты сохранены');
    }

    /**
     * Счета логисту и их задачи: ждут выплаты — по сроку, выплаченные — свежие сверху.
     *
     * @return array{waiting: Collection, paid: Collection, tasks: Collection}
     */
    private static function invoices(Party $party): array
    {
        $all = $party->exists
            ? Invoice::where('party_id', $party->id)->where('direction', 'owed')->where('kind', ChargeKind::Tow->value)
                ->where('state', '!=', InvoiceState::Void->value)->with('charges')->latest('id')->get()
            : collect();

        return [
            'waiting' => $all->where('state', InvoiceState::Issued)->sortBy(fn (Invoice $i) => $i->due_at?->timestamp ?? PHP_INT_MAX)->values(),
            'paid' => $all->where('state', InvoiceState::Paid)->values(),
            'tasks' => Transport::whereIn('invoice_id', $all->pluck('id'))->with(['car', 'offer.settlement', 'vehicle'])->get()->keyBy('invoice_id'),
        ];
    }
}
