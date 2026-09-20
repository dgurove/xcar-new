<?php

namespace App\Notifications;

use App\Mail\Candidate;
use App\Mail\Message;
use App\Park\Request;
use App\Park\Vehicle;
use App\Support\Money;
use App\Support\Plural;
use App\Support\Surface;
use Carbon\CarbonInterface;

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

    public static function mail(Vehicle $v, Message $m): self
    {
        return new self('Письмо: '.$v->titleWithYear(), ($m->from_name ?: $m->from_email).' — '.($m->subject ?: 'без темы'), '/mail/'.$m->thread_id, $v->id);
    }

    public static function sold(Vehicle $v, ?Message $m = null): self
    {
        $who = trim(($v->pickup_name ?? '').' '.($v->pickup_phone ?? ''));

        return new self('Продано: '.$v->titleWithYear(), $who ? 'Заберёт '.$who : ($m?->subject ?: null), '/cars/'.$v->id, $v->id);
    }

    public static function closing(CarbonInterface $month, int $count, float $sum): self
    {
        return new self('Закрытие '.mb_strtolower($month->translatedFormat('F')).': '.$count.' '.Plural::of($count, ['счёт', 'счёта', 'счетов']).' на '.Money::rub($sum), 'Проверьте и выставьте', '/money/closing?month='.$month->format('Y-m'));
    }

    public static function assigned(Request $r): self
    {
        return new self('Вам: '.mb_strtolower($r->type->label()).' — '.$r->vehicle->titleWithYear(), $r->planned_at?->translatedFormat('j M, H:i'), '/cars/'.$r->vehicle_id, $r->vehicle_id, true);
    }

    public static function due(Request $r, bool $overdue): self
    {
        return new self(($overdue ? 'Срок вышел: ' : 'Через два часа: ').mb_strtolower($r->type->label()).' — '.$r->vehicle->titleWithYear(), $r->planned_at?->translatedFormat('j M, H:i'), '/cars/'.$r->vehicle_id, $r->vehicle_id, $overdue);
    }

    public static function call(Request $r): self
    {
        return new self('Перезвонить: '.$r->vehicle->titleWithYear(), $r->contactLine() ?: null, '/cars/'.$r->vehicle_id, $r->vehicle_id);
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
