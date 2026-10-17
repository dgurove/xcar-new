<?php

namespace App\Http\Logistics;

use App\Logistics\Actions\MarkPaid;
use App\Logistics\Actions\OpenOrder;
use App\Logistics\Choices;
use App\Logistics\Client;
use App\Logistics\Transport;
use App\Users\User;
use Illuminate\Http\Request;

/**
 * Заявки логиста от своих заказчиков (18.10.2026): «+» на «Вывозе» — форма страницей, «Изменить» и «Повторить» в карточке,
 * «Оплатил» / «Заплатила» — отметки денег. Только логист и только свои (`OpenOrder`, `MarkPaid` проверяют).
 */
class OrderController
{
    public function create(Request $request)
    {
        $me = $request->user();
        abort_unless($me->isLogist(), 404);
        // «Повторить»: тот же заказчик, адреса, контакты и цены — новая машина.
        $from = $request->query('from') ? Transport::visibleTo($me)->where('origin', 'order')->find($request->query('from')) : null;
        $client = $request->query('client') ? Client::of($me)->find($request->query('client')) : null;

        return $this->form($me, $from ? $this->copy($from) : new Transport(['client_id' => $client?->id, 'from_address' => $client?->address]), false);
    }

    public function store(Request $request, OpenOrder $open)
    {
        $t = $open($request->validate(OpenOrder::rules(), OpenOrder::messages()), $request->user());

        return redirect('/transports?preset=all&peek='.$t->id)->with('toast', 'Заявка создана');
    }

    public function edit(Request $request, Transport $transport)
    {
        $me = $request->user();
        abort_unless($me->isLogist() && $transport->isOrder() && $transport->isCoordinatedBy($me) && $transport->isOpen(), 404);

        return $this->form($me, $transport, true);
    }

    public function update(Request $request, Transport $transport, OpenOrder $open)
    {
        $open($request->validate(OpenOrder::rules(), OpenOrder::messages()), $request->user(), $transport);

        return redirect('/transports?preset=all&peek='.$transport->id)->with('toast', 'Сохранено');
    }

    /** «Оплатил» / «Заплатила» — одна задача; `all` — все долги заказчика или исполнителя разом (из «Оплат»). */
    public function paid(Request $request, Transport $transport, MarkPaid $mark)
    {
        $data = $request->validate(['side' => ['required', 'in:client,executor'], 'paid' => ['required', 'boolean']]);
        $mark($transport, $data['side'], (bool) $data['paid'], $request->user());

        return back()->with('toast', $data['paid'] ? ($data['side'] === 'client' ? 'Оплата от заказчика отмечена' : 'Выплата исполнителю отмечена') : 'Отметка снята');
    }

    private function form(User $me, Transport $t, bool $editing)
    {
        $t->loadMissing(['car', 'client']);

        return view('logistics.transports.form', [
            't' => $t, 'editing' => $editing,
            'executors' => Choices::contractorsOf($me),
        ]);
    }

    private function copy(Transport $from): Transport
    {
        return new Transport($from->only(['client_id', 'from_address', 'from_contact', 'from_phone', 'to_address', 'to_contact',
            'to_phone', 'client_price', 'executor_id', 'executor_cost', 'distance_km']));
    }
}
