<?php

namespace App\Offers;

use App\Workflow\Position;
use App\Workflow\WaitsFor;
use Illuminate\Support\Collection;
use App\Support\StaffCache;
use Illuminate\Support\Facades\Cache;

/**
 * Вкладки сделки в «Предложениях» — по этапам маршрута (16.11.2026, владелец: «„Ваш ход“ ничего не говорит об этапе, разбить на
 * конкретные вкладки», «пришли согласования от поставщика — отдельная вкладка, переоценка — отдельная»; «Расчёт» — непонятно,
 * кому не заплачено). Каждая идущая сделка — ровно в одной вкладке. С 17.11.2026 вкладки двух видов (владелец: «непонятно, почему
 * счётчик у „Менеджер должен нам“ горит, если он нам должен, — мы же ничего сделать не можем»): «Сделать» — есть наше дело
 * (`Position::todo`), вкладка по виду дела, красный счётчик; «Ждём» (`WAITING`) — дела нет, вкладка по блоку шага (`BLOCKS`),
 * в колесе полупрозрачная и без счётчика. Правило — `of`.
 * Раскладка всех сделок — раз на 30 с (`Cache`, сброс — `Nav::forgetStaffCounts`): колесо считает её на каждой странице.
 */
final class DealTabs
{
    /**
     * Ключ → подпись, в порядке маршрута. Подпись — кто что делает (16.11.2026, владелец выбрал из двух вариантов: «Реквизиты и
     * счёт» и прочие существительные — «непонятно»). Ключи — адреса вкладок, их не меняем.
     */
    public const TABS = [
        // Одной лентой по ходу сделки (18.11.2026, владелец: «„Ждём“ отдельно под подзаголовком — убрать, вкладки, где ждём, —
        // среди обычных, чтобы была логика последовательности»): согласование → счёт → оплата менеджера → оплата поставщику →
        // получение → документы. Вкладка с нашим делом — красный счётчик, вкладка ожидания (`WAITING`) — полупрозрачная, обычным
        // шрифтом, без счётчика (владелец: «regular font полупрозрачный, чтобы показать, что там чисто ждать надо»).
        // «Сделать» — строка только при нашем деле, вкладка — по виду дела (`of`; 17.11.2026, владелец: «разлепить, где точно от
        // нас требуется действие, а где мы просто ждём»); «Ждём» — по блоку шага (`BLOCKS`).
        // «Назначить вознаграждение» (24.11.2026, владелец: «для Андрея очень не хватает вкладки перед переоценкой»): пустое
        // вознаграждение — не ноль (`Deal::rewardPending`), без него нет счёта подбора; до того дело лежало в «Отправить счёт».
        'reward' => 'Назначить вознаграждение',
        // Переоценка — первой из шагов сделки: пока цена не решена, остальное ждёт.
        'reprice' => 'Переоценка',
        // Доп. просьба поставщику держит сделку (18.11.2026, `ExtraAsk`): всю паузу — здесь, группами «Ждём поставщика», «Показать
        // менеджеру» (наш ход), «Ждём менеджера».
        'extra' => 'Доп. просьба',
        // Вопрос менеджеру (18.11.2026): письмо Т-Страхования пришло — спросить, берёт ли в работу (`Position::todo` `ask-consent`);
        // и всё, где ждём ответа менеджера на шаге согласования (группа «Ждём ответа»).
        'ask' => 'Спросить менеджера',
        // «Ждём данные покупателя» (27.11.2026, владелец: «когда менеджер что-то заполняет — это не „Спросить менеджера“, а отдельная
        // вкладка сразу после, полупрозрачная с иконкой часов»): на оплате нет покупателя или его данных (`awaitsBuyer`), реквизиты
        // запросили у менеджера на шаге письма (`requisites-asked`). Ожидание (`WAITING`).
        'buyer_data' => 'Ждём данные покупателя',
        // «Проверить реквизиты» (26.11.2026, владелец: «распознавание часто кривое, менеджеры отправляют как есть — Алина проверяет»):
        // следующее наше дело отправляет реквизиты покупателя (письмо с реквизитами, счёт за ТС, договор), а их этот вид ещё не
        // проверили (`DealContract::needsBuyerCheck`). Дело — «Проверить и отправить» одним действием (`x-deal.buyer-check`).
        'verify' => 'Проверить реквизиты',
        // «Подтвердить актуальность» (27.11.2026, владелец: «из „Написать поставщику“ в отдельную вкладку, ответственный Андрей; по
        // остальным „Написать поставщику“ — Алина и Лиза»): письмо шага «Поставщику: подтверждение покупки» (`Position::letterAction`).
        'confirm' => 'Подтвердить актуальность',
        'letter' => 'Написать поставщику',
        // «На согласовании» (17.11.2026, владелец: «не Согласование, а На согласовании») — с 18.11.2026 только ожидание поставщика
        // (владелец: «то, что менеджеры думают, там не должно быть ничего»).
        'agree' => 'На согласовании',
        'answered' => 'Поставщик ответил',
        'invoice' => 'Ждём поставщика',
        'bill' => 'Отправить счёт',
        // Договор (18.11.2026, владелец): паспорт приложен, а договору данных не хватает — «Данные не распознались»; готов и не
        // отправлен — «Счёт оплачен» (красным) и «Счёт не оплачен» (`Deal::paperTodo`).
        'contract' => 'Отправить договор',
        // «Менеджер должен нам» — только то, что ожидает платежа; пришли оплаты — «Проверить оплату» (17.11.2026, владелец).
        // С 26.11.2026 двумя вкладками (владелец: «Андрей хочет собирать только оплаты за подбор»): «Ждём оплату ТС» — долг за машину
        // (Алина), «Ждём оплату подбора» — за услуги подбора (Андрей); подбор внутри неоплаченного счёта за ТС — в обеих (`paying`).
        'paying' => 'Ждём оплату ТС',
        'paying_fee' => 'Ждём оплату подбора',
        // «Ждём покупателя» (24.11.2026, владелец: «счёт отправлен, ждём, когда оплатят — полупрозрачная вкладка без ответственного»):
        // покупатель платит поставщику сам по его счёту (`Deal::paysSupplierOn`), счёт показан — нам он ничего не должен.
        // «Покупатель оплачивает» с 26.11.2026 (владелец: «отдельная вкладка-ожидание, Алина её видит, но она тихая»).
        'buyer' => 'Покупатель оплачивает',
        'check' => 'Проверить оплату',
        'arrived' => 'Деньги пришли',
        'payout' => 'Оплатить поставщику',
        // «Оригиналы» (26.11.2026, владелец: «сделка не закрыта, пока оригиналы документов не получили»; ведёт Лиза): у Совкома
        // договор комиссии и акт (или доверенность) — к нам, ДКП — прежнему владельцу; через Р.О.С. или ПРАЙМ — ДКП и акты с
        // поставщиком и с покупателем, счёт-фактура — к нам со сканом (`Originals::kit`).
        'originals' => 'Оригиналы',
        'pickup' => 'Менеджер забирает ТС',
        // «Ждём договор» (26.11.2026, владелец: «мониторить подгрузку фоток договоров — отдельная вкладка, Алина видит, подгрузили или
        // нет, если что — сама подгрузит или напомнит менеджеру»): шаг просит у менеджера подписанный договор, а его ещё нет. Ожидание:
        // красным — только просроченные (у Алины «только горящее»).
        'signed' => 'Ждём договор',
        'docs' => 'Подписание договора',
        // Дело без своего места в ходе сделки — последней. «Не распределено», а не «Другие дела» (18.11.2026, владелец).
        'todo' => 'Не распределено',
    ];

