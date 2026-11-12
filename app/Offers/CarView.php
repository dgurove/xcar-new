<?php

namespace App\Offers;

use App\Chats\Chat;
use App\Garage\Car as GarageCar;
use App\Garage\CarLine;
use App\Garage\CarState;
use App\Garage\GarageView;
use App\Offers\Actions\PublishDeal;
use App\Offers\Actions\ReturnToSale;
use App\Offers\Actions\SellAtAuction;
use App\Support\Money;
use App\Users\Role;
use App\Users\User;
use App\Workflow\Track;
use Illuminate\Support\Collection;

/**
 * Машина в CRM — одно решение на окно и страницу (11.11.2026, владелец: «единый модуль окна, чтобы изменение вносить в одном
 * месте»; было три окна — предложения, сделки, гаража — и редактор, каждый со своей выборкой, своими `@php` и своим меню).
 * Здесь: одна загрузка связей, этап машины, слово и число для шапки, что сейчас (плашки «Цены», «Приём», «что сейчас»),
 * дорожки «Продажа / Вывоз / третья», пункты меню действий, письма, чаты и документы. Окно (`admin/car/window`) и страница
 * (`admin/offers/edit`) берут одни и те же переменные (`vars`) и одни и те же куски; разница — только раскладка и то, что
 * окно короче (без «Истории», карточки «Чаты», «Фотографий»; поля ТС — до сделки).
 */
final class CarView
{
    public readonly bool $admin;

    /** Сделка машины: идущая, иначе последняя состоявшаяся (`lastDeal`) — у админа. */
    public readonly ?Deal $deal;

    /** @var ?array<string, mixed> гараж (`GarageView`), если машина в гараже */
    public readonly ?array $garageView;

    public readonly ?GarageCar $garageCar;

    /** @var Collection<int, Chat> все чаты по машине с последним сообщением — пилюля окна, карточка сделки, «Чаты» страницы */
    public readonly Collection $chats;

    /** @var array<string, mixed> письма (`OfferFiles::letters`): messages, asks, stages, letters, dealLetters */
    public readonly array $letters;

    private array $vars = [];

    /** Окно открыли со строки прошлой сделки (отменённой или не последней) — окно о ней, а не о нынешней (12.11.2026). */
    public readonly bool $pastDeal;

    private function __construct(public readonly Offer $offer, public readonly User $user, public readonly bool $page, ?Deal $deal = null)
    {
        $this->admin = $user->canManageCrm();
        $this->load();
        // Строка «Сделок» открывает окно своей сделки: «Закончены» — отменённая или прошлая, у машины уже другая (12.11.2026:
        // окно показывало нынешнее состояние машины без суммы, менеджера и договора той сделки).
        $this->pastDeal = $this->admin && $deal && ! $deal->isParallel() && $deal->id !== $offer->lastDeal?->id;
        $this->deal = $this->admin ? ($this->pastDeal ? $deal->setRelation('offer', $offer) : $offer->lastDeal) : null;
        $this->garageCar = $this->admin ? GarageCar::forOffer($offer) : null;
        $this->garageView = $this->garageCar ? GarageView::for($this->garageCar, $user) : null;
        $this->chats = $this->admin ? Chat::withLast()->withCount('messages')->where('offer_id', $offer->id)->orderByDesc('last_message_at')->get() : collect();
        // Письма — с почтой CRM (`canCrmMail`): одна проверка на окно и страницу (было три — в виде, в контроллере и никакой).
        $this->letters = $user->canCrmMail() ? OfferFiles::letters($offer, $user) : ['letters' => 0, 'dealLetters' => 0, 'messages' => collect(), 'asks' => collect(), 'stages' => collect()];
        $this->decide();
    }

    public static function for(Offer $offer, User $user, bool $page = false, ?Deal $deal = null): self
    {
        return new self($offer, $user, $page, $deal);
    }

