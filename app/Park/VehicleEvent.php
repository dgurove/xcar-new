<?php

namespace App\Park;

use App\Support\FieldLabels;
use App\Support\Money;
use App\Users\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['vehicle_id', 'user_id', 'type', 'payload'])]
class VehicleEvent extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'park_vehicle_events';

    protected function casts(): array
    {
        return ['type' => EventType::class, 'payload' => 'array', 'created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function text(): string
    {
        $p = $this->payload ?? [];

        return match ($this->type) {
            EventType::Created => 'Заведена',
            EventType::Accepted => 'Принята'.(! empty($p['yard']) ? ' на «'.$p['yard'].'»' : ''),
            EventType::Moved => 'Переставлена'.(! empty($p['from']) ? ' с «'.$p['from'].'»' : '').(! empty($p['to']) ? ' на «'.$p['to'].'»' : ''),
            EventType::Inspected => 'Осмотрена'.(! empty($p['note']) ? ': '.$p['note'] : ''),
            EventType::Towed => 'Эвакуация'.(! empty($p['note']) ? ': '.$p['note'] : ''),
            EventType::Released => 'Выдана'.(! empty($p['to']) ? ' — '.$p['to'] : '').(! empty($p['unpaid']) ? ', с долгом '.Money::rub($p['unpaid']) : ''),
            EventType::Note => (string) ($p['text'] ?? ''),
            EventType::Updated => 'Изменена: '.FieldLabels::list($p['fields'] ?? []),
            EventType::DocSent => ($p['direction'] ?? 'out') === 'in' ? 'Получено от вендора: '.($p['doc'] ?? '') : 'Отправлено вендору: '.($p['doc'] ?? ''),
            EventType::DocBack => 'Снята отметка: '.($p['doc'] ?? ''),
            EventType::Scheduled => 'Эвакуация назначена'.(! empty($p['at']) ? ' на '.$p['at'] : '').(! empty($p['from']) ? ', '.$p['from'] : ''),
            EventType::Departed => 'Погружена'.(! empty($p['carrier']) ? ', '.$p['carrier'] : '').(! empty($p['from']) ? ' с «'.$p['from'].'»' : ''),
            EventType::Assigned => 'Исполнитель: '.($p['user'] ?? ''),
            EventType::Cancelled => 'Не привезена'.(! empty($p['reason']) ? ': '.$p['reason'] : ''),
            EventType::Linked => 'Связана с предложением № '.($p['number'] ?? ''),
            EventType::Charged => 'Начислено: '.($p['title'] ?? '').' — '.Money::rub($p['amount'] ?? 0),
            EventType::Invoiced => 'Счёт '.($p['label'] ?? '').' на '.Money::rub($p['amount'] ?? 0).' — '.($p['party'] ?? ''),
            EventType::Owed => 'Должны '.($p['party'] ?? '').' '.Money::rub($p['amount'] ?? 0),
            EventType::Paid => 'Оплата '.Money::rub($p['amount'] ?? 0).' по счёту '.($p['label'] ?? '').(($p['left'] ?? 0) > 0 ? ', остаток '.Money::rub($p['left']) : ''),
            EventType::InvoiceVoided => 'Счёт '.($p['label'] ?? '').' аннулирован',
            EventType::Letter => 'Письмо от '.($p['from'] ?? '').': '.($p['subject'] ?? 'без темы'),
            EventType::Called => ! empty($p['reached']) ? 'Дозвонились: '.($p['outcome'] ?? '').(! empty($p['at']) ? ' '.$p['at'] : '') : 'Не дозвонились'.(! empty($p['again']) ? ', снова '.$p['again'] : ''),
            EventType::Sold => 'Продано'.(! empty($p['who']) ? ', заберёт '.$p['who'] : ''),
            EventType::ReleaseRefused => 'От получения отказался'.(! empty($p['note']) ? ': '.$p['note'] : ''),
            EventType::ReportSent => ($p['what'] ?? 'Отчёт').' отправлен вендору',
            EventType::Restored => 'Снова ждём'.(! empty($p['reason']) ? ': '.$p['reason'] : ''),
            EventType::IntakeUndone => 'Приём отменён'.(! empty($p['reason']) ? ': '.$p['reason'] : ''),
            EventType::ReleaseUndone => 'Выдача отменена'.(! empty($p['reason']) ? ': '.$p['reason'] : ''),
        };
    }
}