    /** Вкладки «Ждём»: нашего дела в них нет. */
    public const WAITING = ['buyer_data', 'agree', 'invoice', 'paying', 'paying_fee', 'buyer', 'pickup', 'signed', 'docs'];

    /** Группы «Ждём поставщика» по шагу: чего ждём. */
    public const WAIT_STAGES = ['Письмо с реквизитами' => 'Ждём счёт', 'Подтверждение оплаты' => 'Ждём подтверждение оплаты'];

    /** Заголовок группы дела, когда дело — само название этапа (`Position::todo` по умолчанию): действием, а не этапом. */
    public const TODO_TITLES = [
        // Менеджер сам попросил доп. запрос (27.11.2026, `Offers\ExtraWant`): группа — кто просит, дело в строке — что сделать.
        'Написать доп. запрос' => 'Менеджер просит',
        'Проверка оплаты' => 'Проверить оплату',
        'Проверка оплаты подбора' => 'Проверить оплату подбора',
        'Оплата поставщику' => 'Оплатить поставщику',
        'Оплата поставщику, в гараж' => 'Оплатить поставщику, ТС в наш гараж',
        'Перевод поставщику' => 'Перевести деньги поставщику',
    ];

    /** Блок шага продажи → вкладка; блок не из таблицы — согласование. Заявка Каркаде — то же письмо, что ещё не ушло. */
    public const BLOCKS = [
        'Реквизиты для счёта' => 'invoice',
        'Собираем заявку поставщику' => 'letter',
        'Оплата' => 'paying',
        'Расчёт с поставщиком' => 'payout',
        'Взяли под себя' => 'payout',
        'Оформляем на нас' => 'payout',
        'Получение автомобиля' => 'pickup',
        'Договор' => 'docs',
        'Оформление документов' => 'docs',
    ];