    /** Одна выборка на окно и страницу: модератору — поля, кадры и документы, подтверждений, сделки и маршрута он не видит. */
    private function load(): void
    {
        // Окно открывают и подгружают заранее соседям открытой строки — то, что нужно только странице (история, заявки парковки,
        // сводка показов), грузит только страница (12.11.2026).
        $this->offer->load(['settlement', 'media', 'vendor', ...($this->page ? ['car.events.user.vendor', 'parkVehicle.requests'] : []), ...($this->admin ? [
            'bids.user', 'interests.user.manager', 'interests.placer', 'purchaseCar.purchase', 'purchaseCar.bids.user',
            'parkVehicle.yard', 'vendor.workflows', 'positions.stage.block', 'positions.stage.exits.to.block.stages',
            'positions.stage.workflow', 'deal.buyer', 'lastDeal.buyer', 'lastDeal.bid', 'lastDeal.requirements.media',
            'openEstimates.user', ...User::withAvatar('openEstimates.user.media'), 'declinedEstimates.user', ...User::withAvatar('declinedEstimates.user.media'),
        ] : [])])->loadCount(['activeBids', 'interests', 'activeBids as garage_bids_count' => fn ($b) => $b->where('kind', BidKind::Garage)])
            ->loadMax('activeBids as top_bid', 'amount');
    }

