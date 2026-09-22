<?php

namespace App\Billing;

use App\Cars\HasLabels;

/** За что деньги: хранение, эвакуация, осмотр, простой, негабарит — стоянка; продажа, подбор, перечисление вендору, агентское вознаграждение менеджеру и вознаграждение от поставщика — сделки. */
enum ChargeKind: string
{
    use HasLabels;

    case Storage = 'storage';
    case Tow = 'tow';
    case Inspection = 'inspection';
    case Idle = 'idle';
    case Oversize = 'oversize';
    case Loading = 'loading';
    case Release = 'release';
    case Sale = 'sale';
    case Selection = 'selection';
    case Transfer = 'transfer';
    case AgentFee = 'agent_fee';
    case Reward = 'reward';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Storage => 'Хранение',
            self::Tow => 'Эвакуация',
            self::Inspection => 'Осмотр',
            self::Idle => 'Простой',
            self::Oversize => 'Негабарит',
            self::Loading => 'Погрузка',
            self::Release => 'Выдача',
            self::Sale => 'Продажа ТС',
            self::Selection => 'Подбор ТС',
            self::Transfer => 'Перечисление вендору',
            self::AgentFee => 'Агентское вознаграждение',
            self::Reward => 'Вознаграждение от поставщика',
            self::Other => 'Прочее',
        };
    }
}
