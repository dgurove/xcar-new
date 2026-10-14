<?php

namespace App\Offers;

use App\Users\User;
use Illuminate\Support\Carbon;

/**
 * Как менялись цены предложения (14.10.2026): из записей «Изменён» с было → стало (`Support\Changes`). «Цены» редактора
 * показывают прежнюю сумму зачёркнутой и по нажатию — все правки этой цены: когда, кто, откуда. Закупочная в сделке
 * (`cost`) — рядом с закупочной: её меняет та же правка (`UpdateDealCost`).
 */
final class PriceHistory
{
    public const FIELDS = ['value', 'floor_price', 'publish_price', 'asking_price', 'min_bid_price', 'cost'];

    /** @var array<string, list<array{at: Carbon, was: ?int, now: ?int, user: ?User, why: ?string}>> */
    private array $rows = [];

    public static function of(Offer $offer): self
    {
        $history = new self;
        $events = OfferEvent::where('offer_id', $offer->id)->where('type', OfferEventType::Updated)
            ->whereRaw("jsonb_exists_any(payload->'changes', array['".implode("','", self::FIELDS)."'])")
            ->with('user')->latest('created_at')->latest('id')->get();
        foreach ($events as $event) {
            foreach (array_intersect_key($event->payload['changes'] ?? [], array_flip(self::FIELDS)) as $field => [$was, $now]) {
                $history->rows[$field][] = ['at' => $event->created_at, 'was' => $was === null ? null : (int) $was, 'now' => $now === null ? null : (int) $now,
                    'user' => $event->user, 'why' => self::why($event->payload)];
            }
        }

        return $history;
    }

    /**
     * Правки цены, новые сверху; у закупочной — и закупочной в сделке (`deal: true`).
     *
     * @return list<array{at: Carbon, was: ?int, now: ?int, user: ?User, why: ?string, deal?: bool}>
     */
    public function rows(string $field): array
    {
        $rows = $this->rows[$field] ?? [];
        if ($field === 'floor_price' && isset($this->rows['cost'])) {
            $rows = collect($rows)->concat(array_map(fn ($r) => $r + ['deal' => true], $this->rows['cost']))
                ->sortByDesc(fn ($r) => $r['at']->getTimestamp())->values()->all();
        }

        return $rows;
    }

    /** Сумма до последней правки — зачёркнутой рядом с нынешней; не было — null. */
    public function previous(string $field): ?int
    {
        return $this->rows[$field][0]['was'] ?? null;
    }

    public function has(string $field): bool
    {
        return $this->rows($field) !== [];
    }

    /** Откуда правка словом: файл поставщика, «Из текста», Мигторг, своя причина пробника. */
    private static function why(array $p): ?string
    {
        return match (true) {
            ! empty($p['why']) => (string) $p['why'],
            ($p['source'] ?? null) === 'file' => 'по файлу'.(! empty($p['file']) ? ' «'.$p['file'].'»' : ''),
            ($p['source'] ?? null) === 'valuation' => 'оценка из текста',
            ($p['source'] ?? null) === 'migtorg' => 'с Мигторга',
            default => null,
        };
    }
}