    /** Все решения `@php`, что раньше жили по копии в окне предложения и редакторе. */
    private function decide(): void
    {
        $o = $this->offer;
        $admin = $this->admin;
        $state = $o->state;
        $garageCar = $this->garageCar;
        $scheduled = $o->isScheduled();
        $auction = $o->onAuction();
        $gallery = $o->isGallery();

        // «Без подтверждений» (06.11.2026): цена, срок, кому и «Вернуть в продажу» / «В архив» — плашкой сверху.
        $unsold = $admin && ReturnToSale::unsold($o);
        // Блок «Оценить» — у черновика без цены продажи; оценённому цену правят в «Ценах».
        $rate = $admin && $state === OfferState::Draft && ! $o->asking_price && ! $scheduled;
        // Оценённый черновик, пока не в слоте, — корректировки менеджеров и «Спросить менеджеров» (11.10.2026).
        $correct = $admin && $state === OfferState::Draft && $o->asking_price && ! $scheduled && ! $auction;
        // Закупочной нет — сначала она (05.10.2026): у всех, и у модератора. У Альфы первой — оценочная.
        $floorFirst = $state === OfferState::Draft && ! $o->asking_price && ! $o->floor_price && ! $scheduled;
        // Рядом с «Оценить» — «В гараж» и «Вывоз» (формы на месте полей «Цен»).
        $garageWay = $rate && $state->allows(OfferState::Garage);
        $pickupWay = $rate && ($o->pickup()?->isOpen() || $o->vendor?->pickup_on_arrival) && ! $o->pickedUp();
        // Цены черновика — блоком по шагам сверху (19.10.2026); у остального — разделом «Цены» в форме.
        $steps = $state === OfferState::Draft && ! $scheduled && ! $auction && ! $unsold;
        // Слот, аукцион, «Без подтверждений», архив — плашка «что сейчас» (`now-block`) с кнопками; у гаража её нет.
        $nowKind = ! $admin || $garageCar ? null : match (true) {
            $scheduled => 'slot',
            $auction => 'auction',
            $unsold => 'unsold',
            in_array($state, [OfferState::Archived, OfferState::Cancelled], true) => 'archive',
            default => null,
        };
        // Открытое — блок «Приём» сверху (`intake-block`): срок и продление кнопками.
        $intake = $admin && ! $garageCar && $state === OfferState::Open && $o->bids_close_at;
        // «Выставить в продажу» у черновика и галереи; без цены продажи публиковать нечего.
        $publishItems = $admin && in_array($state, [OfferState::Draft, OfferState::Gallery], true) && ! $scheduled && $o->asking_price && ! $auction && ! $unsold;
        // Оценённый черновик — «Выставить в продажу» кнопкой в «Ценах» (19.10.2026), не пунктом меню.
        $publishButton = $steps && $publishItems;
        // «Отдать…» (`Give`, 18.10.2026): в сделку ценой или в гараж — из черновика, галереи и продажи. В окне у черновика без
        // цены вместо него «В гараж» прямо в «Ценах» (`price-steps`); на странице таких кнопок нет — там «Отдать…» остаётся
        // (12.11.2026: пропадал у черновика без цены на странице).
        $giveMenu = $admin && ! $garageCar && ($this->page || ! $garageWay) && in_array($state, [OfferState::Draft, OfferState::Gallery, OfferState::Open], true);
        // «Вернуть в продажу» с новыми ценами — из сделки и из гаража (`ReturnToSale`, 11.10.2026).
        $resale = $admin && (in_array($state, [OfferState::Sold, OfferState::Garage], true) || $garageCar);
        // Кнопки состояния — только уместные (`stateActions`). Машину из гаража уводит только «Отдали по ошибке» в карточке
        // гаража; «Выставить в продажу» — слотами; у сделки «в черновики» — внутри шторки «Отменить сделку».
        $transitions = $admin && ! $garageCar ? collect($o->stateActions())->mapWithKeys(fn ($label, $s) => [$s => [OfferState::from($s), $label]]) : collect();
        if ($state !== OfferState::Sold) {
            $transitions = $transitions->except(OfferState::Open->value);
        }
        if ($resale && $state === OfferState::Sold) {
            $transitions = $transitions->except(OfferState::Draft->value);
        }
        $bids = $admin ? $o->bids->sortBy([fn ($a, $b) => ($a->state === BidState::Active ? 0 : 1) <=> ($b->state === BidState::Active ? 0 : 1), ['amount', 'desc']]) : collect();

        $this->vars = [
            'offer' => $o,
            'n' => $o->number,
            'admin' => $admin,
            'car' => $this,
            'deal' => $this->deal,
            'garageView' => $this->garageView,
            'garageCar' => $garageCar,
            'gallery' => $gallery,
            'bids' => $bids,
            'waiting' => $bids->where('state', BidState::Active),
            'transitions' => $transitions,
            'unsold' => $unsold,
            'rate' => $rate,
            'correct' => $correct,
            'floorFirst' => $floorFirst,
            'byValue' => $floorFirst && Pricing::valued($o),
            // «В гараж» и «Вывоз» на месте полей «Цен» черновика без цены (`price-steps`) — не машина гаража (`garageCar`).
            'garageWay' => $garageWay,
            'pickupWay' => $pickupWay,
            'ways' => $garageWay || $pickupWay,
            'way' => session('errors')?->hasAny(['executor', 'executor_id', 'pickup']) ? 'pickup' : (old('to') === 'garage' ? 'garage' : 'rate'),
            'steps' => $steps,
            'nowKind' => $nowKind,
            'intake' => $intake,
            'publishItems' => $publishItems,
            'publishButton' => $publishButton,
            'giveMenu' => $giveMenu,
            'resale' => $resale,
            'purge' => $admin && in_array($state, [OfferState::Archived, OfferState::Cancelled], true),
            'askMenu' => ($correct || $rate) && $o->openEstimates->isEmpty() && $o->declinedEstimates->isEmpty(),
            'chats' => $this->chats,
            'tags' => $admin ? Tag::orderBy('sort')->get() : collect(),
            // Кому отдать на оценку — у черновика; «+ Интерес», «За менеджера» берут `People::managers`.
            'managers' => $admin && $state === OfferState::Draft && ! $scheduled ? User::withRole(Role::Manager)->with(User::withAvatar())->orderBy('name')->get() : collect(),
            'showingSummary' => $admin && $this->page ? Showing::summary($o) : collect(),
        ] + $this->letters;
    }

    /** @return array<string, mixed> переменные вида и его кусков (`price-steps`, `now-block`, `intake-block`, `lanes`, `bids`…) */
    public function vars(): array
    {
        return $this->vars;
    }