    private const CACHE = 'deal-tabs:6';

    /**
     * Вкладка и наш ли ход одной сделки по её шагу и делу. `$repricing` — вопрос переоценки ещё не закрыт (`RepriceFlow::for`,
     * 17.11.2026, владелец: «пока вопрос переоценки не закрыт, она должна быть в переоценке всё время, в нужной подгруппе, никуда не
     * уходить, пока не решится вопрос с новой ценой»): и когда ход менеджера, и когда он назвал новую цену.
     */
    public static function of(?Position $position, ?array $todo, bool $repricing = false): string
    {
        $key = $todo['key'] ?? '';
        $block = self::BLOCKS[$position?->stage?->block?->name ?? $position?->stage?->name ?? ''] ?? 'agree';
        if ($todo === null && ! $repricing) {
            // Ждём. Блок, что сам вкладка «Сделать» (оплата поставщику, заявка), без нашего дела — к тому, кого ждём; ждём
            // поставщика на шагах оплаты (подтверждение оплаты, счёт) — «Ждём поставщика», а не «Менеджер должен нам».
            $supplier = $position?->waitsFor() === WaitsFor::Supplier;

            return match (true) {
                // Менеджер думает (берёт ли, что ответить) — пока не ответил, сделка в «Спросить менеджера» (18.11.2026); оплату,
                // получение и договор он ведёт сам — там свои вкладки ожидания.
                self::asksManager($position, $block) => 'ask',
                $block === 'paying' && ! $supplier && $position?->deal?->paysSupplierOn($position->stage) => 'buyer',
                in_array($block, self::WAITING, true) && ! ($supplier && $block === 'paying') => $block,
                $supplier => 'invoice',
                $block === 'payout' => 'paying',
                default => 'agree',
            };
        }

        return match (true) {
            $repricing || in_array($key, ['reprice', 'reprice-answer', 'price-ask', 'price-offer'], true) => 'reprice',
            in_array($key, ['letter:confirm', 'letter-agree:confirm'], true) => 'confirm',
            in_array($key, ['bounced', 'requisites'], true) || str_starts_with($key, 'letter') || str_starts_with($key, 'request:') => 'letter',
            in_array($key, ['ask-consent', 'consent'], true) => 'ask',
            // Контакт менеджеру, что забирает сам (25.11.2026, `ContactDue`) — машина в «Менеджер забирает ТС», счётчик — у «Показать
            // контакты» раздела «Вывоз».
            $key === 'contact' => 'pickup',
            // Ответ поставщика на разбор — на каком бы шаге ни пришёл.
            $key === 'reply' => 'answered',
            str_starts_with($key, 'stage:Проверка оплаты') => 'check',
            $key === 'gap:commission' => 'reward',
            // Деньги пришли раньше счёта — дооформить (записать продажу, покупатель для договора).
            str_starts_with($key, 'gap:paid') => 'arrived',
            // Счёта нет или менеджер его ещё не получил: выставить, отправить, назначить вознаграждение.
            str_starts_with($key, 'gap:') => 'bill',
            str_starts_with($key, 'contract:') => 'contract',
            // Шаг нашей оплаты поставщику, в каком бы блоке он ни стоял.
            (bool) $position?->stage?->paysOut() => 'payout',
            default => 'todo',
        };
    }

