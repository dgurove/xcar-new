<?php

namespace App\Offers;

use App\Billing\Party;
use App\Billing\PartyKind;
use App\Billing\Seller;
use App\Cars\Car;
use App\Support\FastAttributes;
use App\Users\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Договор купли-продажи ТС (05.10.2026; по шаблонам владельца с 19.10.2026). Продавец по схеме: у ДКП — сам
 * собственник по СТС, его паспорт сотрудник переносит со сканов страховой (`seller`, физлицо); у ПРАЙМ и Р.О.С. — они
 * сами (`Party::seller`). Покупатель — из «Покупателей» менеджера (или сам менеджер), физлицо с паспортом или
 * организация, данные — у его контрагента (`users.party_id`). У ПРАЙМ ещё `payer` — кто платит по счёту: покупатель или
 * менеджер за вычетом вознаграждения. Всё про машину (марка, VIN, госномер, СТС, (Э)ПТС, кузов, шасси, ключи) — у
 * машины (`Cars\Car`), тут только цена, город, дата и номер. Вид документа — `form()`: `owner` — физлицо физлицу
 * (Т-Страхование), `prime` / `ros` — ДКП и акт от нашего юрлица; номер у юрлица свой за год (`NumberContract`).
 * ПРАЙМ с 14.11.2026 продаёт «за счёт и по поручению собственника» по договору комиссии (`commissioned`): собственник —
 * тот же `seller` (сам из договора комиссии Совкомбанка — `FillCommissionOwner`), номер — `commissionNo`. НДС договора —
 * выбором в CRM (`vatRate`).
 */
#[Fillable(['deal_id', 'seller_party_id', 'buyer_user_id', 'payer', 'price', 'city', 'signed_at', 'ready_at', 'issuer', 'number', 'year', 'commission_no', 'vat_rate', 'cash_part', 'car_price', 'supplier_price', 'supplier_purpose', 'supplier_requisites', 'shown_at', 'shown_by', 'code', 'numbered_on', 'edited_at', 'edited_by', 'edited_base', 'draft_party_id', 'buyer_checked_at', 'buyer_checked_by', 'buyer_checked_sum'])]
class DealContract extends Model
{
    use FastAttributes;

    /** Документы ТС для договора — поля машины, правит сотрудник в карточке ДКП. */

    public const VEHICLE = ['plate' => 'Госномер', 'sts' => 'СТС', 'pts' => 'ПТС или ЭПТС', 'body_no' => 'Кузов №', 'chassis_no' => 'Шасси (рама) №'];

    protected function casts(): array
    {
        return ['price' => 'int', 'signed_at' => 'date', 'ready_at' => 'datetime', 'issuer' => Seller::class, 'number' => 'int', 'year' => 'int', 'vat_rate' => 'int', 'supplier_price' => 'int', 'supplier_requisites' => 'array', 'shown_at' => 'datetime', 'numbered_on' => 'date', 'invoice_without_reg_at' => 'datetime', 'cash_part' => 'int', 'car_price' => 'int', 'edited_at' => 'datetime', 'buyer_checked_at' => 'datetime'];
    }

    /** Вид документа: физлицо физлицу (ДКП собственника) или ДКП с актом от нашего юрлица. */
    public function form(): string
    {
        return $this->deal->isDkp() ? 'owner' : $this->deal->seller()->value;
    }

    /** Номер для договора и акта: «20261116-01» (`ContractNumber`); не отправлен менеджеру — номера ещё нет, null. */
    public function numberLabel(): ?string
    {
        return $this->code;
    }

