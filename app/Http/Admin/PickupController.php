<?php

namespace App\Http\Admin;

use App\Http\Logistics\TransportController;
use App\Live\Rows;
use App\Logistics\PickupLetters;
use App\Logistics\Transport;
use App\Logistics\TransportState;
use App\Offers\CarWindow;
use App\Offers\OfferNumber;
use App\Offers\OfferState;
use App\Support\Detail;
use App\Support\Table\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * «Вывоз» — раздел колеса «Предложений» (24.11.2026, владелец: «очень не хватает задач по вывозу, я тупо не могу найти такие
 * строки, а у нас куча тачек от Совкома, которые надо решить, кому на вывоз передать»; ведут Катя, Лиза и Алина). Поперёк
 * этапов, как «Оплата за подбор»: машина остаётся и в своей вкладке черновика или сделки. Строка — вывоз продажи строкой
 * логистики (`x-logistics.row`), нажатие — окно машины, где дорожка «Вывоз» с «Кто вывозит», письмами и «Вывоз не нужен».
 * «Менеджер забирает сам» сюда не входит — это «Менеджер забирает ТС» сделок; кроме «Показать контакты» — там как раз он, пока
 * телефона владельца не видел.
 */
class PickupController
{
    /** Вкладка → вкладка логистики, чьи столбцы у строки (`TransportController::columns`). */
    public const TABS = [
        // «Показать контакты» (25.11.2026, владелец по BMW X5 915891/2026: «самая первая перед „Назначить исполнителя“, ответственные
        // Катя, Лиза, Алина»): менеджер забирает сам, а телефона владельца не видел (`Offers\ContactDue`).
        'pickup_contacts' => 'work',
        'pickup_assign' => 'new',
        'pickup_docs' => 'work',
        'pickup_letters' => 'all',
        'pickup_moving' => 'work',
    ];

    /** Вкладки, где ход наш: красный счётчик — все строки. «Везём» — ждём исполнителя. */
    public const OURS = ['pickup_contacts', 'pickup_assign', 'pickup_docs', 'pickup_letters'];

    /** Текущие вывозы продажи у машин, что ещё продаются или в сделке (не архив). */
    private static function base(): Builder
    {
        return Transport::query()->where('transports.current', true)->where('transports.origin', 'sale')->where('transports.kind', 'pickup')
            ->where('transports.state', '!=', TransportState::Cancelled->value)
            ->whereExists(fn ($q) => $q->from('offers as po')->whereColumn('po.car_id', 'transports.car_id')
                ->whereNotIn('po.state', [OfferState::Archived->value, OfferState::Cancelled->value]));
    }

    /** Вывозы вкладки. */
    public static function query(string $tab): Builder
    {
        $q = self::base();

        return match ($tab) {
            'pickup_contacts' => $q->whereIn('transports.id', \App\Offers\ContactDue::transportIds()),
            // «Не решили, кто вывозит» (`undecided`) — решает менеджер сделки, это не наш ход.
            'pickup_assign' => $q->where('transports.state', TransportState::New)->where('transports.undecided', false),
            'pickup_docs' => $q->awaitingDocs(),
            'pickup_letters' => $q->whereIn('transports.id', PickupLetters::dueTransportIds()),
            'pickup_moving' => $q->whereIn('transports.state', [TransportState::Logist, TransportState::Assigned, TransportState::Agreed, TransportState::Moving])->inLogistics(),
        };
    }

    /** Машины вкладки — для числа колеса и раскладки (`OfferController::scopeFor`). @return list<int> */
    public static function carIds(string $tab): array
    {
        return self::query($tab)->distinct()->pluck('transports.car_id')->map(fn ($id) => (int) $id)->all();
    }

    /** Машины вкладки с горящим вывозом (`Responsibility::lateSql`). @return list<int> */
    public static function lateCarIds(string $tab): array
    {
        return self::query($tab)->whereRaw(\App\Offers\Responsibility::lateSql())->distinct()->pluck('transports.car_id')->map(fn ($id) => (int) $id)->all();
    }

