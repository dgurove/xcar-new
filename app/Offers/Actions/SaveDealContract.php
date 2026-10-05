<?php

namespace App\Offers\Actions;

use App\Billing\Party;
use App\Billing\PartyKind;
use App\Notifications\ContractReadyNotice;
use App\Offers\DealContract;
use App\Offers\OfferEventType;
use App\Support\Phone;
use App\Users\Role;
use App\Users\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Данные ДКП (05.10.2026). Сотрудник по сканам страховой вносит продавца (`seller`: паспорт собственника) и ТС
 * (`vehicle`: госномер, СТС, ПТС, номера агрегатов, цена, город). Менеджер — покупателя: своего (`buyer_id`), себя
 * (`me`) или нового (`new_buyer`: заводится в его «Покупателях» без пароля), с паспортом или реквизитами организации
 * (`buyer`), и у ПРАЙМ — кто платит по счёту (`payer`). Счёт ПРАЙМ ставится тут же сам (`SyncDealInvoices`). Договор
 * собрался впервые — менеджеру «ДКП готов».
 */
final class SaveDealContract
{
    public function __construct(private SyncDealInvoices $invoices) {}

    public function __invoke(DealContract $contract, array $data, User $by): DealContract
    {
        $deal = $contract->deal;
        DB::transaction(function () use ($contract, $data, $by, $deal) {
            if (isset($data['seller'])) {
                $seller = $contract->seller ?? new Party(['kind' => PartyKind::Person]);
                $seller->fill(self::passport($data['seller']))->save();
                $contract->seller_party_id = $seller->id;
            }
            if (isset($data['vehicle'])) {
                $contract->fill(array_intersect_key($data['vehicle'], array_flip([...array_keys(DealContract::VEHICLE), 'price', 'city'])));
            }
            if (! empty($data['new_buyer'])) {
                $phone = Phone::normalize($data['new_buyer']['phone'] ?? null);
                if ($phone && User::where('phone', $phone)->exists()) {
                    throw ValidationException::withMessages(['new_buyer.phone' => 'С этим телефоном уже есть человек в xcar']);
                }
                $name = trim($data['buyer']['name'] ?? '');
                [$last, $first] = array_pad(preg_split('/\s+/u', $name, 2) ?: [], 2, null);
                $buyer = User::create(['name' => $name, 'last_name' => $last, 'first_name' => $first, 'phone' => $phone, 'roles' => [Role::Buyer],
                    'manager_id' => $deal->buyer_id, 'approved_at' => now(), 'approved_by' => $by->id, 'access' => []]);
                $contract->buyer_user_id = $buyer->id;
            } elseif (($data['buyer_id'] ?? null) === 'me') {
                // Покупатель — сам менеджер: он же и платит по счёту ПРАЙМ.
                [$contract->buyer_user_id, $contract->payer] = [$deal->buyer_id, 'manager'];
            } elseif (! empty($data['buyer_id'])) {
                $buyer = User::whereKey($data['buyer_id'])->where('manager_id', $deal->buyer_id)->first()
                    ?? throw ValidationException::withMessages(['buyer_id' => 'Это не ваш покупатель']);
                $contract->buyer_user_id = $buyer->id;
            }
            if (in_array($data['payer'] ?? null, ['buyer', 'manager'], true)) {
                $contract->payer = $data['payer'];
            }
            if (isset($data['buyer']) && ($buyer = $contract->buyer()->first())) {
                $party = Party::forUser($buyer);
                $company = ($data['buyer']['kind'] ?? 'person') === 'company';
                // Покупатель — сам менеджер-ИП или самозанятый: паспорт дописываем, вид его реквизитов для выплат не трогаем.
                $keep = $buyer->id === $deal->buyer_id && in_array($party->kind, [PartyKind::Entrepreneur, PartyKind::SelfEmployed], true);
                $party->fill(($keep ? [] : ['kind' => $company ? PartyKind::Company : PartyKind::Person]) + ($company ? self::company($data['buyer']) : self::passport($data['buyer'])))->save();
            }
            $contract->save();
            $deal->offer->log(OfferEventType::Note, $by, ['text' => 'ДКП: '.implode(', ', array_filter([
                isset($data['seller']) ? 'продавец' : null, isset($data['vehicle']) ? 'ТС' : null, $contract->wasChanged('buyer_user_id') ? 'покупатель '.$contract->buyer?->name : null,
                isset($data['buyer']) && ! $contract->wasChanged('buyer_user_id') ? 'паспорт покупателя' : null,
            ]))]);
        });
        // Плательщик или его данные сменились — счёт ПРАЙМ встаёт сам (или перевыставляется, если за него не платили).
        ($this->invoices)($deal->fresh(['offer', 'buyer', 'contract.buyer']), $by);
        $contract = $contract->fresh(['seller', 'buyer.party', 'deal.offer', 'deal.buyer']);
        if (! $contract->ready_at && $contract->isReady()) {
            $contract->update(['ready_at' => now()]);
            $contract->deal->buyer?->notify(new ContractReadyNotice($contract->deal));
        }

        return $contract;
    }

    /** Реквизиты организации из набора формы. */
    private static function company(array $data): array
    {
        $fields = array_intersect_key($data, array_flip(['name', 'inn', 'kpp', 'ogrn', 'legal_address', 'director']));

        return array_map(fn ($v) => is_string($v) && trim($v) === '' ? null : (is_string($v) ? trim($v) : $v), $fields);
    }

    /** Поля паспорта из набора формы — только они, пустые строки как null. */
    private static function passport(array $data): array
    {
        $fields = array_intersect_key($data, array_flip(['name', 'birth_at', 'birth_place', 'passport', 'passport_issued', 'passport_issued_at', 'passport_code', 'reg_address']));

        return array_map(fn ($v) => is_string($v) && trim($v) === '' ? null : (is_string($v) ? trim($v) : $v), $fields);
    }
}
