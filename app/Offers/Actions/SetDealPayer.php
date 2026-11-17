<?php

namespace App\Offers\Actions;

use App\Billing\ChargeKind;
use App\Billing\Invoice;
use App\Billing\InvoiceState;
use App\Billing\Party;
use App\Billing\PartyKind;
use App\Billing\Acquiring\Actions\CreatePayLink;
use App\Billing\Acquiring\PayerKind;
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
 * Плательщик сделки — один на счёт и ссылку (16.11.2026, владелец: «это два способа оплаты одной и той же суммы, а не два
 * разных платежа»; «у счёта тоже необязательно менеджер плательщик, любое лицо может оплатить»). Выбор строкой «Плательщик»
 * над счётом (`x-deal.payer`, CRM и страница сделки менеджера):
 * - `manager` — сам менеджер (как по умолчанию: счёт с разбивкой и удержанием вознаграждения);
 * - `user:{id}` — его покупатель;
 * - `party:{id}` — сторона, что уже платила или была плательщиком по этой сделке;
 * - `new` — кто угодно: физлицо, ИП или организация (организацию и ИП с тем же ИНН берём существующими).
 * Ложится в `deals.payer_party_id`, счёт перевыставляется на него (`SyncDealInvoices`): прежний аннулируется вместе со
 * ссылкой, у нового ссылка на того же плательщика (`EnsurePayLink`). По счёту уже платили — смены нет.
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

    /** Ссылка этого счёта — на плательщика счёта, своего выбора у неё нет: счёт сделки за ТС или подбор. */
    public static function follows(Invoice $invoice): bool
    {
        return $invoice->deal_id !== null && in_array($invoice->kind, [ChargeKind::Sale, ChargeKind::Selection], true) && $invoice->direction === 'issued';
    }

    /** Счета сделки, у которых есть плательщик (продажа, подбор), — живые. */
    public static function invoices(Deal $deal)
    {
        return $deal->invoices()->whereIn('kind', [ChargeKind::Sale, ChargeKind::Selection])->where('direction', 'issued')
            ->where('state', '!=', InvoiceState::Void)->with(['payments', 'claims', 'party'])->get();
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
            [$party, $user] = match (true) {
                $choice === 'manager' => [Party::forUser($deal->buyer), $deal->buyer],
                str_starts_with($choice, 'user:') => $this->buyer($deal, (int) substr($choice, 5)),
                str_starts_with($choice, 'party:') => [$this->known($deal, (int) substr($choice, 6)), null],
                default => [$this->fresh($data), null],
            };
            // Своей стороной всегда, и менеджер тоже (17.11.2026): по умолчанию подбор платит покупатель, выбор «менеджер» должен
            // держаться (`Deal::managerPays` узнаёт его по стороне менеджера).
            $deal->forceFill(['payer_party_id' => $party->id])->save();
            // Договор ПРАЙМ смотрит на `payer` (кто платит — менеджер или покупатель): держим в согласии.
            if ($contract = $deal->contract) {
                $contract->forceFill(['payer' => $user?->id === $deal->buyer_id ? 'manager' : 'buyer'])->save();
            }
            $deal->unsetRelation('contract')->unsetRelation('payer');
            $deal->offer?->log(OfferEventType::Note, $by, ['text' => 'Плательщик: '.$party->name]);
            ($this->sync)($deal->fresh(), $by);
            // Счёт остался прежним (тот же плательщик), а ссылка заведена на другого (выбирали у ссылки до 16.11.2026,
            // счёт № 184: счёт Орлову, ссылка Виеову) — ссылка на плательщика счёта, на ту же сумму.
            foreach (self::invoices($deal) as $invoice) {
                $link = $invoice->openLink();
                // Не отправленный менеджеру счёт ссылки не получает (`CreatePayLink`): смена плательщика из-за этого не падает.
                if ($link && $invoice->party && $invoice->isShownToManager() && PayLink::squash((string) $link->payer_name) !== PayLink::squash((string) $invoice->party->name)) {
                    app(CreatePayLink::class)($invoice, $by, (float) $link->amount, PayerKind::Other, null, $invoice->party->name, null, $invoice->party->email ?: $link->payer_email);
                }
            }

            return $party;
        });
    }

    /**
     * Оплатили по ссылке, а на странице оплаты человек вписал другое имя (16.11.2026, владелец: «имя плательщика должно
     * замениться на это новое фактическое»): плательщик счёта и сделки — он. Известный по сделке (менеджер, его покупатели,
     * прежние сторонние) — тот же, иначе новая сторона-физлицо с его почтой. Счёт перепечатывается на него, в истории — было →
     * стало. Почту плательщик вписывает сам на странице оплаты — мы её не спрашиваем.
     */
    public static function paidBy(Invoice $invoice, PayLink $link): void
    {
        $name = PayLink::squash((string) $link->payer_name);
        $deal = $invoice->deal;
        if ($name === '' || ! $deal || ! self::follows($invoice) || PayLink::squash((string) $invoice->party?->name) === $name) {
            return;
        }
        $was = $invoice->party?->name;
        $party = null;
        foreach (self::options($deal) as $o) {
            $candidate = $o['user'] ? Party::forUser($o['user']) : $o['party'];
            if (in_array($name, [PayLink::squash((string) $candidate->name), PayLink::squash((string) $o['user']?->name)], true)) {
                $party = $candidate;
                break;
            }
        }
        $party ??= Party::create(['kind' => PartyKind::Person, 'name' => $name, 'email' => $link->payer_email]);
        $invoice->forceFill(['party_id' => $party->id])->save();
        $deal->forceFill(['payer_party_id' => $party->id === $deal->buyer?->party_id ? null : $party->id])->save();
        $deal->offer?->log(OfferEventType::Note, null, ['text' => 'Оплатил по ссылке '.$name.($was ? ' (плательщиком стоял '.$was.')' : '')]);
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
