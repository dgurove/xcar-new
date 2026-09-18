<?php

namespace App\Notifications;

use App\Mail\Candidate;
use App\Park\Request;
use App\Park\Vehicle;
use App\Support\Surface;

/** Уведомление стоянки: письмо, назначение, срок, простой — одна категория «Стоянка», метка по ТС, ссылка на хост стоянки. */
final class ParkNotice extends Notice
{
    public function __construct(private string $title, private ?string $text, private string $path, private ?int $vehicleId = null, private bool $critical = false) {}

    public static function letter(Candidate $c): self
    {
        $v = fn (string $f) => $c->extracted[$f]['value'] ?? null;
        $what = ($v('request') ?? null) === 'tow' ? 'Письмо на вывоз' : 'Письмо на приём';

        return new self($what.': '.$c->title(), implode(', ', array_filter([$v('vendor'), $v('location'), $v('insured_phone')])), '/requests/from-mail');
    }

    public static function assigned(Request $r): self
    {
        return new self('Вам: '.mb_strtolower($r->type->label()).' — '.$r->vehicle->titleWithYear(), $r->planned_at?->translatedFormat('j M, H:i'), '/requests/'.$r->id, $r->vehicle_id, true);
    }

    public static function due(Request $r, bool $overdue): self
    {
        return new self(($overdue ? 'Срок вышел: ' : 'Через два часа: ').mb_strtolower($r->type->label()).' — '.$r->vehicle->titleWithYear(), $r->planned_at?->translatedFormat('j M, H:i'), '/requests/'.$r->id, $r->vehicle_id, $overdue);
    }

    public static function idle(Vehicle $v, int $days): self
    {
        return new self('Стоит '.$days.' дн: '.$v->titleWithYear(), ($v->vendor?->name ?? '').($v->yard ? ', '.$v->yard->name : ''), '/cars/'.$v->id, $v->id);
    }

    public function title(): string
    {
        return $this->title;
    }

    public function text(): ?string
    {
        return $this->text ?: null;
    }

    public function href(): string
    {
        return Surface::Park->url($this->path);
    }

    public function tag(): ?string
    {
        return $this->vehicleId ? 'park-'.$this->vehicleId : null;
    }

    public function category(): string
    {
        return 'park';
    }

    public function critical(): bool
    {
        return $this->critical;
    }
}
