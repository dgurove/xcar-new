<?php

namespace App\Http\Cabinet;

use App\Billing\Actions\ClaimPayment;
use App\Billing\Invoice;
use App\Billing\InvoiceState;
use App\Billing\Party;
use App\Billing\PartyKind;
use App\Billing\PaymentSource;
use App\Chats\AuthorKind;
use App\Chats\Message;
use App\Live\RefreshDeal;
use App\Live\Stream;
use App\Media\PhotosPdf;
use App\Logistics\Actions\RequestPickup;
use App\Logistics\Actions\SelfPickup;
use App\Offers\Actions\AttachSignedContract;
use App\Offers\Actions\PickSigningTime;
use App\Offers\Actions\SaveSigningWish;
use App\Offers\Actions\SetReceiver;
use App\Offers\Bid;
use App\Offers\BidState;
use App\Offers\Deal;
use App\Offers\DealContract;
use App\Offers\DealState;
use App\Offers\Disclosures;
use App\Offers\Handover;
use App\Offers\OfferState;
use App\Offers\Parallels;
use App\Offers\Signing;
use App\Offers\Turn;
use App\Support\Ago;
use App\Support\ListPrefs;
use App\Support\ListView;
use App\Support\Portion;
use App\Support\SafeBack;
use App\Support\Sort;
use App\Support\Table\Table;
use App\Support\Table\ColRole;
use App\Support\Table\Column;
use App\Users\User;
use App\Users\Wording;
use App\Workflow\Actions\AnswerRequirement;
use App\Workflow\Actions\AttachChatFiles;
use App\Workflow\Actions\AttachRequirementFiles;
use App\Workflow\Asks;
use App\Workflow\Outcome;
use App\Workflow\Path;
use App\Workflow\Requirement;
use App\Workflow\Track;
use App\Workflow\WaitsFor;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class DealController
{
    /** «В работе»: где ход менеджера — первыми (просроченное, потом по сроку), дальше дата или сумма — выбором. */
    public const SORTS = ['fresh' => ['Дата', 'desc'], 'amount' => ['Сумма', 'desc']];

    /** Архив — сколько строк каждого раздела показываем; сделки целиком ведёт CRM, тут — история менеджера. */
    private const ARCHIVE = 100;

    /**
     * Сделки — только сделки в работе (11.10.2026, владелец). Подтверждения — не сделки: «менеджеры подтверждают кучу
     * машин, а берём редко» — они в «Моих подтверждениях» (`?view=bids`), закончившееся и не состоявшееся — в архиве
     * (`?view=archive`); входы — строками в конце списка.
     */
    public function index(Request $request)
    {
        $me = $request->user();
        abort_unless($me->hasDeals() || $me->isAdmin(), 404);
        if ($request->query('view') === 'archive') {
            return $this->archive($request);
        }
        if ($request->query('view') === 'bids' && $me->hasDeals()) {
            return $this->bidsScreen($request);
        }
        ListPrefs::sync($request, 'deals', view: false, keep: ['sort']);
        $sort = Sort::from($request->query('sort'), self::SORTS, '-fresh');
        // Гаражные — тоже сделки (02.11.2026, владелец: «гаражные тоже в сделках»; до того жили только в «Гараже»): в тех же
        // разделах, с меткой «Гараж», строка ведёт в машину гаража — там и шаг сделки.
        // Ход менеджера — просьба к нему или этап, где ждут его (ПРАЙМ без покупателя — без просьбы): просроченное, потом остальное.
        $turn = Deal::MANAGER_TURN_SQL;
        // По умолчанию — таблицей, как в CRM (18.11.2026, владелец: «сделки и гараж — по умолчанию вид табличный»), строками —
        // пунктом меню вида. Страниц нет ни у кого (тот же день: «всегда бесконечная подгружающаяся лента»).
        $staff = $me->isAdmin() && ! $me->isManager();
        $view = $staff || ListView::fromRequest($request) !== ListView::LIST ? ListView::TABLE : ListView::LIST;
        // Параллельная (07.11.2026) — его сделка, как идущая: со своим теневым шагом (`Parallels::dress`).
        // Строке таблицы кадры не нужны.
        $deals = self::deals($me)->when($view === ListView::TABLE, fn ($d) => $d->without('offer.media'))->live()
            ->orderByRaw("case when {$turn} then 0 else 1 end")
            ->orderByRaw('(select min(r.due_at) from requirements r where r.deal_id = deals.id and r.done_at is null) asc nulls last')
            ->when($sort->key === 'amount', fn ($d) => $d->orderByRaw("coalesce(amount, cost) {$sort->dir()} nulls last"))->orderBy('created_at', $sort->key === 'fresh' ? $sort->dir() : 'desc')->orderByDesc('id');
        // Админу — все сделки площадки одной таблицей, порциями по сотне при листании (`Portion`; были страницы по 30).
        // Менеджеру — свои разделами «чей ход» целиком: раздел считается в памяти (`managerTask`, `waitsForManager`), и порция
        // соврала бы — выражение запроса `MANAGER_TURN_SQL` шире «Вашего хода» (гаражная без просьбы, оплаченные счета); списки
        // малы — до сотни строк у самого занятого.
        $deals = $staff ? Portion::of($request, $deals, (clone $deals)->count()) : $deals->get()->each(fn (Deal $d) => Parallels::dress($d->loadMissing('shadowStage')));
        // Задача менеджера — один раз на сделку: её берут и разделы, и строка (раньше строка считала заново).
        $tasks = $staff ? collect() : $deals->mapWithKeys(fn (Deal $d) => [$d->id => $d->managerTask()]);
        // Чей ход глазами менеджера (`Offers\Turn`, 18.11.2026) — тоже раз на сделку: им живут и разделы, и строка.
        $turns = $staff ? collect() : $deals->mapWithKeys(fn (Deal $d) => [$d->id => Turn::of($d, null, $tasks[$d->id], list: true)]);
        $sections = $staff ? null : self::sections($deals, $tasks, $turns);

        // Админ подтверждения решает в CRM — строки «Мои подтверждения» и «Ждут решения» у него нет.
        $bids = $me->hasDeals() ? self::waiting($me)->count() : 0;
        $pending = $me->hasDeals() ? self::pending($me)->get()->sortByDesc(fn (Bid $b) => $b->offer->bids_close_at?->getTimestamp())->values() : collect();
        $archived = self::deals($me)->whereNotNull('deals.closed_at')->notSuperseded()->count();

        return view('cabinet.deals.index', ['deals' => $deals, 'sections' => $sections, 'tasks' => $tasks, 'turns' => $turns, 'pending' => $pending, 'bids' => $bids, 'archived' => $archived,
            'sort' => $sort, 'view' => $view, 'table' => self::table($staff)]);
    }

    /**
     * Таблица сделок сайта — одно описание на шапку, строки (`x-deal.site-table-row`) и заголовки разделов (18.11.2026).
     * Слева название и что сейчас одним фактом (дело менеджера зелёным, чужой ход — кого ждём серым, просрочено — красным);
     * справа — чья (админу), сумма и срок числом. На телефоне — название с суммой концом строки, под ним факт со сроком.
     * Кнопки дела в строке нет (владелец, тот же день: «статуса достаточно — будут нажимать и внутри уже работать»).
     */
    public static function table(bool $staff): Table
    {
        return Table::of(
            new Column('title', 'Марка, модель', grow: true),
            new Column('state', 'Что сейчас', from: 'sm', role: ColRole::State, rem: 14),
            $staff ? new Column('manager', 'Менеджер', from: 'md', role: ColRole::Who, rem: 8) : null,
            new Column('amount', 'Сумма', from: 'sm', num: true, sort: 'amount', cell: 'nums', rem: 7),
            new Column('term', 'Срок', from: 'sm', cell: 'nums', role: ColRole::When, rem: 6),
        );
    }

    /**
     * Разделы «Сделок» менеджера (27.10.2026, владелец: свои дела терялись рыжей строкой среди «Ждём поставщика»): «Ваш ход» —
     * что сделать (`Deal::managerTask`), просроченное и ближний срок первыми; дальше — наш ход двумя словами (18.11.2026, владелец:
     * «им должно быть всё равно, что мы там внутри делаем»): «На согласовании» и «В обработке» (`Offers\Turn`) — без «Ждём
     * поставщика» и «Ждём XCAR». Гаражная, где ход его без просьбы, — продаёт из гаража: «В гараже». Пустые не рисуются.
     *
     * @return array<string, array{title: string, deals: Collection}>
     */
    private static function sections($deals, Collection $tasks, Collection $turns): array
    {
        [$mine, $rest] = $deals->partition(fn (Deal $d) => $tasks[$d->id] !== null);
        $mine = $mine->sortBy(fn (Deal $d) => $tasks[$d->id]['due']?->getTimestamp() ?? PHP_INT_MAX)->values();
        $who = fn (Deal $d) => match (true) {
            $d->isGarage() && $d->offer->position()?->waitsFor() === WaitsFor::Manager => 'garage',
            $turns[$d->id]?->title === Turn::AGREEING => 'agree',
            default => 'work',
        };
        $rest = $rest->groupBy($who);

        return array_filter([
            'mine' => ['title' => 'Ваш ход', 'deals' => $mine],
            'agree' => ['title' => Turn::AGREEING, 'deals' => $rest->get('agree', collect())],
            'work' => ['title' => Turn::WORKING, 'deals' => $rest->get('work', collect())],
            'garage' => ['title' => 'В гараже', 'deals' => $rest->get('garage', collect())],
        ], fn ($s) => $s['deals']->isNotEmpty());
    }

    /**
     * Мои подтверждения (11.10.2026, владелец): «Ждут решения» — всё живое. Отданное другому — тоже здесь, тем же видом
     * (15.10.2026, владелец: второй не должен знать, что машину отдали, он резерв на случай, если первый сорвётся).
     * Отклонённых и отданных другому, чья продажа состоялась, тут нет вовсе.
     */
    private function bidsScreen(Request $request)
    {
        return view('cabinet.deals.bids', ['waiting' => self::waiting($request->user())->get()]);
    }

    /** Архив: закончившиеся и отменённые сделки. Подтверждений здесь нет — они не сделки. */
    private function archive(Request $request)
    {
        $me = $request->user();
        $closed = self::deals($me)->whereNotNull('deals.closed_at')->notSuperseded()
            ->orderByRaw('coalesce(closed_at, updated_at) desc')->orderByDesc('id')->limit(self::ARCHIVE)->get();

        // Закрытая параллельная — без шага машины (`Parallels::dress`).
        $closed->each(fn (Deal $d) => Parallels::dress($d));

        return view('cabinet.deals.archive', ['done' => $closed->filter(fn (Deal $d) => $d->state === DealState::Done)->values(), 'failed' => $closed->filter(fn (Deal $d) => $d->state === DealState::Cancelled)->values()]);
    }

    /** Сделки экрана с тем, что нужно строке; гаражные тоже (02.11.2026) — машина гаража нужна ссылке строки. */
    private static function deals(User $me)
    {
        return Deal::with(['offer', 'offer.media', 'offer.positions.stage.block', 'offer.positions.stage.exits.to', 'openRequirement', 'garageCar.offer'])
            ->when(! $me->hasDeals(), fn ($d) => $d->with('buyer.media'))->of($me);
    }

    private static function bids(User $me)
    {
        return Bid::with(['offer', 'offer.media'])->where('user_id', $me->id)->latest();
    }

    /**
     * Ждут решения: предложение открыто без сделки — выбор впереди; или идёт чужая сделка — подтверждение в резерве, но
     * менеджеру это не говорится (15.10.2026).
     */
    private static function waiting(User $me)
    {
        return self::bids($me)->where('state', BidState::Active)->where(fn ($w) => $w
            ->whereHas('offer', fn ($o) => $o->where('state', OfferState::Open)->whereDoesntHave('deal'))
            ->orWhereHas('offer.deal', fn ($d) => $d->where(fn ($b) => $b->whereNull('buyer_id')->orWhere('buyer_id', '!=', $me->id))));
    }

    /**
     * «Ждут решения» в «Сделках» (02.11.2026, владелец: «из подтверждённых пропало», 33 машины у 14 менеджеров): срок приёма
     * вышел — машина уходит из «Предложений» (`Offer::onSale`) и у подтвердившего стоит здесь, пока выбор за нами; отдали
     * другому — тут же тем же видом (резерв, 15.10.2026). Пропадает при отказе, завершённой сделке и архиве — подтверждение
     * гаснет (`ChangeOfferState::settleDeal`). До срока машина в «Предложениях», а все живые — в «Моих подтверждениях».
     * Сделку, что ему показывают (белый список, показ всем), он видит обычным предложением в «Предложениях» — здесь её нет,
     * пока показ идёт (05.11.2026).
     */
    public static function pending(User $me)
    {
        return self::waiting($me)->whereHas('offer', fn ($o) => $o->where('bids_close_at', '<=', now())
            ->whereNot(fn ($n) => $n->dealOpenTo($me)));
    }

    public function show(Request $request, Deal $deal)
    {
        $me = $request->user();
        // Админ ведёт сделку в CRM: страница сделки на сайте — работа менеджера («Ваша доля», «Оплатить»).
        if ($me->isAdmin() && $deal->buyer_id !== $me->id) {
            return redirect()->away($deal->crmUrl());
        }
        abort_unless($deal->buyer_id === $me->id, 404);
        // Переоформили (отменили и приняли снова) — старая ссылка ленты и Telegram ведёт на новую, без «Сделка отменена».
        if ($next = $deal->successor()) {
            return redirect($next->href());
        }
        // Отменённая сделка машину не открывает (13.10.2026): кадры, VIN и документы — только пока она ему видна.
        if ($deal->state === DealState::Cancelled && ! $deal->offer->isVisibleTo($me)) {
            return redirect('/deals?view=archive')->with('toast', 'Сделка отменена');
        }
        // Гаражная сделка живёт в гараже: старые ссылки ленты и Telegram ведут туда же.
        if ($deal->isGarage() && $deal->garageCar()->exists()) {
            return redirect($deal->href());
        }

        return view('cabinet.deals.show', self::stepData($deal));
    }

    /** Карточка чата по сделке — фрейм перечитывает её по новому сообщению. */
    public function chat(Request $request, Deal $deal)
    {
        abort_unless($deal->offer->chatOpenFor($request->user()), 404);

        return view('cabinet.deals.chat-card', ['deal' => $deal, 'offer' => $deal->offer]);
    }

    /** Сделка с текущим шагом, путём и счетами — для её страницы и карточки машины в гараже. */
    public static function stepData(Deal $deal): array
    {
        $deal->load(['offer', 'offer.media', 'offer.settlement', 'offer.positions.stage.block', 'offer.positions.stage.workflow', 'offer.positions.stage.exits.to', 'openRequirement.media', 'requirements']);
        // Параллельная (07.11.2026): шаг и путь — её теневые, шагов машины она не видит.
        Parallels::dress($deal);
        $position = $deal->offer->position(Track::Sale);
        // Журнал с начала сделки, с учётом откатов — тот же, что путь в CRM.
        $steps = Path::journal($deal->offer, Track::Sale, $deal->created_at, ! $deal->isActive() && $deal->wasParallel() ? $deal : null);
        // Менеджеру путь целиком: на развилке — главной дорогой (`guess`), а не обрывом на текущем шаге.
        $blocks = $position ? Path::ladder($position->stage, $steps->pluck('block')->all(), $deal, guess: true) : collect();

        return [
            'deal' => $deal,
            'offer' => $deal->offer,
            'position' => $position,
            'blocks' => $blocks,
            'steps' => $steps,
            'requirement' => $deal->openRequirement,
            'exits' => $deal->openRequirement ? $deal->managerExits($position?->stage) : collect(),
            // Чего не хватает счёту ПРАЙМ на шаге оплаты (`Deal::invoiceGap`) — одно значение на страницу и задачу (11.11.2026:
            // страница и `cabinet.deals.step` считали его каждая сама).
            'payGap' => $deal->isLive() && $position?->stage->isPayStep() && $deal->hasContract() ? $deal->invoiceGap() : null,
            'handover' => Handover::for($deal),
            // Из писем страховой — только то, что показал админ (`Offers\Disclosure`, 03.11.2026, владелец: «менеджеру вообще
            // ничего не показываем, пока админ не нажмёт»), новое сверху. Самих писем и их телефонов здесь нет.
            'disclosures' => Disclosures::shown($deal),
            // Неотправленный менеджеру счёт (кнопка в CRM, 16.11.2026) — не его: «Счёт готовится».
            'invoices' => Invoice::where('deal_id', $deal->id)->where('direction', 'issued')->where('state', '!=', InvoiceState::Void)->shownToManager()
                // Шторка счёта (`cabinet.deals.invoice-sheet`): строки, оплаты с платёжками, ссылка — сразу, не по запросу на счёт.
                ->with(['claims', 'party', 'charges', 'allPayments.media', 'payLinks.attempts', 'media'])->orderBy('id')->get()
                ->each(fn (Invoice $i) => $i->setRelation('deal', $deal)),
        ];
    }

    /**
     * Доп. просьба поставщику (18.11.2026, `Offers\ExtraAsk`): ответ менеджера — «Беру в работу» / «Я передумал» (после согласия —
     * «Всё в порядке, берём» / «Не берём»). На «Согласии менеджера» это и ответ шага — второго вопроса нет.
     */
    public function extraAsk(Request $request, Deal $deal, \App\Offers\Actions\SettleExtraAsk $settle)
    {
        $data = $request->validate(['decision' => ['required', 'in:'.\App\Offers\ExtraAsk::TAKE.','.\App\Offers\ExtraAsk::DROP]]);
        $labels = \App\Offers\ExtraAsk::labels($deal);
        if (! \App\Offers\ExtraAsk::holding($deal)?->asks_manager) {
            return $this->back($request, $deal)->with('toast', 'Ответ уже не нужен');
        }
        $settle->decide($deal, $data['decision'], $request->user());
        if ($data['decision'] === \App\Offers\ExtraAsk::DROP) {
            return redirect($deal->isGarage() ? '/garage' : '/deals')->with('toast', 'Сделка отменена');
        }
        $deal->refresh();
        // Взял на согласии — сразу «Кто покупатель?», как у кнопки шага (`answer`): `AskBuyer` спросил только что.
        if ($deal->isActive() && $deal->buyer_asked_at?->gt(now()->subMinute())) {
            return redirect($deal->href())->with('toast', $labels['take'])->with('open_sheet', 'deal-buyer-ask-'.$deal->id);
        }

        return $this->back($request, $deal)->with('toast', $labels['take']);
    }

    public function answer(Request $request, Deal $deal, AnswerRequirement $answer)
    {
        $data = $request->validate(['exit' => ['required', 'integer'], 'requirement' => ['nullable', 'integer'], 'back' => ['nullable', 'string', 'max:500']]);
        // Кнопка не со страницы сделки (чат, полка, вопрос при входе — `x-deal.ask-action`) несёт свою просьбу: на неё уже
        // ответили (за него, сам шаг ушёл) — не отвечаем на новую чужой кнопкой.
        $requirement = $deal->openRequirement()->first();
        if (! $requirement || (isset($data['requirement']) && (int) $data['requirement'] !== $requirement->id)) {
            return $this->back($request, $deal)->with('toast', 'Ответ уже не нужен');
        }
        $exit = Outcome::findOrFail($data['exit']);
        $answer($requirement, $exit, $request->user(), (array) $request->input('fields', []));

        $deal->refresh();
        // «Беру в работу» — сразу окно «Кто покупатель?» на странице сделки, откуда бы ни нажали (10.11.2026, `AskBuyer`).
        if ($exit->takes() && $deal->buyer_asked_at) {
            return redirect($deal->href())->with('toast', Wording::mine($exit->label))->with('open_sheet', 'deal-buyer-ask-'.$deal->id);
        }

        // Сразу туда, где сделку смотрят (гаражная — в гараж) или откуда нажали: второй редирект съел бы тост.
        return $this->back($request, $deal)->with('toast', Wording::mine($exit->label));
    }

    /** Туда, откуда нажали (`back` — путь этого же сайта: чат, «Предложения», «Сделки»), иначе на сделку. */
    private function back(Request $request, Deal $deal)
    {
        return redirect(SafeBack::path($request->input('back')) ?? $deal->href());
    }

    /**
     * «Это чек об оплате» под его фото в чате сделки (10.11.2026, `x-chat.doc-pick`): файлы сообщения — в просьбу шага
     * (`AttachChatFiles`), и ответ шага, если он один («Оплатил, документ приложен»); вариантов несколько — на шаг.
     */
    public function chatFiles(Request $request, Deal $deal, AttachChatFiles $attach, AnswerRequirement $answer)
    {
        $me = $request->user();
        $data = $request->validate(['message' => ['required', 'integer'], 'back' => ['nullable', 'string', 'max:500']]);
        $requirement = $deal->openRequirement()->with('stage.exits')->first();
        if (! $requirement || $requirement->asks !== Asks::Document) {
            return $this->back($request, $deal)->with('toast', 'Ответ уже не нужен');
        }
        $message = Message::with(['files', 'chat'])->findOrFail($data['message']);
        abort_unless($message->chat->offer_id === $deal->offer_id && $message->chat->user_id === $me->id
            && $message->author_kind === AuthorKind::Participant && ! $message->isDeleted(), 404);
        if ($attach($requirement, $message) === 0) {
            return $this->back($request, $deal)->with('toast', 'Файл не нашёлся, приложите его на шаге');
        }
        $move = $requirement->stage?->isPayStep() ? $deal->proofMove($requirement->stage) : $deal->soleMove($requirement->stage);
        if (! $move) {
            return redirect($deal->href().'#step')->with('toast', 'Приложено, ответьте на шаге');
        }
        $answer($requirement->fresh(), $move, $me);

        return $this->back($request, $deal->refresh())->with('toast', Wording::mine($move->label));
    }

    /**
     * Новый покупатель по виду (13.11.2026, владелец: «"внесём сами" не катит, они сами должны поля заполнять; паспорт — ладно,
     * скан нужен»): плитка «Физлицо» / «ИП» / «Организация» (`x-deal.buyer-kind`) открывает шторку «Новый покупатель»
     * (`cabinet.deals.buyer-draft`): поля, у физлица и ИП — скан паспорта с чтением. Человека плитка не заводит (21.11.2026,
     * владелец: брошенные «Новый покупатель» в покупателях — «тупость»): у договора встаёт черновик данных (`draft_party_id`,
     * контрагент без аккаунта — к нему ложится скан), покупатель появляется, когда данные сохранили (`SaveDealContract`).
     */
    public function buyerNew(Request $request, Deal $deal)
    {
        $me = $request->user();
        abort_unless($deal->isLive() && $deal->buyer_id === $me->id && ! $me->isSuperBuyer(), 404);
        $kind = $request->validate(['kind' => ['required', 'in:person,ip,company']])['kind'];
        $as = match ($kind) {
            'ip' => PartyKind::Entrepreneur, 'company' => PartyKind::Company, default => PartyKind::Person,
        };
        $contract = DealContract::for($deal);
        // Передумал вид или нажал дважды — тот же черновик: вписанное и скан на месте, меняется только вид.
        $draft = $contract->draftParty;
        if ($draft && ! User::where('party_id', $draft->id)->exists()) {
            $draft->update(['kind' => $as]);
        } else {
            $contract->update(['draft_party_id' => Party::create(['kind' => $as, 'name' => ''])->id]);
        }

        return $this->back($request, $deal)->with('open_sheet', 'deal-buyer-new');
    }

    /** «Ещё нет» и закрытие вопроса при входе (`x-deal.ask-sheet`): до завтра не спрашиваем (`requirements.prompted_at`). */
    public function later(Request $request, Deal $deal)
    {
        $deal->openRequirement()->first()?->update(['prompted_at' => now()]);

        return $request->expectsJson() ? response()->noContent() : $this->back($request, $deal);
    }

    /**
     * «Напомнить через…» (19.11.2026, `Workflow\Actions\RemindAsks`): вопрос шага или доп. просьбы придёт снова — уведомлением и
     * сообщением в чат; пустое `when` — не напоминать. Вопрос уже закрыт — «Ответ уже не нужен», как у ответа.
     */
    public function remind(Request $request, Deal $deal)
    {
        $data = $request->validate(['when' => ['nullable', 'in:'.implode(',', array_keys(\App\Workflow\Actions\RemindAsks::CHOICES))], 'extra' => ['nullable', 'boolean'],
            'back' => ['nullable', 'string', 'max:500']]);
        $ask = $request->boolean('extra') ? \App\Offers\ExtraAsk::holding($deal) : $deal->openRequirement()->first();
        if (! $ask || ($ask instanceof \App\Offers\ExtraAsk && $ask->phase() !== \App\Offers\ExtraAsk::MANAGER)) {
            return $this->back($request, $deal)->with('toast', 'Ответ уже не нужен');
        }
        $at = filled($data['when'] ?? null) ? \App\Workflow\Actions\RemindAsks::at($data['when']) : null;
        \App\Workflow\Actions\RemindAsks::set($ask, $at, $request->user());

        return $this->back($request, $deal)->with('toast', $at ? 'Напомним '.\App\Workflow\Actions\RemindAsks::when($at) : 'Не напомним');
    }

    /** Время подписания у поставщика — плиткой или своим (`PickSigningTime`). */
    public function signing(Request $request, Deal $deal, PickSigningTime $pick)
    {
        $deal = $pick($deal, $request->user(), (string) $request->validate(['at' => ['required', 'string', 'max:30']])['at']);

        return $this->back($request, $deal)->with('toast', 'Записываем на '.Ago::at($deal->signing_at, true, words: false));
    }

    /** Когда покупатель сможет подписать договор (`SaveSigningWish`): день и время — со страницы сделки и из чата. */
    public function signingWish(Request $request, Deal $deal, SaveSigningWish $save)
    {
        $data = $request->validate(['slots' => ['required', 'array', 'max:60'], 'slots.*' => ['string', 'max:16']], ['slots.required' => 'Отметьте хотя бы одно время']);
        $deal = $save($deal, $request->user(), $data['slots']);

        return $this->back($request, $deal)->with('toast', 'Покупатель может '.Signing::wishLine($deal));
    }

    /** Город подписания (25.11.2026, `SetSigningCity`): чипом или поиском в шторке «Другой город…». */
    public function signingCity(Request $request, Deal $deal, \App\Offers\Actions\SetSigningCity $set)
    {
        $data = $request->validate(['settlement_id' => ['required', 'integer', 'exists:settlements,id']], ['settlement_id.required' => 'Выберите город']);
        $deal = $set($deal, \App\Cars\Settlement::findOrFail($data['settlement_id']), $request->user());

        return $this->back($request, $deal)->with('toast', 'Город подписания: '.$deal->signingCity?->name);
    }

    /** Поиск города в шторке «Другой город…» — тот же справочник, что у CRM (`ReferenceController::settlements`). */
    public function signingPlaces(Request $request, Deal $deal)
    {
        return app(\App\Http\Admin\ReferenceController::class)->settlements($request);
    }

    /** Встреча в календарь телефона: подписание на час, с адресом. */
    public function signingIcs(Request $request, Deal $deal)
    {
        abort_unless(Signing::booked($deal), 404);

        return response(Signing::ics($deal), 200, ['Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="podpisanie-'.$deal->offer->number.'.ics"']);
    }

    /** Кто заберёт машину с нашей парковки и когда (`SetReceiver`): пропуск новый, парковка видит сразу. */
    public function receiver(Request $request, Deal $deal, SetReceiver $set)
    {
        $deal = $set($deal, $request->user(), $request->validate(['who' => ['required', 'string', 'max:20'], 'name' => ['nullable', 'string', 'max:160'],
            'phone' => ['nullable', 'string', 'max:32'], 'at' => ['nullable', 'date']]));

        return redirect($deal->href())->with('toast', 'Заберёт '.$deal->receiver()?->labelFor($request->user()));
    }

    /** «Попросить вывоз…» — менеджер, что забирает сам, просит логистов (`RequestPickup`). */
    public function requestPickup(Request $request, Deal $deal, RequestPickup $pickup)
    {
        abort_unless($deal->isActive() && $deal->offer, 404);
        // То же правило, что рисует кнопку (`Handover::canRequest`, 11.11.2026: просить можно было и когда ТС уже у нас).
        if (! Handover::for($deal)->canRequest) {
            return $this->back($request, $deal)->with('toast-danger', 'Вывоз сейчас не нужен');
        }
        $pickup($deal->offer, $request->user());

        return $this->back($request, $deal)->with('toast', 'Попросили вывоз, логистика назначит исполнителя');
    }

    /** «Автомобиль забрал», когда шага получения в маршруте нет (`Handover::canPick`): вывоз закрыт у него. */
    public function picked(Request $request, Deal $deal, SelfPickup $self)
    {
        abort_unless($deal->isActive() && $deal->offer, 404);
        if (! Handover::for($deal)->canPick) {
            return redirect($deal->href())->with('toast-danger', 'Забрать сейчас нельзя');
        }
        $self($deal->offer, $request->user(), $request->user());

        return redirect($deal->href())->with('toast', 'Автомобиль забрал');
    }

    /**
     * Файл в просьбу шага. На шаге оплаты платёжка — и ответ (16.11.2026, `Deal::proofMove`): шаг сделан, страницу перечитывает
     * `photos_controller` (`reload`). Платёжка ушла, поставщик ещё не подтвердил — файл ложится к ней же
     * (`Deal::sentPaymentProof`): «Приложить ещё» и остальные файлы той же пачки.
     */
    public function upload(Request $request, Deal $deal, AnswerRequirement $answer)
    {
        $requirement = $deal->openRequirement()->first() ?? $deal->sentPaymentProof();
        abort_unless($requirement, 404);
        if (! $requirement->done_at && ($gap = $deal->signingCityGap($requirement->stage))) {
            return response()->json(['message' => $gap], 422);
        }
        $request->validate(['file' => ['required', 'file', 'max:30720']]);
        $file = $request->file('file');
        try {
            app(AttachRequirementFiles::class)($requirement, $file);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        if ($this->proofPassed($deal, $requirement, $answer, $request->user())) {
            session()->flash('toast', 'Документ об оплате приложен');

            return Stream::response('');
        }

        return $this->filesStream($deal, $requirement);
    }

    /**
     * «Приложить» одним документом в просьбу шага (24.11.2026, `x-ui.photo-pdf`): фото и файлы собраны в один
     * (`PhotosPdf`) под названием человека; на шаге оплаты — он же ответ, как у `upload`. Страница перечитывается.
     */
    public function uploadDoc(Request $request, Deal $deal, AnswerRequirement $answer, PhotosPdf $photos)
    {
        $requirement = $deal->openRequirement()->first() ?? $deal->sentPaymentProof();
        abort_unless($requirement && $requirement->asks === Asks::Document, 404);
        // Город подписания — до документа об оплате (25.11.2026, владелец: «без указания чек пусть не отправляется»).
        if (! $requirement->done_at && ($gap = $deal->signingCityGap($requirement->stage))) {
            return response()->json(['message' => $gap], 422);
        }
        $doc = $photos->fromRequest($request, $deal->offer->title());
        app(AttachRequirementFiles::class)($requirement, $doc['file'], named: true);
        $paid = $this->proofPassed($deal, $requirement, $answer, $request->user());
        session()->flash('toast', $paid ? 'Документ об оплате приложен' : 'Приложено: '.$doc['name']);

        return response()->json(['reload' => true, 'name' => $doc['name'], 'pages' => $doc['pages']]);
    }

    /** На шаге оплаты платёжное поручение — и ответ шага (16.11.2026, `Deal::proofMove`). */
    private function proofPassed(Deal $deal, Requirement $requirement, AnswerRequirement $answer, User $me): bool
    {
        if (! $requirement->done_at && $requirement->asks === Asks::Document && $requirement->stage?->isPayStep()
            && ($move = $deal->proofMove($requirement->stage))) {
            $answer($requirement->fresh(), $move, $me);

            return true;
        }

        return false;
    }

    /**
     * «Приложить чек» на шаге оплаты «страховой напрямую» (16.11.2026, вариант Б, `cabinet.deals.receipt`): `for` — за что чек.
     * Поставщику — платёжка шага (PDF, шаг пройден, как `upload`); ООО «ПРАЙМ» — «я оплатил» на остаток счёта с этим чеком (`ClaimPayment`, как «По счёту» в шторке оплаты).
     */
    public function receipt(Request $request, Deal $deal, AnswerRequirement $answer, ClaimPayment $claim)
    {
        $me = $request->user();
        $data = $request->validate([
            'file' => ['required', 'file', 'max:20480', 'mimes:pdf,jpg,jpeg,png,heic,heif,webp'],
            'for' => ['required', 'string', 'regex:/^(supplier|invoice:\d+)$/'],
        ], ['file.required' => 'Выберите файл чека', 'file.mimes' => 'Нужен снимок или PDF', 'file.max' => 'Файл больше 20 МБ', 'for.required' => 'Выберите, за что чек']);
        if ($data['for'] === 'supplier') {
            // Шаг ждёт платёжку — она и ответ; платёжка ушла, поставщик не подтвердил — ещё лист к ней (`sentPaymentProof`).
            $open = $deal->openRequirement()->first();
            $requirement = $open && AttachRequirementFiles::paysOn($open) && $deal->paysSupplierOn($open->stage) ? $open : (! $open ? $deal->sentPaymentProof() : null);
            if (! $requirement) {
                return redirect($deal->href())->with('toast', 'Сейчас чек поставщику не приложить');
            }
            if (! $requirement->done_at && ($gap = $deal->signingCityGap($requirement->stage))) {
                return redirect($deal->href().'#money')->with('toast', $gap);
            }
            app(AttachRequirementFiles::class)($requirement, $request->file('file'));
            if (! $requirement->done_at && ($move = $deal->proofMove($requirement->stage))) {
                $answer($requirement->fresh(), $move, $me);
            }

            return redirect($deal->href())->with('toast', 'Чек приложен к оплате поставщику');
        }
        $invoice = $deal->issuedInvoices()->whereKey((int) substr($data['for'], 8))->firstOrFail();
        abort_unless($invoice->isVisibleToManager($me) && ! $invoice->isOwed(), 404);
        if ($gap = $deal->signingCityGap($deal->offer->position(\App\Workflow\Track::Sale)?->stage)) {
            return redirect($deal->href().'#money')->with('toast', $gap);
        }
        $left = round($invoice->remaining() - $invoice->claimed(), 2);
        if ($left <= 0) {
            return redirect($deal->href())->with('toast', 'По этому счёту оплата уже приложена');
        }
        $claim($invoice, $me, $left, null, $request->file('file'), null, PaymentSource::Bank);

        return redirect($deal->href())->with('toast', 'Чек приложен к оплате '.($invoice->seller->config()['name'] ?? 'по счёту'));
    }

    public function removeFile(Request $request, Deal $deal, Media $media)
    {
        $requirement = $deal->openRequirement()->first() ?? $deal->sentPaymentProof();
        abort_unless($requirement && $media->model_id === $requirement->id && $media->model_type === $requirement::class, 404);
        // Ушедшая платёжка остаётся хотя бы одним файлом: убрать последний — значит отозвать оплату, а это не сюда.
        abort_if($requirement->done_at && $requirement->getMedia('files')->count() < 2, 422);
        $media->delete();

        return $requirement->done_at ? redirect($deal->href().'#step') : $this->filesStream($deal, $requirement);
    }

    /** Файлы шага, его кнопки (появляются с первым файлом) и галка «Подписанный договор» — одним потоком. */
    private function filesStream(Deal $deal, Requirement $requirement)
    {
        // Файл шага видят CRM (путь сделки) и вторая вкладка менеджера — без перезагрузки (19.10.2026).
        RefreshDeal::of($deal);
        $requirement = $requirement->fresh();

        // Кнопки — шага этой просьбы (у параллельной — её теневого), а не шага машины.
        return Stream::view('cabinet.deals.files-stream', ['requirement' => $requirement, 'deal' => $deal,
            'exits' => $deal->managerExits($requirement->stage)]);
    }

    /**
     * Подписанный договор, сделанный вне системы (15.11.2026, `AttachSignedContract`): приложить на любом шаге, без
     * покупателя; файлов можно несколько (страницы фотографиями).
     */
    public function signed(Request $request, Deal $deal, AttachSignedContract $attach)
    {
        $request->validate(['files' => ['required', 'array', 'max:20'], 'files.*' => ['file', 'max:30720']],
            ['files.required' => 'Выберите файл договора', 'files.*.max' => 'Файл больше 30 МБ']);
        $this->attachSigned($deal, $attach, $request->user(), $request->file('files'));

        return back()->with('toast', 'Подписанный договор приложен');
    }

    /** «Приложить» договор одним документом (24.11.2026, `x-ui.photo-pdf`): фото и файлы — один под названием человека. */
    public function signedDoc(Request $request, Deal $deal, AttachSignedContract $attach, PhotosPdf $photos)
    {
        abort_unless(AttachSignedContract::allows($deal), 404);
        $doc = $photos->fromRequest($request, $deal->offer->title());
        $this->attachSigned($deal, $attach, $request->user(), [$doc['file']], named: true);
        session()->flash('toast', 'Договор приложен');

        return response()->json(['reload' => true, 'name' => $doc['name'], 'pages' => $doc['pages']]);
    }

    /**
     * Шаг договора у него открыт — файлы в просьбу шага и сразу ответ «Договор приложен» (18.11.2026, владелец: «убирай чек-лист
     * договора»; кнопка «Приложить подписанный договор» в блоке денег): маршрут идёт своим исходом, как раньше по кнопке.
     *
     * @param  list<UploadedFile>  $files
     */
    private function attachSigned(Deal $deal, AttachSignedContract $attach, User $me, array $files, bool $named = false): void
    {
        $requirement = $deal->buyer_id === $me->id ? $deal->openRequirement()->with('stage.exits')->first() : null;
        if ($requirement && $requirement->asks === Asks::Document && $requirement->stage?->aboutContract() && ! $requirement->stage->isPayStep()
            && ($move = $deal->soleMove($requirement->stage))) {
            foreach ($files as $file) {
                app(AttachRequirementFiles::class)($requirement, $file, $named);
            }
            app(AnswerRequirement::class)($requirement->fresh(), $move, $me);

            return;
        }
        foreach ($files as $file) {
            $attach($deal, $file, $me, $named);
        }
    }

    /** Подписанный договор одним PDF — файлы сделки и ответы шага договора (`SignedContractPdf`, 17.11.2026). */
    public function signedPdf(Deal $deal)
    {
        $pdf = \App\Offers\Documents\SignedContractPdf::render($deal) ?? abort(404);

        return response($pdf, 200, ['Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename*=UTF-8\'\''.rawurlencode(\App\Offers\Documents\SignedContractPdf::fileName($deal))]);
    }

    public function removeSigned(Request $request, Deal $deal, Media $media)
    {
        abort_unless(AttachSignedContract::allows($deal), 404);
        AttachSignedContract::remove($deal, $media, $request->user());

        return back()->with('toast', 'Файл договора убран');
    }
}
