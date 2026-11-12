<?php

namespace App\Billing\Bank\Actions;

use App\Billing\Bank\Transaction;
use App\Billing\Party;
use App\Billing\PartyKind;
use App\Offers\Actions\SaveDealContract;
use App\Offers\Deal;
use App\Offers\DealContract;
use App\Offers\OfferEventType;
use App\Users\Actions\AddPerson;
use App\Users\Actions\IssueInvite;
use App\Users\User;

/**
 * Плательщик из выписки — покупатель сделки (12.11.2026, владелец: из 54 поступлений по VIN 45 заплатил покупатель, а не
 * менеджер, — в выписке его ФИО или фирма с ИНН, а у 18 сделок покупателя не было, и счёт с договором не вставали): заплатил
 * не сам менеджер — его покупателем (тот же у этого менеджера — по ИНН, иначе по имени; нет — новый «Только для сделки»), в
 * сделку платит покупатель. Что дальше — штатно: физлицу паспорт (дело менеджера), фирме — реквизиты (`Заполнить`).
 */
final class BuyerFromStatement
{
    /** ИНН банка-посредника: физлицо платит через Сбербанк — в «Плательщике» «ПАО Сбербанк//ФИО//счёт». */
    private const BANKS = ['7707083893'];

    public function __construct(private AddPerson $add, private IssueInvite $issue, private SaveDealContract $save) {}

    /**
     * Кто заплатил, если не сам менеджер сделки.
     *
     * @return array{name: string, inn: ?string, kind: PartyKind}|null
     */
    public static function payer(Transaction $tx, Deal $deal): ?array
    {
        $parts = array_values(array_filter(array_map('trim', explode('//', (string) $tx->counterparty)), fn ($p) => $p !== '' && ! preg_match('/^\d+$/', $p)));
        $name = $parts && count($parts) > 1 && preg_match('/банк/iu', $parts[0]) ? $parts[1] : ($parts[0] ?? null);
        $name = trim(preg_replace('/\s*\(ИП\)|^ИП\s+/u', '', (string) $name));
        if ($name === '' || ! preg_match('/\p{L}{2}/u', $name)) {
            return null;
        }
        $surname = mb_strtoupper(trim((string) ($deal->buyer?->last_name ?: last(explode(' ', trim((string) $deal->buyer?->name))))));
        if ($surname !== '' && preg_match('/(^|\s)'.preg_quote($surname, '/').'(\s|$)/u', mb_strtoupper($name))) {
            return null;
        }
        $inn = $tx->counterparty_inn && ! in_array($tx->counterparty_inn, self::BANKS, true) ? $tx->counterparty_inn : null;
        $kind = match (true) {
            $inn !== null && strlen($inn) === 10 => PartyKind::Company,
            $inn !== null && strlen($inn) === 12 && str_contains((string) $tx->counterparty, 'ИП') => PartyKind::Entrepreneur,
            default => PartyKind::Person,
        };
        // Физлицо в выписке — заглавными: «ГОЛОВНЁВ КИРИЛЛ ВИКТОРОВИЧ» → «Головнёв Кирилл Викторович».
        if ($kind !== PartyKind::Company && mb_strtoupper($name) === $name) {
            $name = mb_convert_case(mb_strtolower($name), MB_CASE_TITLE);
        }

        return ['name' => $name, 'inn' => $kind === PartyKind::Person ? null : $inn, 'kind' => $kind];
    }

    /** Поставить плательщика покупателем сделки; null — покупатель уже есть, платил сам менеджер или имени нет. */
    public function __invoke(Transaction $tx, Deal $deal, User $by): ?User
    {
        $deal->loadMissing(['buyer', 'contract', 'offer']);
        if (! $deal->buyer?->isManager() || ! $deal->collectsBuyer() || $deal->contract?->buyer_user_id || ! ($p = self::payer($tx, $deal))) {
            return null;
        }
        $mine = User::where('manager_id', $deal->buyer_id);
        $user = ($p['inn'] ? (clone $mine)->whereHas('party', fn ($q) => $q->where('inn', $p['inn']))->first() : null)
            ?? (clone $mine)->whereRaw('lower(name) = ?', [mb_strtolower($p['name'])])->first();
        if (! $user) {
            // Физлицо — «Фамилия Имя Отчество»: фамилия отдельно; фирма — одним именем.
            $words = explode(' ', $p['name'], 2);
            [$first, $last] = $p['kind'] === PartyKind::Person && count($words) === 2 ? [$words[1], $words[0]] : [$p['name'], ''];
            [$user] = ($this->add)(fn () => $this->issue->buyer($deal->buyer, $deal->id), ['first_name' => mb_substr($first, 0, 60), 'last_name' => mb_substr($last, 0, 60)], contact: true);
        }
        $party = Party::forUser($user);
        $party->update(array_filter(['kind' => $party->inn ? null : $p['kind'], 'name' => $party->inn ? null : $p['name'], 'inn' => $party->inn ?: $p['inn'],
            'account' => $party->account ?: $tx->counterparty_account, 'bank_name' => $party->bank_name ?: $tx->counterparty_bank], fn ($v) => $v !== null && $v !== ''));
        ($this->save)(DealContract::for($deal), ['buyer_id' => (string) $user->id, 'payer' => 'buyer'], $by);
        $deal->offer->log(OfferEventType::Note, $by, ['text' => 'Покупатель из выписки: '.$p['name'].($p['inn'] ? ', ИНН '.$p['inn'] : '')]);

        return $user;
    }
}
