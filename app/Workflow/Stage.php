<?php

namespace App\Workflow;

use App\Billing\WorkDays;
use App\Mail\Template;
use App\Offers\CarPlace;
use App\Offers\Deal;
use App\Offers\Offer;
use App\Offers\OfferState;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Этап — наша кухня: чей ход, сколько ждём, что становится с машиной и
 * какими кнопками отсюда уходят. Менеджер видит блок, а с этапа наружу
 * выходит только просьба к нему — там, где его ход.
 */
#[Fillable([
    'workflow_id', 'block_id', 'name', 'position', 'waits_for', 'limit_minutes', 'limit_workdays', 'deadline_source',
    'offer_state', 'car_place', 'ask_title', 'ask_text', 'asks', 'fields', 'staff_fields', 'template_id',
])]
class Stage extends Model
{
    protected $table = 'workflow_stages';

    protected function casts(): array
    {
        return [
            'waits_for' => WaitsFor::class,
            'deadline_source' => DeadlineSource::class,
            'offer_state' => OfferState::class,
            'car_place' => CarPlace::class,
            'asks' => Asks::class,
            'fields' => 'array',
            'staff_fields' => 'array',
            'limit_minutes' => 'int',
            'limit_workdays' => 'bool',
        ];
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    public function block(): BelongsTo
    {
        return $this->belongsTo(Block::class, 'block_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class, 'template_id');
    }

    public function exits(): HasMany
    {
        return $this->hasMany(Outcome::class, 'stage_id')->orderBy('position');
    }

    /**
     * Исходы для того, кто жмёт, и той ветки, что сейчас идёт: на продаже — сделка (гаражная «платим мы» видит
     * гаражную ветку вместо покупательской), на вывозе — куда везём (`Outcome::fits`). Без сделки — как у обычной.
     *
     * @return Collection<int, Outcome>
     */
    public function exitsFor(Actor $actor, ?Deal $deal = null): Collection
    {
        return $this->loadedExits()->where('actor', $actor)->filter(fn (Outcome $e) => $e->fits($deal))->values();
    }

    /**
     * Этап оплаты: менеджер выходит из него «Платёжное поручение приложено». Ход на нём зависит от счёта — без счёта
     * ждём нас (`Position::awaitsInvoice`). Одна дверь вместо сравнения подписи по месту.
     */
    public function isPayStep(): bool
    {
        // По всем веткам: у гаражной «платим мы» тот же этап, а без сделки её выходы `exitsFor` не отдаёт.
        return $this->loadedExits()->contains(fn (Outcome $e) => $e->actor === Actor::Manager && str_starts_with(mb_strtolower($e->label), 'платёжное поручение'));
    }

    /**
     * Этап с письмом поставщику (11.10.2026): шаблон письма и ход поставщика — «Согласование с поставщиком» Альфы
     * («Уведомили поставщика о покупке»), «Подтвердили покупку поставщику». Пока письмо не ушло — ход наш, срок ответа
     * считается от письма (`Position::letterStatus`, `Offers\Listeners\MarkSupplierLetter`).
     */
    public function isLetterStep(): bool
    {
        return $this->template_id !== null && $this->waits_for === WaitsFor::Supplier;
    }

    /** Ждём согласия менеджера взять сделку в работу («Беру в работу»). */
    public function asksConsent(): bool
    {
        return $this->loadedExits()->contains(fn (Outcome $e) => $e->takes());
    }

    /** Этап получения автомобиля: менеджер жмёт «Автомобиль забрал» (Совкомбанк — счетов до него нет). */
    public function isPickupStep(): bool
    {
        return $this->loadedExits()->contains(fn (Outcome $e) => $e->actor === Actor::Manager && mb_strtolower($e->label) === 'автомобиль забрал');
    }

    /**
     * Сделка ещё согласуется: поставщик может отказать («Поставщик согласовал» / «Поставщик отказал» — у Каркаде и на
     * «Собираем заявку поставщику», пока заявка не ушла) или ждём согласия менеджера «Беру в работу». Счетов на таком этапе
     * нет — их ставит первый вход дальше (`AgreeDeal`, 09.10.2026).
     */
    public function isAgreement(): bool
    {
        return $this->loadedExits()->contains(fn (Outcome $e) => preg_match('/^поставщик (согласовал|отказал)/u', mb_strtolower($e->label)) === 1
            || $e->takes());
    }

    /** Выход менеджера «Платёжное поручение приложено» этого этапа — его жмут заявка об оплате и сама оплата. */
    public function payExit(?Deal $deal = null): ?Outcome
    {
        return $this->exitsFor(Actor::Manager, $deal)->first(fn (Outcome $e) => str_starts_with(mb_strtolower($e->label), 'платёжное поручение'));
    }

    public function awaitsManager(?Deal $deal = null): bool
    {
        return $this->exitsFor(Actor::Manager, $deal)->isNotEmpty();
    }

    /** Наш исход, ведущий на этап с таким состоянием оффера: так кнопка «Опубликовать» догоняет маршрут. */
    public function exitInto(OfferState $state, ?Deal $deal = null): ?Outcome
    {
        return $this->exitsFor(Actor::Staff, $deal)->first(fn (Outcome $e) => $e->to?->offer_state === $state);
    }

    public function timerExit(): ?Outcome
    {
        return $this->exitsFor(Actor::Timer)->first();
    }

    /** countdown — ждём менеджера или поставщика и срок есть; stopwatch — кого-то ждём без срока; none. */
    public function timerMode(): string
    {
        return match (true) {
            $this->waits_for === WaitsFor::Nobody => 'none',
            $this->deadline_source !== DeadlineSource::Own || $this->limit_minutes !== null => 'countdown',
            default => 'stopwatch',
        };
    }

    public function deadlineFrom(Carbon $enteredAt): ?Carbon
    {
        return $this->limit_minutes !== null && $this->waits_for !== WaitsFor::Nobody
            ? self::addLimit($enteredAt, $this->limit_minutes, (bool) $this->limit_workdays)
            : null;
    }

    /**
     * Срок от момента: календарные минуты или рабочие дни (19.01.2027, начальник: «3 дня» с пятницы горели в понедельник утром) —
     * выходные не считаются, время суток то же (пт 15:00 + 1 раб. дн = пн 15:00). Рабочие дни — только целыми сутками.
     */
    public static function addLimit(Carbon $from, int $minutes, bool $workdays): Carbon
    {
        return $workdays ? WorkDays::add($from, intdiv($minutes, 1440)) : $from->copy()->addMinutes($minutes);
    }

    /** Обратно к `addLimit`: откуда тикали часы, если срок такой. */
    public static function subLimit(Carbon $deadline, int $minutes, bool $workdays): Carbon
    {
        return $workdays ? WorkDays::sub($deadline, intdiv($minutes, 1440)) : $deadline->copy()->subMinutes($minutes);
    }

    /**
     * Срок словом — одна функция на доску маршрута, экран «Таймеры», форму и шаг: «3 раб. дн», «1 д 12 ч», «90 мин» → «1 ч 30 мин»;
     * чужой источник — его имя («срок приёма подтверждений»), без срока — null.
     */
    public function limitLabel(): ?string
    {
        if ($this->deadline_source !== DeadlineSource::Own) {
            return mb_strtolower($this->deadline_source->label());
        }

        return self::durationLabel($this->limit_minutes, (bool) $this->limit_workdays);
    }

    public static function durationLabel(?int $minutes, bool $workdays = false): ?string
    {
        if ($minutes === null) {
            return null;
        }
        if ($workdays) {
            return intdiv($minutes, 1440).' раб. дн';
        }
        $d = intdiv($minutes, 1440);
        $h = intdiv($minutes % 1440, 60);
        $m = $minutes % 60;

        return implode(' ', array_filter([$d ? $d.' д' : null, $h ? $h.' ч' : null, $m ? $m.' мин' : null])) ?: '0 мин';
    }

    /** Срок этапа для оффера, если войти в него сейчас: свой, конец приёма подтверждений или срок страховой. */
    public function deadlineFor(Offer $offer, Carbon $now): ?Carbon
    {
        return match ($this->deadline_source) {
            DeadlineSource::BidsClose => $offer->bids_close_at,
            DeadlineSource::InsurerDeadline => $offer->insurer_deadline_at?->copy()->endOfDay(),
            DeadlineSource::Own => $this->deadlineFrom($now),
        };
    }

    public function managerTitle(): string
    {
        return $this->ask_title ?: ($this->block?->name ?? $this->name);
    }

    public function managerText(): ?string
    {
        return $this->ask_text ?: $this->block?->text;
    }

    /** Поля с ключами: ключ считается из подписи один раз и дальше не меняется, иначе осиротели бы записанные ответы. */
    public static function keyFields(array $fields): array
    {
        $result = [];
        $taken = [];
        foreach ($fields as $field) {
            $label = trim((string) ($field['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $key = trim((string) ($field['key'] ?? '')) ?: (Str::slug($label, '_') ?: 'pole');
            $unique = $key;
            for ($n = 2; in_array($unique, $taken, true); $n++) {
                $unique = "{$key}_{$n}";
            }
            $taken[] = $unique;
            $result[] = ['key' => $unique, 'label' => $label, 'type' => in_array($field['type'] ?? '', ['text', 'number', 'date', 'textarea'], true) ? $field['type'] : 'text'];
        }

        return $result;
    }

    private function loadedExits(): Collection
    {
        return $this->relationLoaded('exits') ? $this->exits : $this->exits()->with('to')->get();
    }
}
