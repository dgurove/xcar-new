<?php

namespace App\Offers\Actions;

use App\Billing\Actions\SaveBuyerData;
use App\Billing\Party;
use App\Billing\PartyKind;
use App\Notifications\BuyerContractDataNotice;
use App\Offers\DealContract;
use App\Offers\OfferEventType;
use App\Support\Money;
use App\Support\Phone;
use App\Users\Actions\AddPerson;
use App\Users\Actions\IssueInvite;
use App\Users\Role;
use App\Users\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Данные ДКП (05.10.2026). Сотрудник по сканам страховой вносит продавца (`seller`: паспорт собственника) и ТС
 * (`vehicle`: госномер, СТС, (Э)ПТС, кузов, шасси — в машину; цена и город — в договор). Даты договора нет (15.11.2026,
 * владелец: «печатают без даты, вписывают рукой») — в документе линия. Менеджер — покупателя: своего (`buyer_id`), себя
 * (`me`) или нового (`new_buyer`: заводится в его «Покупателях» без пароля), с паспортом или реквизитами организации
 * (`buyer`, `SaveBuyerData`: физлицо, ИП или организация), и у ПРАЙМ — кто платит по счёту (`payer`). Счёт ПРАЙМ ставится тут же сам (`SyncDealInvoices`). Договор
 * собрался впервые — менеджеру «ДКП готов».
 */
final class SaveDealContract
{
    public function __construct(private SyncDealInvoices $invoices, private UpdateOffer $offers, private SettleContract $settle, private SaveBuyerData $buyerData, private UpdateDealMoney $money) {}

