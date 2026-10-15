<?php

namespace App\Garage;

use App\Offers\PickupState;
use App\Support\Plural;
use App\Users\User;

/**
 * Машина гаража одной строкой — для плитки, строки и таблицы на сайте и в CRM (10.10.2026: раньше это считали три
 * шаблона каждый по-своему). Этап цветным словом (ждёт машину — словом вывоза, ждут ответа менеджера — «ваш ход»),
 * когда (дни на этапе, у проданной — с какого числа, у рассчитанной — дата) и главное число этапа с подписью.
 * До продажи главное число везде — закупочная (`Car::price`, 13.10.2026: менеджеру, админу на сайте и в CRM), после —
 * итог продажи: к выплате, отдать нам, продана за.
 */
final class CarLine
{
    private function __construct(
        public readonly string $word,
        public readonly string $tone,
        public readonly string $when,
        public readonly float|int|null $value,
        public readonly ?string $caption,
        public readonly ?string $who,
    ) {}

    public static function for(Car $car, User $viewer, bool $crm = false): self
    {
        $offer = $car->offer;
        $staff = $viewer->isAdmin();
        $current = $car->payoutInvoice ?? $car->invoice;
        // Ход менеджера в сделке со страховой — только самому менеджеру и только на сайте.
        $asks = ! $crm && $car->manager_id === $viewer->id ? $car->deal?->openRequirement : null;
        $tone = $asks ? 'text-urgent' : self::tone($car->state->tone());
        // Ждёт машину — словом вывоза: «забрать» (везёт сам — его ход), «стоит у вас», «готовим документы».
        [$pickWord, $pickTone] = $car->isWaiting() ? PickupState::of($offer, $crm ? null : $viewer) : [null, null];
        if (! $crm && $pickTone === 'urgent' && $offer->evacuator_id === $viewer->id) {
            $tone = 'text-urgent';
        }
        $word = match (true) {
            (bool) $asks => 'ваш ход: '.mb_strtolower($asks->title),
            $pickWord !== null => 'ждёт ТС, '.$pickWord,
            default => mb_strtolower($car->state->label()),
        };
        $when = match ($car->state) {
            CarState::Settled => (string) ($car->settled_at ?? $car->sold_at)?->translatedFormat('j M'),
            CarState::Sold => 'с '.$car->sold_at?->translatedFormat('j M'),
            default => self::days($car->stageDays()),
        };
        [$value, $caption] = $crm ? self::crmMoney($car, $current) : self::siteMoney($car, $current, $staff);

        return new self(
            $word,
            $tone,
            $when,
            $value && (float) $value > 0 ? $value : null,
            $value && (float) $value > 0 ? $caption : null,
            // Чья — сотруднику; «забирает Сергей К.» уже назвал того же человека — второй раз не пишем.
            $staff && ! ($car->manager && str_contains($word, $car->manager->shortName())) ? ($car->manager?->shortName() ?? 'взяли под себя') : null,
        );
    }

    /** «3 дня»: число и слово не разрываются переносом. */
    public static function days(int $d): string
    {
        return $d."\u{00A0}".Plural::of($d, ['день', 'дня', 'дней']);
    }

    private static function tone(string $tone): string
    {
        return match ($tone) {
            'urgent' => 'text-urgent',
            'open' => 'text-accent-text',
            'closed' => 'text-ink-dim',
            default => 'text-ink-muted',
        };
    }

    private static function siteMoney(Car $car, $current, bool $staff): array
    {
        return match ($car->state) {
            // Закупочная — только сотруднику (15.10.2026, владелец); менеджеру до продажи — его расходы.
            CarState::Waiting, CarState::Repair, CarState::Ready, CarState::Selling => $staff ? [$car->price(), 'закупочная']
                : (($spent = $car->extraSpent()) > 0 ? [$spent, 'расходы'] : [null, null]),
            CarState::Sold => $current && $current->remaining() > 0
                ? [$current->remaining(), $current->isOwed() ? ($staff ? 'отдаём' : 'к выплате') : ($car->invoice_to === 'buyer' ? 'платит покупатель' : ($staff ? 'отдаёт нам' : 'отдать нам'))]
                : [$car->sold_price, $staff ? 'счёта нет' : 'продана за'],
            default => [$car->sold_price, 'продана за'],
        };
    }

    private static function crmMoney(Car $car, $current): array
    {
        return match ($car->state) {
            CarState::Waiting, CarState::Repair, CarState::Ready, CarState::Selling => [$car->price(), 'закупочная'],
            CarState::Sold => $current && $current->remaining() > 0
                ? [$current->remaining(), $current->isOwed() ? 'отдаём' : ($car->invoice_to === 'buyer' ? 'платит покупатель' : 'отдаёт нам')]
                : [$car->sold_price, $car->invoice ? 'продана за' : 'счёта нет'],
            default => [$car->sold_price, 'продана за'],
        };
    }
}