    /** Машина ещё продаётся (до сделки и гаража): поля ТС и «Цены» — в окне, после — только на странице. */
    public function selling(): bool
    {
        return ! $this->garageCar && ! in_array($this->offer->state, [OfferState::Sold, OfferState::Delivered, OfferState::Garage], true);
    }

    /**
     * Слово этапа — посередине полосы окна (вариант D3, 09.11.2026, как на парковке): после продажи — этап сделки с её
     * сроком (`DealLine`, как в строке таблицы), иначе вкладка (`Offer::crmTab`).
     *
     * @return array{0: string, 1: string} слово и класс тона
     */
    public function status(): array
    {
        if ($this->pastDeal) {
            $line = DealLine::of($this->deal);

            return [$line->word, $line->tone];
        }
        $line = $this->admin && in_array($this->offer->state, [OfferState::Sold, OfferState::Delivered, OfferState::Garage, OfferState::Cancelled], true)
            ? DealLine::forOffer($this->offer) : null;

        return $line ? [$line->word, $line->tone] : [$this->offer->crmTab(), $this->offer->state->textTone() ?: 'text-ink-muted'];
    }

    /**
     * Главное число справа от названия: у сделки — её цена (гаражная — «В гараж»), у гаража — `CarLine` с подписью; у
     * предложения числа нет — цены в «Ценах».
     *
     * @return ?array{0: string, 1: ?string}
     */
    public function aside(): ?array
    {
        if ($this->garageCar) {
            $line = CarLine::for($this->garageCar, $this->user, crm: true);

            return $line->caption ? [Money::rub($line->value), $line->caption] : null;
        }
        $deal = $this->deal;
        if ($deal && $this->showsDeal()) {
            return [$deal->isGarage() ? 'В гараж' : Money::rub($deal->amount), null];
        }

        return null;
    }

    /**
     * Кто держит машину — одной строкой сведений шапки окна (12.11.2026, владелец: «менеджер несколько раз написан»): менеджер
     * гаража с тем, кто платит поставщику, менеджер сделки, покупатель аукциона. Ниже в окне имя не повторяется — шаг пути
     * говорит «Менеджер», вывоз — «забирает сам», ссылка — пилюлей.
     *
     * @return ?array{user: ?User, name: string, role: string}
     */
    public function holder(): ?array
    {
        if ($this->garageCar) {
            return ['user' => $this->garageCar->manager, 'name' => $this->garageCar->manager?->shortName() ?? 'Взяли под себя',
                'role' => implode(', ', array_filter(['гараж', $this->garageCar->deal?->garagePayerLine()]))];
        }
        $deal = $this->showsDeal() ? $this->deal : null;
        if ($deal?->isAuction()) {
            return $deal->auctionBuyer ? ['user' => null, 'name' => $deal->auctionBuyer->name, 'role' => 'покупатель на аукционе'] : null;
        }

        return $deal?->buyer ? ['user' => $deal->buyer, 'name' => $deal->buyer->shortName(), 'role' => 'менеджер сделки'] : null;
    }

    /**
     * Что «Деньги» окна не повторяют (12.11.2026, владелец: «закупочная несколько раз»): число шапки (цена сделки или
     * закупочная гаража до продажи) и кто платит поставщику — он в сведениях под менеджером.
     *
     * @return list<string> ключи строк `x-deal.money`: amount, cost, payer
     */
    public function moneySkip(): array
    {
        if ($this->garageCar) {
            return $this->garageCar->isSold() ? ['payer'] : ['payer', 'cost'];
        }

        return $this->deal && $this->showsDeal() && ! $this->deal->isGarage() ? ['amount'] : [];
    }

    /** Окно о сделке: машина в сделке или выдана, или открыли со строки прошлой сделки — у шапки её цена и менеджер. */
    public function showsDeal(): bool
    {
        return $this->pastDeal || ($this->deal && in_array($this->offer->state, [OfferState::Sold, OfferState::Delivered], true));
    }

