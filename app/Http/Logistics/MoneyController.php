<?php

namespace App\Http\Logistics;

use App\Billing\Actions\SaveRequisites;
use App\Billing\ChargeKind;
use App\Billing\Invoice;
use App\Billing\InvoiceState;
use App\Billing\Party;
use App\Billing\PartyRules;
use App\Logistics\Transport;
use App\Users\Role;
use App\Users\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * «Оплаты» логистики (19.01.2027; владелец 17.10.2026: «платим только Татьяне»): логисту — его реквизиты и долг по
 * вывозам (счёт `owed` вида `Tow`, ставит `SettleCost`), «Ждут выплаты» и «Выплачено»; админу — то же по каждому логисту.
 * Выплачивают в CRM «Оплаты → Мы должны», здесь только видно.
 */
class MoneyController
{
    public function index(Request $request)
    {
        $me = $request->user();
        $logists = $me->isAdmin() ? User::withRole(Role::Logist)->with('party')->orderBy('name')->get() : collect([$me]);

        return view('logistics.money.index', [
            'me' => $me,
            'rows' => $logists->map(fn (User $u) => (object) ['user' => $u, 'party' => Party::forUser($u, false), ...self::invoices($u)]),
        ]);
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
    private static function invoices(User $u): array
    {
        $party = Party::forUser($u, false);
        $all = $party->exists
            ? Invoice::where('party_id', $party->id)->where('direction', 'owed')->where('kind', ChargeKind::Tow->value)
                ->where('state', '!=', InvoiceState::Void->value)->with('charges')->latest('id')->get()
            : collect();

        return [
            'waiting' => $all->where('state', InvoiceState::Issued)->sortBy(fn (Invoice $i) => $i->due_at?->timestamp ?? PHP_INT_MAX)->values(),
            'paid' => $all->where('state', InvoiceState::Paid)->values(),
            'tasks' => Transport::whereIn('invoice_id', $all->pluck('id'))->with(['car', 'offer', 'vehicle'])->get()->keyBy('invoice_id'),
        ];
    }
}