    /**
     * Дело шага у идущих сделок — одной выборкой (переоценки письмом — одним запросом, как было в «Ваш ход»).
     *
     * @param  Collection<int, Deal>  $deals  со связями `offer.positions.stage…`
     * @return Collection<int, array{deal: Deal, tab: string, todo: ?array}> по `deal.id`
     */
    public static function rows(Collection $deals): Collection
    {
        $offers = $deals->pluck('offer_id')->filter()->unique();
        $letters = Reprice::whereIn('offer_id', $offers)->where('state', Reprice::APPLIED)
            ->where('source', 'letter')->whereNull('answered_at')->distinct()->pluck('offer_id')->flip();
        // У кого вообще есть переоценка (ждущая или разобранная) — только им спрашиваем, закрыт ли вопрос (`RepriceFlow::for`).
        $repriced = Reprice::whereIn('offer_id', $offers)->whereIn('state', [Reprice::PENDING, Reprice::APPLIED])->distinct()->pluck('offer_id')->flip();
        // Вопрос открыт и без `RepriceFlow` (17.11.2026, владелец про Audi RS 6 Сергея — гаражная сделка, у неё шага «Переоценка» нет):
        // ждущая переоценка или менеджер ещё не ответил про новую цену.
        $pending = Reprice::whereIn('offer_id', $offers)->where('state', Reprice::PENDING)->distinct()->pluck('offer_id')->flip();
        $asked = RepriceAsk::whereIn('deal_id', $deals->pluck('id'))->whereIn('state', RepriceAsk::OPEN)->distinct()->pluck('deal_id')->flip();

        return $deals->mapWithKeys(function (Deal $deal) use ($letters, $repriced, $pending, $asked) {
            $position = $deal->offer?->position();
            $position?->setRelation('offer', $deal->offer)->setRelation('deal', $deal);
            $position && ($position->letterRepriceKnown = $letters->has($deal->offer_id));
            $todo = $position?->todo();
            $repricing = $pending->has($deal->offer_id) || $asked->has($deal->id)
                || ($repriced->has($deal->offer_id) && $deal->offer && RepriceFlow::for($deal->offer->setRelation('deal', $deal)) !== null);
            // Менеджер думает про новую цену — подгруппа «Ждём менеджера» (`waits`), а не дело шага («Прочитать ответ поставщика» — это
            // и есть письмо переоценки, его черёд после ответа). Свои дела переоценки (не ответил к сроку, назвал цену) — остаются.
            if ($asked->has($deal->id) && ! in_array($todo['key'] ?? '', ['reprice', 'reprice-answer', 'price-ask', 'price-offer'], true)) {
                $todo = null;
            }
            // Реквизиты запросили у менеджера на шаге письма (18.11.2026) — его ход: «Спросить менеджера», группа «Ждём ответа».
            if (($todo['key'] ?? '') === 'requisites-asked') {
                return [$deal->id => ['deal' => $deal, 'tab' => 'buyer_data', 'todo' => null]];
            }
            // Доп. просьба держит сделку — она во вкладке «Доп. просьба», что бы ни было на шаге (кроме переоценки — она первой).
            if (! $repricing && ExtraAsk::holding($deal)) {
                // Менеджер решает, а поставщик написал ещё (27.11.2026, Wielton 2610152180: «ждём менеджера — с какого хера красным»;
                // «разбор писем — пиздец срочно»): письмо — в «Поставщик ответил», где письма разбирают; «Доп. просьба» ждёт тихо.
                if (($todo['key'] ?? '') === 'reply' && $position?->extraAsk()?->phase() === ExtraAsk::MANAGER) {
                    return [$deal->id => ['deal' => $deal, 'tab' => 'answered', 'todo' => $todo]];
                }

                return [$deal->id => ['deal' => $deal, 'tab' => 'extra', 'todo' => $todo]];
            }
            // Счёт поставщика показан, просьба «Оплатите» висит, а ход остался нашим (2610152115: шаг «Оплата счёта» с нашим ходом без
            // дела) — ждём покупателя, а не «Не распределено».
            if (str_starts_with($todo['key'] ?? '', 'stage:') && $position?->stage && $deal->paysSupplierOn($position->stage) && $deal->openRequirement) {
                $todo = null;
            }
            // У шага своего дела нет — наше дело по документам покупателя: не распознались данные, договор не отправлен (18.11.2026).
            if ($todo === null && ! $repricing) {
                $todo = $deal->paperTodo();
            }
            // Шаг «Оригиналы» — своей вкладкой: наше дело — отправить, что у нас; остальное — ждём (26.11.2026).
            if (! $repricing && Originals::applies($position?->stage)) {
                // Документы — одной выборкой на дело и значки строки (`x-deal.todo-row` столбец «Документы»).
                $docs = Originals::of($deal);

                return [$deal->id => ['deal' => $deal, 'tab' => 'originals', 'todo' => Originals::todo($deal, $position->entered_at, $docs), 'docs' => $docs]];
            }
            // Отправляем реквизиты покупателя, а они не проверены — сначала проверка (26.11.2026): «Проверить реквизиты».
            if (! $repricing && self::verifies($deal, $todo)) {
                return [$deal->id => ['deal' => $deal, 'tab' => 'verify', 'todo' => [...$todo, 'key' => 'verify', 'title' => 'Проверить реквизиты', 'rank' => 1,
                    'button' => ['label' => 'Проверить', 'href' => '/offers/'.$deal->offer?->number]]]];
            }

            // Шаг оплаты, а менеджер не указал покупателя или его данные (Совкомбанк: без них нет счёта и договора) — «Спросить
            // менеджера» (26.11.2026, владелец: «Алина отвечает за то, чтобы менеджеры заполнили реквизиты для счёта»).
            if ($todo === null && ! $repricing && self::awaitsBuyer($deal, $position)) {
                return [$deal->id => ['deal' => $deal, 'tab' => 'buyer_data', 'todo' => null]];
            }
            // Ждём от менеджера фото подписанного договора — «Ждём договор» (26.11.2026), на каком бы блоке шаг ни стоял.
            if ($todo === null && ! $repricing && self::awaitsSigned($deal, $position)) {
                return [$deal->id => ['deal' => $deal, 'tab' => 'signed', 'todo' => null]];
            }

            return [$deal->id => ['deal' => $deal, 'tab' => self::of($position, $todo, $repricing), 'todo' => $todo]];
        });
    }

