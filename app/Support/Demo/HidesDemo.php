<?php

namespace App\Support\Demo;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

/**
 * Демо-записи видит только демо-пользователь. Сотрудникам, другим людям, очередям и расписанию
 * (без вошедшего) их нет: ни в списках CRM, ни в счётчиках, ни в сверке выписки.
 * Старые миграции ходят в базу моделями раньше, чем появилась колонка `is_demo`, — до неё области нет.
 */
trait HidesDemo
{
    /** @var array<string, bool> */
    private static array $demoColumn = [];

    protected static function bootHidesDemo(): void
    {
        static::addGlobalScope('demo', function (Builder $q) {
            $table = $q->getModel()->getTable();
            if (! (self::$demoColumn[$table] ??= Schema::hasColumn($table, 'is_demo')) || Demo::viewing()) {
                return;
            }
            $q->where($q->getModel()->qualifyColumn('is_demo'), false);
        });
    }
}
