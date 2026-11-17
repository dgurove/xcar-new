<?php

namespace App\Offers\Actions;

use App\Billing\ChargeKind;
use App\Billing\Invoice;
use App\Billing\InvoiceState;
use App\Billing\Party;
use App\Billing\PartyKind;
use App\Billing\Acquiring\PayLink;
use App\Offers\Deal;
use App\Offers\DealContract;
use App\Offers\OfferEventType;
use App\Support\Phone;
use App\Users\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Плательщик счёта сделки за ТС (16.11.2026, владелец: «у счёта тоже необязательно менеджер плательщик, любое лицо может
 * оплатить»; с 18.11.2026 — только у ДКП ПРАЙМ / Р.О.С.: «плательщик указывается только для выставления договора счёта (ДКП), для
 * комиссий нет»). Выбор строкой «Плательщик» над счётом (`x-deal.payer`, CRM и страница сделки менеджера):
 * - `manager` — сам менеджер (как по умолчанию: счёт с разбивкой и удержанием вознаграждения);
 * - `user:{id}` — его покупатель;
 * - `party:{id}` — сторона, что уже платила или была плательщиком по этой сделке;
 * - `new` — кто угодно: физлицо, ИП или организация (организацию и ИП с тем же ИНН берём существующими).
 * Ложится в `deals.payer_party_id`, счёт перевыставляется на него (`SyncDealInvoices`). По счёту уже платили — смены нет.
 */
final class SetDealPayer
{
    public const KINDS = ['person', 'ip', 'company'];

    public function __construct(private SyncDealInvoices $sync) {}

    /** Правила формы — одни на CRM и сайт. */
    public static function rules(): array
    {
        return [
            'payer' => ['required', 'string', 'max:40', 'regex:/^(manager|new|user:\d+|party:\d+)$/'],
            'kind' => ['exclude_unless:payer,new', 'required', Rule::in(self::KINDS)],
            'name' => ['exclude_unless:payer,new', 'required', 'string', 'max:200'],
            'inn' => ['exclude_unless:payer,new', 'nullable', 'required_if:kind,ip,company', 'regex:/^\d{10}(\d{2})?$/'],
            'phone' => ['exclude_unless:payer,new', 'nullable', 'string', 'max:40'],
            'email' => ['exclude_unless:payer,new', 'nullable', 'email', 'max:120'],
        ];
    }

    public static function messages(): array
    {
        return ['name.required' => 'Укажите, кто платит', 'inn.required_if' => 'Нужен ИНН', 'inn.regex' => 'ИНН — 10 или 12 цифр', 'email.email' => 'Проверьте почту'];
    }

    /** Счёт с плательщиком — только счёт сделки за ТС (18.11.2026, владелец: «для комиссий нет»). */
    public static function follows(Invoice $invoice): bool
    {
        return $invoice->deal_id !== null && $invoice->kind === ChargeKind::Sale && $invoice->direction === 'issued';
    }

    /** Счета сделки, у которых есть плательщик (за ТС), — живые. */
    public static function invoices(Deal $deal)
    {
        return $deal->shownInvoices()->filter(fn (Invoice $i) => $i->kind === ChargeKind::Sale
            && $i->direction === 'issued' && $i->state !== InvoiceState::Void)->values();
    }

    /** По счёту уже платили (или менеджер сообщил об оплате) — плательщика не меняем: деньги пришли от него. */
    public static function locked(Deal $deal): bool
    {
        return self::invoices($deal)->contains(fn (Invoice $i) => $i->claims->isNotEmpty() || $i->payments->contains(fn ($p) => ! $p->source->isSoft()));
    }

    /**
     * Кого можно выбрать строками (кроме «Другой плательщик»): менеджер, покупатель по договору, остальные его покупатели,
     * сторонние, что уже стояли плательщиком этой сделки. @return list<array{value: string, party: ?Party, user: ?User, sub: string}>
     */
    public static function options(Deal $deal): array
    {
        $manager = $deal->buyer;
        if (! $manager) {
            return [];
        }
        $contractBuyer = $deal->contract?->buyer;
        $out = [['value' => 'manager', 'user' => $manager, 'party' => null, 'sub' => 'менеджер']];
        $buyers = \App\Support\People::buyersOf($manager);
        if ($contractBuyer && $contractBuyer->id !== $manager->id) {
            $buyers = $buyers->reject(fn ($b) => $b->id === $contractBuyer->id)->prepend($contractBuyer);
        }
        foreach ($buyers as $b) {
            $out[] = ['value' => 'user:'.$b->id, 'user' => $b, 'party' => null, 'sub' => $contractBuyer?->id === $b->id ? 'покупатель по договору' : 'покупатель'];
        }
        $known = collect($out)->map(fn ($o) => $o['user']?->party_id)->filter()->all();
        $parties = Party::whereIn('id', array_filter([$deal->payer_party_id, ...Invoice::where('deal_id', $deal->id)->whereIn('kind', [ChargeKind::Sale, ChargeKind::Selection])->pluck('party_id')->all()]))
            ->whereNotIn('id', $known)->where('is_self', false)->get();
        foreach ($parties as $p) {
            $out[] = ['value' => 'party:'.$p->id, 'user' => null, 'party' => $p, 'sub' => match ($p->kind) {
                PartyKind::Company => 'организация'.($p->inn ? ', ИНН '.$p->inn : ''),
                PartyKind::Entrepreneur => 'ИП'.($p->inn ? ', ИНН '.$p->inn : ''),
                default => 'физлицо',
            }];
        }

        return $out;
    }