    /** Шаг оплаты ждёт менеджера, а счёта нет: не указан покупатель или его данные (`Deal::invoiceGap`). */
    public static function awaitsBuyer(Deal $deal, ?Position $position): bool
    {
        return $position?->stage?->isPayStep() && $position->waitsFor() === WaitsFor::Manager && in_array($deal->invoiceGap(), ['buyer', 'passport'], true);
    }

    /** Шаг ждёт от менеджера подписанный договор (`Asks::Document` про договор), а его ещё нет — ни у сделки, ни в ответе шага. */
    public static function awaitsSigned(Deal $deal, ?Position $position): bool
    {
        $stage = $position?->stage;

        return $stage !== null && $stage->asks === \App\Workflow\Asks::Document && $stage->aboutContract() && $position->waitsFor() === WaitsFor::Manager
            && ! $deal->contractSigned() && \App\Offers\Documents\SignedContractPdf::files($deal)->isEmpty();
    }

    /** Раскладка всех идущих сделок: `offer_id` → [вкладка, наш ход]. @return array<int, array{0: string, 1: bool}> */
    public static function map(): array
    {
        // Сделки Каркаде — не во вкладках сделки, а в «Предложениях из закупок» (24.11.2026, владелец: «из сделок тоже надо вытащить»).
        return array_filter(self::raw(), fn (array $v) => ! $v[2]);
    }

    /** Сделки Каркаде: машина → вкладка, где она стояла бы (подгруппы «Предложений из закупок»). @return array<int, string> */
    public static function carcade(): array
    {
        return array_map(fn (array $v) => $v[0], array_filter(self::raw(), fn (array $v) => $v[2]));
    }

