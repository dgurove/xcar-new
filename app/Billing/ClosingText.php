<?php

namespace App\Billing;

use App\Support\FastAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Свой текст документа месяца, пока он черновик (`Closing::draft`, в базе его нет): номер и дата акта, основание, строка
 * (17.11.2026, `Invoice::actNo`, `registryLine`). Ключ — месяц и группа черновика; отправка (`FixClosingDocument`) переносит
 * их в записанный счёт и стирает строку.
 */
class ClosingText extends Model
{
    use FastAttributes;

    public const FIELDS = ['act_no', 'act_at', 'basis', 'line_title'];

    protected $table = 'billing_closing_texts';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['month' => 'date', 'act_at' => 'date'];
    }

    public static function for(\DateTimeInterface $month, string $group): ?self
    {
        return self::whereDate('month', $month->format('Y-m-01'))->where('group', $group)->first();
    }
}