    /** Какой строкой стоит нынешний плательщик. */
    public static function current(Deal $deal): string
    {
        if (! $deal->payer_party_id) {
            // Плательщик по умолчанию (`Deal::payerUser`: покупатель по договору у продажи ПРАЙМ и у подбора, где покупатель
            // платит поставщику сам).
            $user = $deal->payerUser($deal->isPrime());

            return $user && $user->id !== $deal->buyer_id ? 'user:'.$user->id : 'manager';
        }
        $user = User::where('party_id', $deal->payer_party_id)->first();

        return match (true) {
            $user && $user->id === $deal->buyer_id => 'manager',
            $user && $user->manager_id === $deal->buyer_id => 'user:'.$user->id,
            default => 'party:'.$deal->payer_party_id,
        };
    }

    public function __invoke(Deal $deal, User $by, array $data): Party
    {
        return DB::transaction(function () use ($deal, $by, $data) {
            $deal = Deal::whereKey($deal->id)->lockForUpdate()->firstOrFail();
            if (! $deal->buyer) {
                throw ValidationException::withMessages(['payer' => 'У сделки нет менеджера']);
            }
            if (self::locked($deal)) {
                throw ValidationException::withMessages(['payer' => 'По счёту уже платили — плательщика не поменять']);
            }
            $choice = (string) $data['payer'];
            // Наше юрлицо плательщиком не бывает — это схема «Через Р.О.С.» (17.11.2026); новое — до заведения стороны.
            if ($choice === 'new' && ($ours = Party::ours($data['inn'] ?? null))) {
                throw ValidationException::withMessages(['inn' => Party::oursMessage($ours)]);
            }
            [$party, $user] = match (true) {
                $choice === 'manager' => [Party::forUser($deal->buyer), $deal->buyer],
                str_starts_with($choice, 'user:') => $this->buyer($deal, (int) substr($choice, 5)),
                str_starts_with($choice, 'party:') => [$this->known($deal, (int) substr($choice, 6)), null],
                default => [$this->fresh($data), null],
            };
            if ($choice !== 'manager' && $party->isOurs()) {
                throw ValidationException::withMessages(['payer' => Party::oursMessage(Party::ours($party->inn) ?? $party->seller ?? \App\Billing\Seller::Ros)]);
            }
            // Своей стороной всегда, и менеджер тоже (17.11.2026): выбор «менеджер» должен держаться (`Deal::managerPays` узнаёт его по
            // стороне менеджера).
            $deal->forceFill(['payer_party_id' => $party->id])->save();
            // Договор ПРАЙМ смотрит на `payer` (кто платит — менеджер или покупатель): держим в согласии.
            if ($contract = $deal->contract) {
                $contract->forceFill(['payer' => $user?->id === $deal->buyer_id ? 'manager' : 'buyer'])->save();
            }
            $deal->unsetRelation('contract')->unsetRelation('payer');
            $deal->offer?->log(OfferEventType::Note, $by, ['text' => 'Плательщик: '.$party->name]);
            ($this->sync)($deal->fresh(), $by);

            return $party;
        });
    }

    /** @return array{Party, User} */
    private function buyer(Deal $deal, int $id): array
    {
        $user = User::whereKey($id)->where(fn ($q) => $q->where('manager_id', $deal->buyer_id)->orWhere('id', $deal->contract?->buyer_user_id ?? 0))->first();
        if (! $user) {
            throw ValidationException::withMessages(['payer' => 'Это не покупатель менеджера']);
        }

        return [Party::forUser($user), $user];
    }

    private function known(Deal $deal, int $id): Party
    {
        $ok = $id === $deal->payer_party_id || Invoice::where('deal_id', $deal->id)->where('party_id', $id)->exists();
        $party = $ok ? Party::where('is_self', false)->find($id) : null;
        if (! $party) {
            throw ValidationException::withMessages(['payer' => 'Такого плательщика у сделки нет']);
        }

        return $party;
    }

    /** Новый плательщик: ИП и организация с тем же ИНН — уже заведённые, физлицо — новая сторона. */
    private function fresh(array $data): Party
    {
        $kind = match ($data['kind'] ?? 'person') {
            'company' => PartyKind::Company,
            'ip' => PartyKind::Entrepreneur,
            default => PartyKind::Person,
        };
        $name = PayLink::squash((string) $data['name']);
        $inn = preg_replace('/\D+/', '', (string) ($data['inn'] ?? '')) ?: null;
        $phone = Phone::normalize($data['phone'] ?? null);
        $email = filled($data['email'] ?? null) ? mb_strtolower(trim($data['email'])) : null;
        $known = $inn ? Party::where('is_self', false)->where('inn', $inn)->orderBy('id')->first() : null;
        if ($known) {
            $known->fill(array_filter(['phone' => $known->phone ?: $phone, 'email' => $known->email ?: $email]))->save();

            return $known;
        }

        return Party::create(['kind' => $kind, 'name' => $name, 'inn' => $inn, 'phone' => $phone, 'email' => $email]);
    }
}