    /** Вся раскладка: машина → [вкладка, наше ли дело, Каркаде, шаг просрочен, за что долг на оплате]. @return array<int, array{0: string, 1: bool, 2: bool, 3: bool, 4: list<string>}> */
    private static function raw(): array
    {
        $carcade = \App\Http\Admin\OfferController::carcadeId();

        // Непроданная гаражная «платит менеджер» — только в «Гараже» (17.11.2026, владелец), во вкладках сделки её нет.
        // Раскладке нужны шаг и дело, а не строка: без кадров машин (`offer.media`) — с ними выборка 360 сделок шла
        // полсекунды вместо 0,1 с, и это была главная цена пересчёта после каждого события (17.11.2026).
        return StaffCache::remember(self::CACHE, 30, fn () => self::rows(Deal::active()->whereHas('offer', fn ($o) => $o->where('offers.state', OfferState::Sold))
            ->with(array_values(array_diff(self::WITH, ['offer.media'])))->get())->reject(fn (array $r) => $r['deal']->sellsFromGarage($r['deal']->offer?->position()?->stage))
            // Тихое дело (заявка Каркаде, `Position::todo` `quiet`) — во вкладке, но не в её счётчике.
            // Шаг просрочен (`Position::isOverdue`) — горящее для «только горящего» ответственного (`Responsibility`, 26.11.2026).
            // У «Ждём оплату» — за что долг (`payingParts`).
            ->mapWithKeys(fn (array $r) => [$r['deal']->offer_id => [$r['tab'], $r['todo'] !== null && empty($r['todo']['quiet']),
                $carcade !== null && $r['deal']->offer?->vendor_id === $carcade, (bool) $r['deal']->offer?->position()?->isOverdue(),
                $r['tab'] === 'paying' ? self::payingParts($r['deal']) : []]])->all());
    }

    /**
     * За что менеджер должен на шаге оплаты (26.11.2026): `car` — за ТС, `fee` — за подбор, оба — подбор внутри счёта за ТС
     * (`DealDebt`: «за ТС и подбор»). Не понять — за ТС: на шаге оплаты ждём платёж за машину.
     *
     * @return list<'car'|'fee'>
     */
    public static function payingParts(Deal $deal): array
    {
        $what = \App\Billing\DealDebt::of($deal)?->what ?? '';
        $parts = array_values(array_filter([str_contains($what, 'ТС') ? 'car' : null, str_contains($what, 'подбор') ? 'fee' : null]));

        return $parts ?: ['car'];
    }

    /** Машины «Ждём оплату ТС» (`car`) или «Ждём оплату подбора» (`fee`) с шага оплаты; carcade — и сделки Каркаде. @return list<int> */
    public static function paying(string $part, bool $carcade = false): array
    {
        return array_keys(array_filter($carcade ? self::raw() : self::map(), fn (array $v) => $v[0] === 'paying' && in_array($part, $v[4] ?? ['car'], true)));
    }

    /**
     * Наше дело отправит реквизиты покупателя — письмо с реквизитами (платит покупатель), счёт за ТС на него, договор, — а этот их
     * вид сотрудник не проверил (`DealContract::needsBuyerCheck`, 26.11.2026).
     */
    private static function verifies(Deal $deal, ?array $todo): bool
    {
        $key = $todo['key'] ?? '';
        if (! in_array($key, ['letter:requisites', 'gap:send', 'contract:paid', 'contract:unpaid'], true) || ! $deal->contract?->needsBuyerCheck()) {
            return false;
        }

        return match ($key) {
            'letter:requisites' => ! $deal->schemeOf()->paysCar() && ! $deal->isGarageUs(),
            'gap:send' => $deal->invoices->contains(fn ($i) => $i->kind === \App\Billing\ChargeKind::Sale && $i->direction === 'issued' && ! $i->shown_at
                && $i->state === \App\Billing\InvoiceState::Issued && $i->party_id === $deal->contract->buyerParty()?->id),
            default => true,
        };
    }

    /** Машины идущих сделок с просроченным шагом. @return list<int> */
    public static function overdueIds(): array
    {
        return array_keys(array_filter(self::map(), fn (array $v) => $v[3] ?? false));
    }