    public function __invoke(DealContract $contract, array $data, User $by): DealContract
    {
        $deal = $contract->deal;
        // У супер-покупателя покупатель по договору — он сам: другого он не выбирает и не заводит.
        if ($deal->isDirect()) {
            unset($data['new_buyer'], $data['buyer_id'], $data['payer']);
            $contract->buyer_user_id ??= $deal->buyer_id;
            $contract->payer = 'manager';
        }
        DB::transaction(function () use ($contract, $data, $by, $deal) {
            $wasBuyer = $contract->buyer_user_id;
            if (isset($data['seller'])) {
                $seller = $contract->seller ?? new Party(['kind' => PartyKind::Person]);
                $seller->fill(self::passport($data['seller']))->save();
                $contract->seller_party_id = $seller->id;
            }
            if (isset($data['vehicle'])) {
                $contract->fill(array_intersect_key($data['vehicle'], array_flip(['price', 'city'])));
                // Документы ТС — поля машины (одна запись на все дорожки), правка с историей в предложении.
                $car = array_intersect_key($data['vehicle'], DealContract::VEHICLE);
                $car && ($this->offers)($deal->offer, $car, $by, ['why' => 'ДКП']);
            }
            if (! empty($data['new_buyer'])) {
                $phone = Phone::normalize($data['new_buyer']['phone'] ?? null);
                if ($phone && User::where('phone', $phone)->exists()) {
                    throw ValidationException::withMessages(['new_buyer.phone' => 'С этим телефоном уже есть человек в xcar']);
                }
                $name = trim($data['buyer']['name'] ?? $data['buyer']['org'] ?? '');
                [$last, $first] = array_pad(preg_split('/\s+/u', $name, 2) ?: [], 2, null);
                $buyer = User::create(['name' => $name, 'last_name' => $last, 'first_name' => $first, 'phone' => $phone, 'roles' => [Role::Buyer],
                    'manager_id' => $deal->buyer_id, 'approved_at' => now(), 'approved_by' => $by->id, 'access' => []]);
                $contract->buyer_user_id = $buyer->id;
            } elseif (($data['buyer_id'] ?? null) === 'draft') {
                $contract->buyer_user_id = $this->fromDraft($contract, $data['buyer'] ?? [])->id;
                $contract->draft_party_id = null;
            } elseif (($data['buyer_id'] ?? null) === 'me') {
                // Покупатель — сам менеджер: он же и платит по счёту ПРАЙМ.
                [$contract->buyer_user_id, $contract->payer] = [$deal->buyer_id, 'manager'];
            } elseif (! empty($data['buyer_id'])) {
                $buyer = User::whereKey($data['buyer_id'])->where('manager_id', $deal->buyer_id)->first()
                    ?? throw ValidationException::withMessages(['buyer_id' => 'Это не ваш покупатель']);
                // Заведённый раньше «покупатель» — наше юрлицо (ООО «Р.О.С.» Корабельникова, 17.11.2026) — не покупатель.
                if ($buyer->party && ($ours = Party::ours($buyer->party->inn) ?? ($buyer->party->is_self ? $buyer->party->seller : null))) {
                    throw ValidationException::withMessages(['buyer_id' => Party::oursMessage($ours)]);
                }
                $contract->buyer_user_id = $buyer->id;
            }
            // Сменить покупателя нельзя, когда договор подписан или по счёту платили (`Deal::buyerLocked`, 26.11.2026).
            if ($wasBuyer && $contract->isDirty('buyer_user_id') && ($why = $deal->buyerLocked())) {
                throw ValidationException::withMessages(['buyer_id' => $why]);
            }
            // Покупатель по договору сменился, а платил прежний — платит новый: договор и счёт на одного (22.11.2026, владелец).
            // Плательщик «по умолчанию» (пусто) и есть покупатель по договору (`Deal::payerUser`, `SetDealPayer::current`) — его
            // сторону не пишем: она заводится заново с паспортом. «Платит другой» (сторона не из покупателей) остаётся.
            if ($contract->buyer_user_id && $contract->buyer_user_id !== $wasBuyer && ! $deal->isDirect() && $deal->payer_party_id) {
                $wasParty = $wasBuyer ? User::whereKey($wasBuyer)->value('party_id') : null;
                if ($wasParty && $deal->payer_party_id === $wasParty) {
                    $deal->forceFill(['payer_party_id' => null])->save();
                    $contract->payer = $contract->buyer_user_id === $deal->buyer_id ? 'manager' : 'buyer';
                }
            }
            // ДКП ПРАЙМ (14.11.2026): номер договора комиссии и ставка НДС — выбором сотрудника в карточке ДКП.
            if (array_key_exists('commission_no', $data)) {
                $contract->commission_no = filled($data['commission_no']) ? trim($data['commission_no']) : null;
            }
            if (array_key_exists('vat_rate', $data)) {
                $contract->vat_rate = $data['vat_rate'] === null ? null : (int) $data['vat_rate'];
            }
            // «Сформировать без прописки» (18.11.2026): счёт выходит без неё (`Party::invoiceGaps`), договор её требует как прежде.
            if (! empty($data['invoice_without_reg'])) {
                $contract->invoice_without_reg_at ??= now();
            }
            if (in_array($data['payer'] ?? null, ['buyer', 'manager'], true)) {
                $contract->payer = $data['payer'];
            }
            if (isset($data['buyer']) && ($buyer = $contract->buyer()->first())) {
                // Покупатель — сам менеджер-ИП или самозанятый: паспорт дописываем, вид его реквизитов для выплат не трогаем.
                ($this->buyerData)(Party::forUser($buyer), $data['buyer'], keepKind: $buyer->id === $deal->buyer_id);
            }
            // Выбрали другого, не сохранив черновик плитки, — черновик со сканом больше не нужен.
            if ($contract->isDirty('buyer_user_id') && $contract->buyer_user_id && $contract->draft_party_id) {
                $stale = $contract->draftParty;
                $contract->draft_party_id = null;
                DB::afterCommit(fn () => $stale && ! User::where('party_id', $stale->id)->exists() && rescue(fn () => $stale->delete(), report: false));
            }
            $contract->save();
            // Ничего из названного не тронули (кто платит, скан) — «ДКП изменён», а не пустое «ДКП: » (07.11.2026).
            $what = implode(', ', array_filter([
                isset($data['seller']) ? ($deal->isDkp() ? 'продавец' : 'собственник') : null, isset($data['vehicle']) ? 'ТС' : null,
                $contract->wasChanged('commission_no') ? 'договор комиссии '.($contract->commission_no ?? 'по номеру убытка') : null,
                $contract->wasChanged('vat_rate') ? ($contract->vat_rate ? 'НДС '.$contract->vat_rate.' %' : 'без НДС') : null, $contract->wasChanged('buyer_user_id') ? 'покупатель '.$contract->buyer?->name : null,
                isset($data['buyer']) && ! $contract->wasChanged('buyer_user_id') ? 'данные покупателя' : null,
                $contract->wasChanged('invoice_without_reg_at') ? 'счёт без прописки' : null,
            ]));
            $deal->offer->log(OfferEventType::Note, $by, ['text' => $what !== '' ? 'ДКП: '.$what : 'ДКП изменён']);
        });
        $picked = $contract->wasChanged('buyer_user_id');
        // Оформляет на себя (13.11.2026, владелец: «комиссию менеджера из счёта на оплату сразу вычитать»): вознаграждения не
        // было — доля менеджера от разницы (`Deal::defaultCommission`); пока по счетам не платили. Счёт и так за вычетом его.
        $fresh = $deal->fresh(['buyer', 'contract']);
        if ($picked && $fresh->selfBuy() && $fresh->moneyEditable() && $fresh->commission === null && ($commission = $fresh->defaultCommission()) !== null) {
            ($this->money)($fresh, $by, $commission, note: 'Оформляет на себя: вознаграждение '.Money::rub($commission));
        }
        // Сменили покупателя — счёт за ТС на прежнего, за который не платили, гаснет сразу (27.11.2026: новый без данных — счёта на
        // него ещё нет, а старый, неотправленный, считался готовым к отправке не тому человеку). Новый встанет сам, когда будут данные.
        if ($picked && ! $deal->isDirect()) {
            $party = $contract->fresh('buyer')->buyerParty();
            $deal->issuedInvoices()->where('kind', \App\Billing\ChargeKind::Sale)->where('state', \App\Billing\InvoiceState::Issued)
                ->with(['payments', 'claims'])->get()
                ->filter(fn ($i) => $i->party_id !== $party?->id && $i->claims->isEmpty() && $i->payments->every(fn ($p) => $p->source->isSoft() || $p->voided_at))
                ->each(fn ($i) => app(\App\Billing\Actions\VoidInvoice::class)($i, $by, 'Сменился покупатель'));
        }
        // Плательщик или его данные сменились — счёт ПРАЙМ встаёт сам (или перевыставляется, если за него не платили).
        ($this->invoices)($deal->fresh(['offer', 'buyer', 'contract.buyer']), $by);
        $contract = $contract->fresh(['seller', 'buyer.party', 'deal.offer', 'deal.buyer']);
        // Выбрали покупателя без паспорта — ему самому «Нужны данные для договора» (19.10.2026): вносит в кабинете.
        $buyer = $contract->buyer;
        if ($buyer && $picked && $buyer->id !== $deal->buyer_id && ! $buyer->party?->readyAsBuyer()) {
            $buyer->notify(new BuyerContractDataNotice($deal));
        }

        return ($this->settle)($contract);
    }