    public function tab(Request $request, string $tab)
    {
        $detail = Detail::of($request, fn (string $key) => CarWindow::of($request, OfferNumber::find($key)));
        if ($detail->framed()) {
            return $detail->response();
        }
        $user = $request->user();
        $q = self::query($tab)->with(['region', 'car', ...\App\Logistics\SaleState::WITH, 'executor', 'logist', 'toYard', 'client', 'depot', 'fromDepot', 'routeLegs.depot', 'routeLegs.toYard']);
        $term = trim((string) $request->query('q'));
        if ($term !== '') {
            $q->search($term, crm: true);
        }
        // Дольше ждущие — выше.
        $rows = Rows::filter($q->orderBy('transports.created_at')->get(), fn (Transport $t) => $t->offer?->number);
        // Горящее — первыми подгруппами (26.11.2026, владелец: Кате во вывозе красным только просроченное и горящий срок
        // обязывающего): «Горит срок обязывающего», «Просрочено» (`Responsibility::lateSql`), дальше — как было.
        $hot = \App\Offers\Offer::whereIntegerInRaw('id', $rows->map(fn (Transport $t) => $t->offer?->id)->filter()->values()->all())
            ->whereRaw(\App\Offers\Responsibility::hotSql())->pluck('id')->flip();
        $late = Transport::whereIntegerInRaw('id', $rows->pluck('id')->all())->whereRaw(\App\Offers\Responsibility::lateSql())->pluck('id')->flip();
        $rest = $tab === 'pickup_moving' ? fn (Transport $t) => $t->state->label() : fn () => '';
        $groups = $rows->groupBy(fn (Transport $t) => match (true) {
            $t->offer && $hot->has($t->offer->id) => OfferController::HOT_GROUP,
            $late->has($t->id) => \App\Offers\Responsibility::LATE_GROUP,
            default => $rest($t),
        });
        $order = [OfferController::HOT_GROUP, \App\Offers\Responsibility::LATE_GROUP];
        $groups = $groups->sortKeysUsing(fn ($a, $b) => (array_search($a, $order, true) === false ? 9 : array_search($a, $order, true)) <=> (array_search($b, $order, true) === false ? 9 : array_search($b, $order, true)));
        // Есть горящее, а у вкладки своих групп нет — остальные строки «Остальные».
        if ($groups->has('') && $groups->count() > 1) {
            $groups = $groups->mapWithKeys(fn ($v, $k) => [$k === '' ? 'Остальные' : $k => $v]);
        }

        return view('admin.pickups.index', [
            'detail' => $detail,
            'tab' => $tab,
            'groups' => $groups,
            'total' => $rows->count(),
            'searching' => $term !== '',
            'table' => self::table($user, $tab),
            'presets' => OfferController::presets($user),
            'sections' => OfferController::sections($user),
            'counts' => OfferController::counts($user),
        ]);
    }

    /** Трек документов СДЭКом из окна машины CRM (26.11.2026) — тот же `Docs::send`, что на хосте логистики. */
    public function docs(Request $request, Transport $transport, \App\Logistics\Actions\Docs $docs)
    {
        abort_unless($request->user()->sendsLogisticsDocs(), 403);
        $track = (string) $request->validate(['docs_track' => ['required', 'string', 'max:60']], ['docs_track.required' => 'Трек-номер СДЭК'])['docs_track'];
        $docs->send($transport, $track, $request->user());

        return back()->with('toast', 'Документы отправлены');
    }

    /** «Документы получены» за исполнителя — ведущим логистику (`Docs::receive`). */
    public function docsReceived(Request $request, Transport $transport, \App\Logistics\Actions\Docs $docs)
    {
        abort_unless($request->user()->leadsLogistics(), 403);
        $docs->receive($transport, $request->user());

        return back()->with('toast', 'Документы получены');
    }

    /** Столбцы — как у логистики на её вкладке, без галочек пачки (назначают в окне машины). */
    public static function table(\App\Users\User $user, string $tab): Table
    {
        return TransportController::table($user, self::TABS[$tab], pick: false);
    }
}
