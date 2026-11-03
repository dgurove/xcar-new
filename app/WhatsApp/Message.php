<?php

namespace App\WhatsApp;

use App\Offers\Estimate;
use App\Offers\Offer;
use App\Offers\Share\Caption;
use App\Support\Ago;
use App\Support\Money;
use App\Support\Surface;
use App\Users\User;

/**
 * Одно сообщение в WhatsApp: куда (`to` — личный чат, пусто — группа), что (текст; `offer` — PDF этой машины с текстом
 * подписью) и ключ — он же ключ службы в Цюрихе против дублей и строка `whatsapp_messages`.
 *
 * Виды (владелец 03.11.2026): `group` — открытое всем, в группу; `personal` — открытое не всем, каждому, кому открыто, тем же
 * PDF без «только вам» (в личные и так понятно); `estimate` — просьба оценить, текстом и ссылкой: пока не оценено, шеринг
 * закрыт.
 */
final class Message
{
    public const GROUP = 'group';

    public const PERSONAL = 'personal';

    public const ESTIMATE = 'estimate';

    private function __construct(
        public readonly string $key,
        public readonly string $kind,
        public readonly string $text,
        public readonly ?Offer $offer = null,
        public readonly ?string $to = null,
        public readonly ?int $offerId = null,
        public readonly ?int $userId = null,
        public readonly ?int $estimateId = null,
    ) {}

    public static function group(Offer $offer, ?string $key = null): self
    {
        return new self($key ?? 'offer:'.$offer->id, self::GROUP, self::caption($offer), $offer, offerId: $offer->id);
    }

    /** Шеринг ему закрыт (запрет у машины или открытая просьба оценить) — без PDF, текстом и ссылкой. */
    public static function personal(Offer $offer, User $user, ?string $key = null): self
    {
        return new self($key ?? "personal:{$offer->id}:{$user->id}", self::PERSONAL, self::caption($offer),
            $offer->shareLockedFor($user) ? null : $offer, $user->whatsapp_jid, $offer->id, $user->id);
    }

    public static function estimate(Estimate $estimate): self
    {
        $offer = $estimate->offer;
        $city = collect(Caption::fields($offer, null))->firstWhere('key', 'city')['value'] ?? null;
        $due = 'до '.Ago::day($estimate->due_at, true);
        $lines = $estimate->isCorrection()
            ? ['*Проверьте цену*', $offer->titleWithYear(), $city, Money::rub($estimate->base_price).', '.$due]
            : ['*Нужна оценка*', $offer->titleWithYear(), $city, 'Оценить '.$due,
                $estimate->exclusive_days ? 'После оценки — '.Estimate::daysWord($estimate->exclusive_days).' только вам' : null];
        $lines[] = Surface::Site->url('/offers/'.$offer->number);

        return new self('estimate:'.$estimate->id, self::ESTIMATE, implode("\n", array_filter($lines)),
            to: $estimate->user->whatsapp_jid, offerId: $offer->id, userId: $estimate->user_id, estimateId: $estimate->id);
    }

    /** Подпись: текст для клиента и ссылка на машину в XCar следующей строкой. */
    public static function caption(Offer $offer): string
    {
        return implode("\n", array_filter([Caption::forward($offer), Caption::link($offer)]));
    }
}
