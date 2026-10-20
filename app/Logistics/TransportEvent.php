<?php

namespace App\Logistics;

use App\Support\Ago;
use App\Support\Money;
use App\Support\Plural;
use App\Users\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Строка истории вывоза (18.10.2026, владелец: «чтобы видеть, кто что нажал, — Татьяна вместо исполнителя или кто-то
 * вместо Татьяны, кто и во сколько»): что случилось, кто нажал (`by_id`) и за кого (`for_id` — исполнитель, когда шаг
 * исполнителя нажал не он: логист, админ). Пишут `record` — назначения из своих действий, шаги — слушатель
 * `Listeners\RecordHistory` по `TransportMoved`. Лесенка (`Transport::steps`) — где задача сейчас, история — как дошла.
 */
#[Fillable(['transport_id', 'type', 'by_id', 'for_id', 'payload', 'created_at'])]
final class TransportEvent extends Model
{
    public const UPDATED_AT = null;

    /** Шаги исполнителя: нажал не он — «за него». */
    public const EXECUTOR_STEPS = ['called', 'missed', 'docs_received', 'picked', 'delivered'];

    /** Шаг отката — словами кнопки, что отменили. */
    public const STEPS = ['logist' => 'Логист', 'executor' => 'Исполнитель', 'called' => 'Созвонились', 'docs_sent' => 'Документы отправлены',
        'docs_received' => 'Получил документы', 'picked' => 'Забрал', 'delivered' => 'Привёз', 'cancelled' => 'Вывоз отменён'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'created_at' => 'datetime'];
    }

    public function by(): BelongsTo
    {
        return $this->belongsTo(User::class, 'by_id');
    }

    public function for(): BelongsTo
    {
        return $this->belongsTo(User::class, 'for_id');
    }

    public static function record(Transport $t, string $type, ?User $by, array $payload = [], ?Carbon $at = null): self
    {
        $for = in_array($type, self::EXECUTOR_STEPS, true) && $by && $t->executor_id && $t->executor_id !== $by->id ? $t->executor_id : null;

        return self::create(['transport_id' => $t->id, 'type' => $type, 'by_id' => $by?->id, 'for_id' => $for,
            'payload' => array_filter($payload, fn ($v) => $v !== null) ?: null, 'created_at' => $at ?? now()]);
    }

    /** Что случилось — фразой: «Исполнитель Иван П., был Сахиб Г.», «Созвонились, вывоз 24 окт, 12:00», «Отменили «Забрал»». */
    public function what(): string
    {
        $p = $this->payload ?? [];
        $name = fn (?int $id) => $id ? (User::find($id)?->shortName() ?? 'удалён') : null;
        // Имена не склоняем: «был Сахиб Г.», а не «вместо Сахиба Г.».
        $was = fn () => ($w = $name($p['was'] ?? null)) ? ', был '.$w : '';

        return match ($this->type) {
            'opened' => isset($p['from']) ? 'Следующий отрезок: со стоянки' : 'Задача заведена',
            // Точку не опускаем в строчные: адрес («Казань, Победы 1») — данные извне.
            'route' => 'Куда: '.implode(', ', array_filter([$p['to'] ?? 'пока неизвестно', isset($p['reach']) ? mb_lcfirst($p['reach']) : null])),
            'reopened' => 'Вывоз снова нужен',
            'logist' => 'Логист '.$name($p['logist'] ?? null).$was(),
            'assigned' => 'Исполнитель '.$name($p['executor'] ?? null).$was(),
            'released' => 'Исполнитель снят',
            'cost' => 'Цена '.Money::rub((int) ($p['cost'] ?? 0)).(isset($p['was']) ? ', была '.Money::rub((int) $p['was']) : ''),
            'called' => 'Созвонились'.(isset($p['at']) ? ', вывоз '.Ago::day(Carbon::parse($p['at']), time: true) : ''),
            'missed' => 'Не дозвонился',
            'docs_sent' => 'Документы отправлены СДЭКом'.(isset($p['track']) ? ', трек '.$p['track'] : ''),
            'docs_received' => 'Получил документы',
            'picked' => 'Забрал'.(isset($p['keys']) ? ', '.$p['keys'].' '.Plural::of((int) $p['keys'], ['ключ', 'ключа', 'ключей']) : '').(isset($p['to']) ? ', '.$p['to'] : ''),
            'delivered' => 'Привёз'.(isset($p['to']) ? ', '.$p['to'] : '').(isset($p['day']) ? ', '.$p['day'] : ''),
            'cancelled' => 'Вывоз отменён'.(isset($p['reason']) ? ': '.$p['reason'] : ''),
            'auction' => 'Забрал аукцион',
            'auction_undone' => 'Продажа на аукционе отменена',
            'undone' => 'Отменили «'.(self::STEPS[$p['step'] ?? ''] ?? 'шаг').'»',
            default => $this->type,
        };
    }

    /** Кто — «Татьяна Л. за исполнителя Иван П.» (имя не склоняем); по ссылке исполнителя — он сам; без автора — null. */
    public function who(): ?string
    {
        if (! $this->by_id) {
            return null;
        }
        $by = $this->by?->shortName() ?? 'удалён';

        return $this->for_id ? $by.' за исполнителя '.($this->for?->shortName() ?? '') : $by;
    }
}