    /**
     * Дорожки (06.10.2026, «как три параллельных маршрута»): «Продажа» — маршрут со страховой, у гаражной дальше её продажа;
     * «Вывоз» — задача логистики (у идущей сделки без вывоза — получение); третья — «Гараж», «Аукцион», «Сделка»,
     * «Подтверждения» или «Интерес».
     *
     * @return array{sale: bool, pickup: bool, third: ?string}
     */
    public function lanes(): array
    {
        $o = $this->offer;
        $deal = $this->deal;
        $third = match (true) {
            (bool) $this->garageView => 'garage',
            (bool) $deal?->isAuction() => 'auction',
            (bool) $deal => 'deal',
            // Машина из закупки — цены менеджеров в закупке стоят в «Подтверждениях» и у черновика (29.10.2026).
            $this->vars['bids']->isNotEmpty() || $o->state === OfferState::Open || ($this->admin && $o->purchaseCar?->hasPrices()) => 'bids',
            // Черновик в галерее: интерес и «+ Интерес» за менеджера (13.10.2026).
            $o->isGallery() || $o->interests->isNotEmpty() => 'interest',
            default => null,
        };
        // Вывоз — идёт или был, или его заводят «Вывезти» — у гаражной, у сделки и у вендора, чьи машины вывозим всегда.
        $pickup = $o->pickup() || (! in_array($o->state, [OfferState::Delivered, OfferState::Cancelled, OfferState::Archived], true)
            && ($this->garageView || $deal || $o->vendor?->pickup_on_arrival));

        return [
            'sale' => ($o->position(Track::Sale) && ! $deal?->isAuction()) || (bool) $this->garageView,
            'pickup' => $pickup,
            'third' => $third,
        ];
    }

    /**
     * «⋯» дорожки «Гараж» окна (12.11.2026): продажа и подготовка, без «Вернуть в продажу» (оно в «⋯» окна), и «Добавить
     * расход», пока расходов нет (раздела тогда нет). Одно решение для заголовка «Гаража» и «⋯» окна.
     *
     * @return list<array>
     */
    public function garageMenu(): array
    {
        if (! $g = $this->garageView) {
            return [];
        }
        $car = $this->garageCar;
        $menu = array_values(array_filter($car->isSold() && ! $car->settledByDeal() ? $g['morePrep'] : [...$g['morePrep'], ...$g['moreSale']], fn ($m) => ($m[1] ?? null) !== 'resale'));
        if ($g['staff'] && $g['canAdd'] && $car->costs->isEmpty()) {
            $menu[] = ['emit', 'cost-new', 'Добавить расход'];
        }

        return $menu;
    }

    /**
     * Окну в «Гараже» показать нечего (12.11.2026, владелец: «много мусора»): подготовка пройдена или машину ещё везут, расходов
     * нет, денег до продажи нет (число шапки), чата с другим менеджером нет — раздела нет, его действия — в «⋯» окна.
     * Те же условия, что рисуют тело `admin.offers.garage-card` в окне.
     */
    public function garageQuiet(): bool
    {
        if (! $g = $this->garageView) {
            return false;
        }
        $car = $this->garageCar;
        $path = $car->state !== CarState::Waiting && in_array($car->state, CarState::prep(), true);
        $money = $g['money'] && ! $car->isSold() && ! $car->priceFor($g['staff']);
        $chat = $car->manager && $this->deal?->buyer_id !== $car->manager_id;

        return ! $path && ! $money && ! $chat && $car->costs->isEmpty();
    }

    /** Чат с менеджером сделки (тот, что карточкой и пилюлей «Чат») — из общей выборки, без своего запроса. */
    public function dealChat(): ?Chat
    {
        $deal = $this->deal;

        return $deal?->buyer ? $this->chats->first(fn (Chat $c) => $c->user_id === $deal->buyer_id && ! $c->manager_id && $c->messages_count > 0) : null;
    }

    /** @return Collection<int, Chat> остальные чаты с перепиской (пустой — заведён и брошен — не строка) */
    public function otherChats(): Collection
    {
        $deal = $this->deal;

        return $this->chats->reject(fn (Chat $c) => ! $c->messages_count || ($deal?->buyer && $c->user_id === $deal->buyer_id && ! $c->manager_id))->values();
    }