    /**
     * Новый покупатель из черновика плитки (21.11.2026, `Cabinet\DealController::buyerNew`): данные сохранили — только теперь он
     * встаёт в «Покупатели» менеджера, с контрагентом черновика (там уже скан паспорта). Имя — из ФИО или названия формы.
     */
    private function fromDraft(DealContract $contract, array $buyer): User
    {
        $party = $contract->draftParty;
        if (! $party || User::where('party_id', $party->id)->exists()) {
            throw ValidationException::withMessages(['buyer_id' => 'Данные нового покупателя устарели, выберите вид ещё раз']);
        }
        $name = trim(preg_replace('/\s+/u', ' ', (string) (($buyer['kind'] ?? null) === 'company' ? ($buyer['org'] ?? '') : ($buyer['name'] ?? ''))));
        // Реквизиты в карточке организации файлами (27.11.2026) — названия ещё нет: «Новый покупатель», имя встанет, когда внесём данные.
        $name = $name !== '' ? $name : AddPerson::UNNAMED;
        [$first, $last] = ($buyer['kind'] ?? null) === 'company' ? [$name, ''] : (SaveBuyerData::nameParts($name) ?? [$name, '']);
        $manager = $contract->deal->buyer;
        [$user] = app(AddPerson::class)(fn () => app(IssueInvite::class)->buyer($manager),
            ['first_name' => mb_substr($first, 0, 60), 'last_name' => mb_substr($last, 0, 60)], contact: true);
        $user->update(['party_id' => $party->id]);

        return $user;
    }

    /** Поля паспорта из набора формы — только они, пустые строки как null. */
    private static function passport(array $data): array
    {
        $fields = array_intersect_key($data, array_flip(['name', 'birth_at', 'birth_place', 'passport', 'passport_issued', 'passport_issued_at', 'passport_code', 'reg_address']));

        return array_map(fn ($v) => is_string($v) && trim($v) === '' ? null : (is_string($v) ? trim($v) : $v), $fields);
    }
}
