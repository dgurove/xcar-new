<?php

namespace App\Http\Admin;

use App\Live\Rows;
use App\Logistics\Transport;
use App\Offers\Binding;
use App\Offers\Offer;
use App\Offers\OfferNumber;
use App\Support\Detail;
use App\Support\Facets\Common;
use App\Support\Facets\Facets;
use App\Support\Surface;
use App\Support\Table\ColRole;
use App\Support\Table\Column;
use App\Support\Table\Table;
use Illuminate\Http\Request;

/**
 * Экран «Горит» (19.10.2026, начальник: «зашёл — сразу видишь, что горит, в чём дело, провалился — потушил»): машины с
 * горящим или недавно вышедшим сроком обязывающего (`Binding::alarmQuery`). Два блока — «Сгорели» и «Вот-вот сгорят»,
 * внутри группы по причине (`Binding::REASONS`), в строке — этап и на сколько просрочено или сколько осталось; чип
 * «Менеджер» — менеджер идущей сделки. В CRM строка открывает карточку предложения рядом (та же, что в списке), в логистике —
 * задачу вывоза. Капсула тревоги в шапке ведёт сюда. Кому — `Binding::alarmsFor` (ведущие логистику).
 */
final class AlarmController
{
    public function index(Request $request, OfferController $offers)
    {
        $user = $request->user();
        abort_unless(Binding::alarmsFor($user), 404);
        $crm = Surface::current() === Surface::Crm;
        // Карточка рядом — та же, что у списка предложений; потушили (покупатель, оплата) — строка уходит.
        $detail = Detail::of($request, function (string $key) use ($request, $offers, $user) {
            $offer = OfferNumber::find($key);
            if ($offer && ! Binding::alarmQuery($user)->whereKey($offer->id)->exists()) {
                session()->now('detail-gone', true);
            }

            return $offers->detailOf($request, $offer);
        });
        if ($crm && $detail->framed()) {
            return $detail->response();
        }
        $facets = Facets::for($request, 'alarms', Common::manager(Binding::MANAGER_SQL)->none('Без менеджера'));
        $q = Binding::alarmQuery($user)->with(['deal.buyer', 'positions.stage.block', 'vendor']);
        if (filled($request->query('q'))) {
            $q->searchCrm(trim((string) $request->query('q')));
        }
        $facets->apply($q);
        Rows::offers($q);
        $all = $q->orderBy('offers.binding_until')->limit(300)->get();
        $group = fn ($list) => $list->groupBy(fn (Offer $o) => Binding::reason($o))
            ->sortBy(fn ($rows, string $reason) => array_search($reason, array_keys(Binding::REASONS), true));
        $tasks = $crm ? collect() : Transport::open()->whereIn('car_id', $all->pluck('car_id')->filter())->pluck('id', 'car_id');

        return view('admin.alarms.index', [
            'over' => $group($all->filter(fn (Offer $o) => $o->binding_until->isPast())->sortByDesc('binding_until')),
            'burning' => $group($all->filter(fn (Offer $o) => $o->binding_until->isFuture())),
            'total' => $all->count(),
            'facets' => $facets,
            'detail' => $crm ? $detail : null,
            'crm' => $crm,
            // В логистике — задача вывоза машины, иначе предложение в CRM.
            'href' => fn (Offer $o) => isset($tasks[$o->car_id]) ? '/transports/'.$tasks[$o->car_id] : Surface::Crm->url('/offers/'.$o->number),
        ]);
    }

    /** Таблица «Горит» — одно описание на шапку, строки `x-alarm.row` и заголовки причин. */
    public static function table(): Table
    {
        return Table::of(
            new Column('title', 'Марка, модель', grow: true),
            new Column('step', 'Этап', from: 'sm', role: ColRole::State, rem: 12),
            new Column('manager', 'Менеджер', from: 'sm', role: ColRole::Who, rem: 9),
            new Column('vendor', 'Вендор, № убытка', from: 'sm', role: ColRole::Code, rem: 10),
            new Column('term', 'Срок', cell: 'nums', role: ColRole::When, rem: 8),
        );
    }
}