    /** Машины вкладки (`OfferController::scopeFor`); carcade — и сделки Каркаде. @return list<int> */
    public static function ids(string $tab, bool $carcade = false): array
    {
        return array_keys(array_filter($carcade ? self::raw() : self::map(), fn (array $v) => $v[0] === $tab));
    }

    /** Сколько ждёт нас по вкладкам. @return array<string, int> */
    public static function ours(): array
    {
        return collect(self::map())->filter(fn (array $v) => $v[1] && ! in_array($v[0], self::WAITING, true))->countBy(fn (array $v) => $v[0])->all();
    }

    public static function forget(): void
    {
        StaffCache::bump(StaffCache::TABS);
    }

    /** Связи, что нужны делу шага (`Position::todo`) и строкам. */
    public const WITH = ['offer', 'offer.vendor', 'offer.media', 'offer.positions.stage.block', 'offer.positions.stage.exits.to', 'offer.pendingReprice',
        'buyer', 'openRequirement', 'invoices.claims', 'invoices.party', 'contract.buyer.party.media', 'garageCar'];

    /** Группы сделок без нашего дела, по порядку. */
    public const WAITS = ['answer' => 'Ждём ответа', 'buyer' => 'Ждём данные покупателя', 'originals' => 'Документы в пути', 'manager' => 'Ждём менеджера', 'supplier' => 'Ждём поставщика', 'transport' => 'Везёт наша логистика', 'other' => 'Прочее'];

    /** Ждём ответа менеджера на шаге согласования, а не на оплате, получении, договоре или расчёте (у них свои вкладки). */
    private static function asksManager(?Position $position, string $block): bool
    {
        return $position?->waitsFor() === WaitsFor::Manager && ! in_array($block, ['invoice', 'paying', 'pickup', 'docs', 'payout', 'letter'], true);
    }

    /** Заголовок группы ожидания по ключу `waits`. */
    public static function waitTitle(string $key): string
    {
        return str_contains($key, ':') ? self::WAIT_STAGES[explode(':', $key, 2)[1]] : self::WAITS[$key];
    }

    /** Кого ждём по сделке без нашего дела (`WAITS`): ход наш, но ТС везёт логистика (`Position::carried`), — своя группа. */
    public static function waits(Deal $deal): string
    {
        $position = $deal->offer?->position();

        $ask = ExtraAsk::holding($deal);

        return match (true) {
            // Доп. просьба: ждём ответа поставщика или решения менеджера (18.11.2026).
            $ask !== null => $ask->phase() === ExtraAsk::MANAGER ? 'manager' : 'supplier',
            // Менеджер думает про новую цену (`RepriceAsk`) — ждём его, какой бы шаг ни стоял (гаражная без шага переоценки).
            RepriceAsk::where('deal_id', $deal->id)->whereIn('state', RepriceAsk::OPEN)->exists() => 'manager',
            // Ждём реквизиты от менеджера на шаге письма (`Position::requisitesTodo`).
            ($position?->todo()['key'] ?? null) === 'requisites-asked' => 'answer',
            $position?->carried() === true => 'transport',
            // Оригиналы: документ у менеджера — ждём его, иначе документы в пути (26.11.2026).
            Originals::applies($position?->stage) => Originals::waits($deal),
            // Шаг оплаты без покупателя или его данных (26.11.2026, «Спросить менеджера»).
            self::awaitsBuyer($deal, $position) => 'buyer',
            // Вопрос менеджеру на шаге не из вкладок ожидания (оплата, получение, договор) — «Спросить менеджера», «Ждём ответа».
            self::asksManager($position, self::BLOCKS[$position?->stage?->block?->name ?? $position?->stage?->name ?? ''] ?? 'agree') => 'answer',
            $position?->waitsFor() === WaitsFor::Manager => 'manager',
            // Чего ждём от поставщика на шагах оплаты — своей группой («Ждём счёт», «Ждём подтверждение оплаты»).
            $position?->waitsFor() === WaitsFor::Supplier => isset(self::WAIT_STAGES[$position->stage?->name]) ? 'supplier:'.$position->stage->name : 'supplier',
            default => 'other',
        };
    }
}