    /** Карточки «Продана на аукционе» — шторка продажи на аукционе нужна. */
    public function sellsAtAuction(): bool
    {
        return $this->admin && SellAtAuction::allows($this->offer, $this->user);
    }

    /**
     * Пункты меню действий — одно на окно («⋯» в полосе) и страницу («···» плашки действий): группами, как в iOS;
     * опасное — красным последним. Плашки «что сейчас» (слот, аукцион, архив, «Без подтверждений») и кнопки «Цен» свои
     * действия держат сами — второй раз в меню их нет.
     *
     * @return list<list<array<string, mixed>>> группы пунктов: kind post|emit|view
     */
    public function actions(bool $deletable = false): array
    {
        if (! $this->admin && ! $deletable) {
            return [];
        }
        $o = $this->offer;
        $n = $o->number;
        $v = $this->vars;
        $state = $o->state;
        $nowKind = $v['nowKind'];
        $groups = [];
        $emit = fn (string $event, string $label, string $icon, bool $danger = false) => ['kind' => 'emit', 'event' => $event, 'label' => $label, 'icon' => $icon, 'danger' => $danger];
        $post = fn (string $action, string $label, string $icon, array $fields = [], bool $danger = false, ?string $confirm = null, ?string $text = null, bool $guard = false, ?string $method = null, ?string $confirmLabel = null) => compact('action', 'label', 'icon', 'fields', 'danger', 'confirm', 'text', 'guard', 'method') + ['kind' => 'post', 'confirmLabel' => $confirmLabel];

        if ($this->admin) {
            if ($v['publishItems'] && ! $v['publishButton']) {
                $groups[] = [['kind' => 'view', 'view' => 'admin.car.publish-items'],
                    $emit('publish-day', 'Другой день…', 'calendar'), $emit('urgent', 'Срочно…', 'fire') + ['fire' => true]];
            }
            if ($v['giveMenu'] && ! $v['publishButton']) {
                $groups[] = [$emit('give-'.$n, 'Отдать…', 'deal')];
            }
            // Машина в сделке (и гаражной, пока не привезли) — показать остальным менеджерам поверх неё (12.11.2026, владелец:
            // «она в сделке у этого менеджера, но начальник хочет опубликовать её для других»; `PublishDeal`): идёт — та же
            // шторка правит кому и срок, рядом «Снять публикацию».
            if (PublishDeal::allows($o)) {
                $groups[] = $o->showsDealAsOpen()
                    ? [$emit('deal-publish', 'Публикация для других…', 'flag'),
                        $post('/offers/'.$n.'/deal-publication', 'Снять публикацию', 'eye-off', [], false, PublishDeal::STOP_CONFIRM, PublishDeal::STOP_TEXT, method: 'delete', confirmLabel: 'Снять')]
                    : [$emit('deal-publish', 'Опубликовать для других менеджеров…', 'flag')];
            }
            if (($v['publishItems'] && Exclusive::due($o)->isNotEmpty()) || ($state === OfferState::Open && Exclusive::running($o)->isNotEmpty())) {
                $groups[] = [['kind' => 'view', 'view' => 'admin.car.exclusive-off']];
            }
            // «Гараж» окну показать нечего — раздела нет, его действия здесь (12.11.2026).
            if (! $this->page && $this->garageQuiet() && ($garage = $this->garageMenu())) {
                $groups[] = array_map(fn ($m) => $m[0] === 'emit'
                    ? $emit($m[1], $m[2], $m[1] === 'cost-new' ? 'plus' : 'edit')
                    : $post($m[2], $m[3], 'edit', [], $m[1] === 'DELETE', ($m[4] ?? null) ?: null, method: in_array($m[1], ['POST', 'post'], true) ? null : strtolower($m[1])), $garage);
            }
            // Пустая заметка в окне не рисуется — пунктом «⋯»: нажатие показывает её поле на месте (12.11.2026, владелец: «мусор»).
            if (($noteDeal = $this->garageCar?->deal ?? $this->deal) && blank($noteDeal->notes)) {
                $groups[] = [$emit('deal-note-'.$noteDeal->id, 'Заметка', 'edit')];
            }
            if ($v['askMenu']) {
                $groups[] = [$emit('estimate-ask-'.$n, $v['correct'] ? 'Спросить менеджеров' : 'Отдать на оценку', 'users')];
            }
            if ($o->auctionActions() !== [] && $nowKind !== 'auction') {
                $groups[] = [['kind' => 'view', 'view' => 'admin.car.auction-items']];
            }
            // Черновик из таблицы Кати — «Вернуть в черновики»: номер уходит из списка, машина — в свою рабочую вкладку.
            if ($state === OfferState::Draft && ($listed = LegacyClaim::listed($o))) {
                $groups[] = [$post('/offers/legacy/'.$listed->id, 'Вернуть в черновики', 'undo', method: 'delete')];
            }
            // Архив — «Вернуть в черновики» и «Удалить навсегда» кнопками плашки; «В архив» у «Без подтверждений» — тоже.
            $transitions = $nowKind === 'archive' ? collect() : $v['transitions'];
            $calm = $state === OfferState::Sold ? collect() : $transitions->reject(fn ($t) => $t[0] === OfferState::Archived || $t[0]->tone() === 'danger');
            $danger = $transitions->diffKeys($calm);
            if ($v['unsold']) {
                $danger = $danger->except(OfferState::Archived->value);
            }
            $icon = fn (OfferState $s) => match ($s) {
                OfferState::Gallery => 'grid', OfferState::Draft => $state === OfferState::Archived ? 'undo' : 'eye-off', OfferState::Archived => 'archive', default => 'flag',
            };
            if ($calm->isNotEmpty()) {
                $groups[] = $calm->map(fn ($t) => $post('/offers/'.$n.'/state', $t[1], $icon($t[0]), ['state' => $t[0]->value], guard: true))->values()->all();
            }
            $red = [];
            if ($v['resale'] && $state === OfferState::Sold && $o->deal?->isActive()) {
                // Поставщик отказал продавать (28.10.2026): сделка закрыта по причине, предложение в архиве.
                $red[] = $emit('declined', 'Поставщик отказал…', 'x', true);
            }
            if ($v['resale']) {
                // У сделки — одна «Отменить сделку» шторкой: в продажу или в черновики (12.10.2026); гараж — «Вернуть в продажу».
                $red[] = $emit('resale', $state === OfferState::Sold && ! $this->garageCar ? 'Отменить сделку' : 'Вернуть в продажу', 'undo', true);
            }
            foreach ($danger as [$next, $label]) {
                $red[] = $state === OfferState::Sold
                    ? $post('/offers/'.$n.'/state', $label, $icon($next), ['state' => $next->value], true, 'Отменить сделку?', 'Подтверждение менеджера отклонится, неоплаченные счета сделки аннулируются', true)
                    : $post('/offers/'.$n.'/state', $label, $icon($next), ['state' => $next->value], true, $next->label().'?',
                        $o->parkVehicle && in_array($next, [OfferState::Archived, OfferState::Cancelled], true) ? 'ТС снимется с продажи, на парковке она остаётся с фото и документами' : null, true);
            }
            // Из архива — навсегда, со всем связанным (`PurgeOffer`).
            if ($v['purge'] && $nowKind !== 'archive') {
                $red[] = $post('/offers/'.$n.'/purge', 'Удалить навсегда', 'trash', [], true, 'Удалить навсегда?', 'Фото, документы, сделки, чаты и счета по нему удалятся без возврата', confirmLabel: 'Удалить') + ['top' => true];
            }
            if ($red) {
                $groups[] = $red;
            }
        }
        if ($deletable) {
            $groups[] = [$post('/offers/'.$n, 'Удалить черновик', 'trash', [], true, 'Удалить черновик?', 'Фото и документы удалятся вместе с ним', method: 'delete') + ['top' => true]];
        }

        return $groups;
    }
}
