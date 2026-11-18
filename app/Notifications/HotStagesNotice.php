<?php

namespace App\Notifications;

use App\Support\Plural;
use App\Support\Surface;

/** Утренняя сводка админам: сколько шагов сделок горит и сколько из них — наш ход (`Workflow\Actions\StagesDigest`). */
final class HotStagesNotice extends Notice
{
    public function __construct(private int $total, private int $ours) {}

    /** Заменяет само себя: в ленте строка одна (`CollapseNotices`). */
    public function collapses(?\App\Users\User $user): bool
    {
        return true;
    }

    public function label(): string
    {
        return 'Утренняя сводка: горит';
    }

    public function title(): string
    {
        return 'Горит: '.$this->total.' '.Plural::of($this->total, ['шаг', 'шага', 'шагов']).($this->ours ? ', наш ход '.$this->ours : '');
    }

    public function text(): ?string
    {
        return null;
    }

    public function href(): string
    {
        return Surface::Crm->url('/offers?preset=agree');
    }

    public function category(): string
    {
        return 'deals';
    }
}