    /** Машина договора — одна запись на все дорожки. */
    public function car(): ?Car
    {
        return $this->deal->offer->car;
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'seller_party_id');
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_user_id');
    }

    /**
     * Новый покупатель, пока его данные не сохранили (21.11.2026): контрагент без аккаунта — поля и скан паспорта шторки «Новый
     * покупатель» (`Cabinet\DealController::buyerNew`). Человек из него — при сохранении (`SaveDealContract`).
     */
    public function draftParty(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'draft_party_id');
    }

    /**
     * Договор сделки: строка заводится при первом обращении. Цена — что получает собственник (ДКП), цена подтверждения
     * (ПРАЙМ) или закупочная (гаражная «платит менеджер» у ПРАЙМ: покупатель — сам менеджер).
     */
    public static function for(Deal $deal): self
    {
        // Договор знает свою сделку — ту, что на руках: иначе он перечитывал её, а с ней предложение, машину, марку и модель.
        if ($deal->contract) {
            return $deal->contract->setRelation('deal', $deal);
        }
        // Гаражная «платит менеджер» и сделка супер-покупателя — покупатель он сам, он же платит.
        $self = $deal->isGarageManager() || $deal->isDirect();
        $defaults = ['price' => self::priceFor($deal)] + ($self ? ['buyer_user_id' => $deal->buyer_id, 'payer' => 'manager'] : []);

        return tap(self::firstOrCreate(['deal_id' => $deal->id], $defaults), fn ($c) => $deal->setRelation('contract', $c->setRelation('deal', $deal)));
    }

    /**
     * Приложена ли карточка организации файлами к покупателю, которого сохраняет форма (27.11.2026): черновик плитки
     * (`buyer_id = draft`) — его контрагент, иначе покупатель по договору. Тогда поля ИП и организации не обязательны
     * (`PartyRules::passport(files:)`).
     */
    public static function cardFiled(Deal $deal, ?string $buyerId): bool
    {
        $contract = self::for($deal);
        $party = $buyerId === 'draft' ? $contract->draftParty : ($buyerId === null || $buyerId === '' ? $contract->buyer?->party : null);

        return (bool) $party?->getMedia('card')->isNotEmpty();
    }

    /** Цена договора по деньгам сделки: собственнику (ДКП), закупочная (гаражная), иначе — как счёт: цена подтверждения (у
     *  гаражной Совкомбанка — цена продажи, которую назвал менеджер) минус вознаграждение менеджера, у ПРАЙМ и у Р.О.С.
     *  (18.11.2026, владелец: «цена минус вознаграждение!!! В ДКП ПРАЙМ, Р.О.С. тоже!!!»). */
    public static function priceFor(Deal $deal): ?int
    {
        return match (true) {
            // Покупатель с гаражом — цена продажи, которую назначил он или мы (`SetGarageSalePrice`, 26.11.2026, владелец: «он не
            // должен нигде видеть цену закупа»; с 24.11 была его цена гаража — она часто и есть закупочная).
            // С 27.11.2026 — за вычетом его расходов и вознаграждения (`Deal::holderNet`); счёт уже оплачен — сумма оплаты.
            $deal->forGarageBuyer() => ($paid = $deal->issuedInvoices()->where('kind', \App\Billing\ChargeKind::Sale)->where('state', \App\Billing\InvoiceState::Paid)->value('total')) !== null
                ? (int) round((float) $paid) : $deal->holderNet(),
            $deal->isDkp() => $deal->ownerPrice(),
            $deal->isGarage() && ! $deal->namesBuyer() => $deal->cost,
            // ПРАЙМ / Р.О.С. с известными деньгами — сколько на счёт за ТС (`Deal::carPrice`, вписывается с 25.11.2026), остальное
            // подбором по ссылке.
            ($car = $deal->carPrice()) !== null => $car,
            // Цена продажи минус вознаграждение и часть, отданная наличными мимо счёта (18.11.2026, владелец: сумму договора путали
            // с ценой продажи; «сумма договора меняется в редких случаях, когда часть наличными отдали» — `cash_part`).
            $deal->amount !== null => max(0, (int) $deal->amount - (int) $deal->commission - (int) $deal->contract?->cash_part),
            default => null,
        };
    }

    /**
     * ПРАЙМ продаёт по договору комиссии: у вендора по комиссии (Совкомбанк) всегда, у остальных — если собственника вписали.
     * Нет — в договоре нет куска «за счёт и по поручению собственника … на основании Договора комиссии» (Каркаде).
     */
    public function commissioned(): bool
    {
        return $this->form() === Seller::Prime->value && ($this->deal->isCommission() || $this->seller_party_id !== null);
    }

    /** Номер договора комиссии: вписанный (или разобранный из файла), у вендора по комиссии — номер убытка, как у Совкомбанка. */
    public function commissionNo(): ?string
    {
        return $this->commission_no ?: ($this->deal->isCommission() ? ($this->deal->offer->claim_ref ?: null) : null);
    }

    /**
     * Ставка НДС договора и счёта за ТС — одна дверь (20.11.2026, бухгалтер: «НДС всегда на всю сумму»): выбранная в CRM
     * (`Vat::RATES`); не выбрана — по схеме:
     * — ПРАЙМ продаёт по договору комиссии (`commissioned`: Совкомбанк, вписан собственник) — НДС того, у кого забираем машину,
     *   `offers.supplier_vat` (письмо, документ, вендор, рука; не знаем — без НДС, владелец);
     * — ПРАЙМ сам покупает и перепродаёт (Каркаде «Оптом») — ставка ПРАЙМ, 5 %;
     * — Р.О.С. — его ставка (22 %); ДКП собственника — без НДС.
     */
    public function vatRate(): int
    {
        if ($this->form() === 'owner') {
            return 0;
        }
        if ($this->vat_rate !== null) {
            return $this->vat_rate;
        }
        $seller = $this->deal->seller();
        if ($seller !== Seller::Prime) {
            return $seller->vatRate();
        }

        return $this->commissioned() ? (int) $this->deal->offer->supplier_vat : $seller->vatRate();
    }

    /** Откуда ставка, когда её не выбрали руками: «как у поставщика, по письму 12.11» / «НДС поставщика не указан» / null. */
    public function vatSource(): ?string
    {
        if ($this->vat_rate !== null || $this->form() === 'owner' || $this->deal->seller() !== Seller::Prime) {
            return null;
        }
        if (! $this->commissioned()) {
            return 'ПРАЙМ перепродаёт';
        }
        $offer = $this->deal->offer;
        if ($offer->supplier_vat === null) {
            return 'НДС поставщика не указан';
        }

        return 'как у поставщика'.match ($offer->supplier_vat_source) {
            'letter' => ', по письму', 'document' => ', по документу', 'vendor' => ', как у вендора', default => '',
        }.($offer->supplier_vat_at && in_array($offer->supplier_vat_source, ['letter', 'document'], true) ? ' '.$offer->supplier_vat_at->format('d.m') : '');
    }

    /**
     * Собственник для договора комиссии: ФИО и серия с номером паспорта, у ИП и организации — имя и ИНН (ОГРН печатается, если
     * есть: в договорах комиссии Совкомбанка его бывает нет); больше шаблон о нём не пишет.
     */
    public function ownerReady(): bool
    {
        $s = $this->seller;

        return filled($s?->name) && ($s->kind === PartyKind::Person ? filled($s->passport) : filled($s->inn));
    }

    /** Продавец: у ДКП — собственник по СТС (вносит сотрудник), у ПРАЙМ — сам ПРАЙМ. */
    public function sellerParty(): ?Party
    {
        return $this->deal->isDkp() ? $this->seller : Party::seller($this->deal->seller());
    }

    public function buyerParty(): ?Party
    {
        return $this->buyer?->party;
    }

    /** Поля стороны, что уходят в счёт, договор и письмо с реквизитами: их отпечаток проверяет сотрудник (`buyerSum`). */
    private const CHECKED_FIELDS = ['kind', 'name', 'inn', 'kpp', 'ogrn', 'legal_address', 'director', 'director_basis', 'full_name', 'passport', 'passport_issued',
        'passport_issued_at', 'passport_code', 'reg_address', 'birth_at', 'birth_place', 'bank_name', 'bik', 'account', 'corr_account'];

    /**
     * Отпечаток реквизитов покупателя (26.11.2026, проверка сотрудником — `Actions\CheckBuyer`): сторона, её поля и страницы скана.
     * Поменялось что-то из этого (менеджер поправил, скан распознался заново) — проверка снова нужна. Покупателя нет — null.
     */
    public function buyerSum(): ?string
    {
        $party = $this->buyerParty();
        if (! $party) {
            return null;
        }
        $fields = array_map(fn ($v) => $v === null ? null : (string) $v, array_intersect_key($party->getAttributes(), array_flip(self::CHECKED_FIELDS)));
        ksort($fields);

        // И файлы карточки организации (27.11.2026): приложили новые — проверить снова.
        return sha1(json_encode([$party->id, $fields, $party->getMedia('passport')->pluck('id')->sort()->values()->all(),
            ...($party->getMedia('card')->isNotEmpty() ? [$party->getMedia('card')->pluck('id')->sort()->values()->all()] : [])]));
    }

    /** Реквизиты покупателя проверены сотрудником в этом виде. */
    public function buyerChecked(): bool
    {
        return $this->buyer_checked_sum !== null && $this->buyer_checked_sum === $this->buyerSum();
    }

    /**
     * Нужна проверка (26.11.2026, владелец: «без её «Всё верно» реквизиты не уходят поставщику и не отправляются счёт и договор»):
     * у сделки есть покупатель с данными (хоть для счёта, хоть для договора), а этот их вид сотрудник ещё не проверил.
     */
    public function needsBuyerCheck(): bool
    {
        $party = $this->buyerParty();

        return $party !== null && ($party->readyForContract() || $party->readyForInvoice()) && ! $this->buyerChecked();
    }

    /**
     * По счёту ПРАЙМ платит сам менеджер — он же покупатель или нет. Плательщик выбран
     * своей стороной (`deals.payer_party_id`, `SetDealPayer`, 16.11.2026) — менеджер, только если это его сторона.
     */
    public function managerPays(): bool
    {
        if ($this->deal->payer_party_id) {
            return $this->deal->buyer !== null && $this->deal->payer_party_id === $this->deal->buyer->party_id;
        }

        return $this->payer === 'manager' || ($this->buyer_user_id !== null && $this->buyer_user_id === $this->deal->buyer_id);
    }

    /** Чего не хватает договору — словами для карточки: «данные продавца», «покупатель», «паспорт покупателя». @return list<string> */
    public function missing(): array
    {
        return array_values(array_filter([
            $this->deal->isDkp() && ! $this->seller?->readyForContract() ? 'данные продавца' : null,
            $this->commissioned() && ! $this->ownerReady() ? 'данные собственника' : null,
            // ДКП от Р.О.С. — продавец Р.О.С.: без его реквизитов (пришлёт владелец) договор не печатаем с прочерками.
            $this->deal->seller() === Seller::Ros && ! Party::seller(Seller::Ros)->readyForContract() ? 'данные продавца' : null,
            // (Э)ПТС стоит в обоих видах договора: у собственника — ещё и основание права собственности.
            ! $this->car()?->pts ? '(Э)ПТС' : null,
            $this->buyer ? ($this->buyerParty()?->readyForContract() ? null : 'данные покупателя') : 'покупатель',
            // Скан паспорта покупателя-физлица (11.10.2026): без него договор не печатается.
            $this->buyer && $this->buyerParty()?->readyForContract() && ! $this->buyerParty()->readyAsBuyer() ? 'скан паспорта покупателя' : null,
            $this->price ? null : 'цена',
        ]));
    }

    public function isReady(): bool
    {
        return $this->missing() === [];
    }

    /**
     * Можно отправлять менеджеру: собран из данных сделки или приложен заполненный руками (23.11.2026, владелец: «скачать шаблон,
     * заполнить, приложить и отправить» — и когда данных продавца, ПТС или покупателя нет). Заполненный файл и есть договор.
     */
    public function sendable(): bool
    {
        return $this->isReady() || ContractEdit::has($this->deal);
    }

    /**
     * Слово у подписи «Договор» (19.11.2026; одна дверь для «Денег» и шага договора в «Ходе сделки», 27.11.2026): подписан, отправлен,
     * не отправлен или чего-то не хватает; `null` — договора у сделки нет. @return array{0: string, 1: string}|null
     */
    public static function word(Deal $deal, ?self $contract, bool $signed): ?array
    {
        return match (true) {
            $signed => ['подписан', 'ok'],
            $contract?->shown_at !== null => ['отправлен '.\App\Support\Ago::day($contract->shown_at), 'ok'],
            $contract && (ContractEdit::has($deal) || ! $contract->missing()) => ['не отправлен', 'bad'],
            $contract !== null && $deal->isActive() => ['нужны данные', 'bad'],
            default => null,
        };
    }

    /** Менеджеру договор виден: можно отправлять и отправлен кнопкой «Отправить менеджеру» в CRM (16.11.2026); иначе — «Договор готовится». */
    public function shownToManager(): bool
    {
        return $this->shown_at !== null && $this->sendable();
    }
}
