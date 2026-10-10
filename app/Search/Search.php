<?php

namespace App\Search;

use App\Billing\Acquiring\PayLink;
use App\Billing\Invoice;
use App\Garage\Car as GarageCar;
use App\Http\Admin\MoneyController;
use App\Http\Admin\MoneyLinks;
use App\Mail\Account;
use App\Mail\Candidate;
use App\Mail\CandidateState;
use App\Mail\Scope;
use App\Mail\Thread;
use App\Offers\Deal;
use App\Offers\LegacyClaim;
use App\Offers\Offer;
use App\Park\Vehicle;
use App\Support\Money;
use App\Support\Surface;
use App\Users\User;
use App\Vendors\Vendor;
use Illuminate\Support\Collection;

/**
 * Поиск в шапке — одна выдача на три хоста (10.10.2026, владелец: «поиск должен быть прям охуенным», «чтобы находил, что
 * номер есть в таблице Кати и просто не заведён»). Группы: ТС — строка на машину, где бы она ни была (предложение, ТС
 * парковки, таблица Кати, цепочка «Из писем», сделка и гараж менеджера), дальше люди, деньги, вендоры, чаты, письма.
 * Что кому видно — те же двери, что у списков (`inCrm`, `siteList`, `Surface::opensFor`, область города парковки,
 * `canCrmMail`, `canManageCrm`); ищут те же скоупы, что лупы списков.
 */
final class Search
{
    public const CARS = 8;

    public const ROWS = 5;

    /** @return array<string, array{title: string, rows: list<Hit>, more?: array{label: string, href: string}}> */
    public static function for(User $user, Term $t, Surface $surface): array
    {
        if ($t->blank()) {
            return [];
        }

        return array_filter(match ($surface) {
            Surface::Crm => self::crm($user, $t),
            Surface::Park => self::park($user, $t),
            Surface::Site => self::site($user, $t),
        }, fn ($g) => $g['rows'] !== []);
    }

    private static function crm(User $user, Term $t): array
    {
        $admin = $user->canManageCrm();
        $cars = new Cars($t);
        $offers = Offer::inCrm($user)->whereNot(fn ($o) => $o->emptyDraft())->searchCrm($t)
            ->with(['brand', 'model', 'deal.buyer', 'garageCar.manager', 'parkVehicle.yard'])->latest('updated_at')->limit(12)->get()
            ->each(fn (Offer $o) => $cars->offer($o, link: "/offers/{$o->number}", crm: true));
        if (Surface::Park->opensFor($user)) {
            Vehicle::search($t)->with(['brand', 'model', 'yard'])->latest('updated_at')->limit(12)->get()
                ->each(fn (Vehicle $v) => $cars->vehicle($v, link: Surface::Park->url("/cars/{$v->id}"), external: true));
        }
        if ($admin && $t->claim) {
            $claims = LegacyClaim::where('claim_ref_key', 'like', Term::like($t->claim))->orderBy('position')->limit(8)->get();
            $owned = Offer::where('is_demo', false)->whereIn('claim_ref_key', $claims->pluck('claim_ref_key'))->get(['id', 'state', 'claim_ref_key'])->keyBy('claim_ref_key');
            $claims->each(fn (LegacyClaim $c) => $cars->legacy($c, $owned->get($c->claim_ref_key)));
        }
        if ($user->canCrmMail()) {
            self::candidates(Scope::Offers, $t)->each(fn (Candidate $c) => $cars->candidate($c, '/offers/from-mail?q='.urlencode($t->raw)));
        }

        return [
            'cars' => ['title' => 'ТС', 'rows' => $cars->top(self::CARS)] + self::more($offers->isNotEmpty(), 'Все предложения', '/?q='.urlencode($t->raw)),
            'people' => ['title' => 'Люди', 'rows' => $admin ? self::people(User::query(), $t, fn (User $u) => "/settings/users/{$u->id}") : []],
            'money' => ['title' => 'Деньги', 'rows' => $admin ? self::money(Invoice::crmMoney(), $t, crm: true) : []],
            'mail' => self::mail($user->canCrmMail() ? Scope::Offers : null, $t, '/work/mail'),
        ];
    }

    private static function park(User $user, Term $t): array
    {
        $cars = new Cars($t);
        $vehicles = Vehicle::search($t)->with(['brand', 'model', 'yard'])->latest('updated_at')->limit(12)->get()
            ->each(fn (Vehicle $v) => $cars->vehicle($v, link: "/cars/{$v->id}"));
        self::candidates(Scope::Park, $t)->each(fn (Candidate $c) => $cars->candidate($c, '/requests/from-mail?q='.urlencode($t->raw)));
        $manage = $user->canManagePark() && ! $user->isParkHelper();

        return [
            'cars' => ['title' => 'ТС', 'rows' => $cars->top(self::CARS)] + self::more($vehicles->isNotEmpty(), 'Все ТС', '/cars?q='.urlencode($t->raw)),
            'money' => ['title' => 'Деньги', 'rows' => $manage ? self::money(Invoice::ofPark(), $t, crm: false) : []],
            'vendors' => ['title' => 'Вендоры', 'rows' => Vendor::onPark()->search($t)->orderBy('name')->limit(self::ROWS)->get()
                ->map(fn (Vendor $v) => new Hit($v->name, "/vendors/{$v->id}", array_filter([$v->inn ? 'ИНН '.$v->inn : null])))->all()],
            'mail' => self::mail($manage ? Scope::Park : null, $t, '/mail'),
        ];
    }

    private static function site(User $user, Term $t): array
    {
        $cars = new Cars($t);
        // Свои сделки и гараж — выше витрины: это машины менеджера, о них и спрашивают.
        if ($user->hasDeals()) {
            Deal::of($user)->whereHas('offer', fn ($o) => $o->search($t))->with(['offer.brand', 'offer.model'])->latest('id')->limit(6)->get()
                ->each(fn (Deal $d) => $cars->siteDeal($d));
        }
        if ($user->canGarage()) {
            GarageCar::of($user)->whereHas('offer', fn ($o) => $o->search($t))->with(['offer.brand', 'offer.model'])->latest('id')->limit(6)->get()
                ->each(fn (GarageCar $c) => $cars->siteGarage($c));
        }
        $offers = Offer::query()->where(fn ($w) => $w->siteList($user)->orWhere(fn ($g) => $g->inGallery()))->search($t)
            ->with(['brand', 'model'])->latest('published_at')->limit(12)->get()
            ->each(fn (Offer $o) => $cars->offer($o, link: "/offers/{$o->number}", crm: false));

        return [
            'cars' => ['title' => 'ТС', 'rows' => $cars->top(self::CARS)] + self::more($offers->isNotEmpty(), 'Все предложения', '/offers?q='.urlencode($t->raw)),
            'people' => ['title' => 'Покупатели', 'rows' => $user->isManager() ? self::people($user->buyers(), $t, fn (User $u) => "/buyers/{$u->id}") : []],
        ];
    }

    /** Подсказка в поле поиска — что здесь ищут. */
    public static function hint(): string
    {
        return match (Surface::current()) {
            Surface::Crm => 'Номер, VIN, марка, имя',
            Surface::Park => 'Номер, VIN, госномер',
            Surface::Site => 'Марка, модель, номер',
        };
    }

    /** «Все ›» группы — только когда в её списке что-то есть: ссылка на пустой список ни к чему. */
    private static function more(bool $any, string $label, string $href): array
    {
        return $any ? ['more' => ['label' => $label, 'href' => $href]] : [];
    }

    /** Живые цепочки «Из писем» своей стороны по номеру убытка, VIN или госномеру. */
    private static function candidates(Scope $scope, Term $t): Collection
    {
        $keys = array_filter([$t->claim, $t->vin ? mb_strtolower($t->vin) : null]);
        if (! $keys) {
            return collect();
        }

        return Candidate::where('scope', $scope)->where('state', CandidateState::New)->whereNull('vehicle_id')->whereNull('offer_id')
            ->where(fn ($w) => collect($keys)->each(fn ($k) => $w->orWhere('key', 'ilike', Term::like($k))))
            ->latest('last_message_at')->limit(5)->get();
    }

    /** @return list<Hit> */
    private static function people($query, Term $t, \Closure $href): array
    {
        return $query->search($t)->orderBy('users.name')->limit(self::ROWS)->get()
            ->map(fn (User $u) => new Hit($u->name, $href($u), array_values(array_filter([$u->rolesLabel(), $u->phone ? $u->phoneFormatted() : null]))))->all();
    }

    /** @return list<Hit> */
    private static function money($query, Term $t, bool $crm): array
    {
        $invoices = $query->search($t)->with('party')->latest('id')->limit(self::ROWS)->get();
        $rows = $invoices->map(fn (Invoice $i) => new Hit(
            $i->number ? ($i->isOwed() ? 'Выплата' : 'Счёт').' № '.$i->number : ($i->isOwed() ? 'Выплата' : 'Счёт'),
            $crm ? MoneyController::url($i) : "/money?peek={$i->id}",
            array_values(array_filter([Money::rub($i->total), $i->party?->name, $i->state->label()])),
        ));
        if ($crm && $rows->count() < self::ROWS) {
            // Ручные ссылки без сделки: у них счёт «Услуга», его номер не ищут — ищут плательщика.
            MoneyLinks::query()->search($t, deep: false)->whereNotIn('billing_pay_links.invoice_id', $invoices->pluck('id'))
                ->latest('billing_pay_links.id')->limit(self::ROWS - $rows->count())->get()
                ->each(fn (PayLink $l) => $rows->push(new Hit('Ссылка на оплату', MoneyController::linkUrl($l),
                    array_values(array_filter([Money::rub($l->amount), $l->payerLabel()])))));
        }

        return $rows->all();
    }

    /** Письма — числом и тремя последними темами; «Все» — почта с тем же запросом. */
    private static function mail(?Scope $scope, Term $t, string $base): array
    {
        if (! $scope) {
            return ['title' => 'Письма', 'rows' => []];
        }
        // Полнотекст по письмам — самый тяжёлый запрос выдачи: один раз, четвёртая строка только говорит, что есть ещё.
        $threads = Thread::whereIn('account_id', Account::where('scope', $scope)->pluck('id'))->where('messages_count', '>', 0)->search($t)
            ->latest('last_message_at')->limit(4)->get();
        $rows = $threads->take(3)
            ->map(fn (Thread $th) => new Hit($th->subject ?: 'Без темы', $base.'?window='.$th->id, [$th->last_message_at?->translatedFormat('j M')]))->all();

        return ['title' => 'Письма', 'rows' => $rows] + self::more($threads->count() > 3, 'Все письма', $base.'?q='.urlencode($t->raw));
    }
}
