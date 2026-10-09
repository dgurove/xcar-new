<?php

namespace App\Notifications;

use App\Billing\Invoice;
use App\Mail\Candidate;
use App\Mail\Extraction\Intent;
use App\Mail\Message;
use App\Park\Request;
use App\Park\Vehicle;
use App\Support\Money;
use App\Support\Plural;
use App\Support\Surface;
use App\Users\User;
use Carbon\CarbonInterface;

/**
 * Уведомление парковки: письмо, назначение, срок, простой, оплаты — категории «Заявки и письма» (`park`) и «Оплаты»
 * (`park_money`), метка по ТС, ссылка на хост парковки. Управляющему (роль «Парковка») то, где нужен он сам, приходит и в
 * Telegram (`telegram`). Заявки, письма и сроки — только управляющим своего города; администраторам парковки — «управляющий
 * принял / выдал» (`handled`), просрочка и простой (владелец 09.10.2026). В Telegram админам — только `NotifyOwner(park)`.
 */
final class ParkNotice extends Notice
{
    public function __construct(private string $title, private ?string $text, private string $path, private ?int $vehicleId = null, private bool $critical = false,
        private string $category = 'park', private bool $telegram = true) {}

    public static function letter(Candidate $c): self
    {
        $v = fn (string $f) => $c->extracted[$f]['value'] ?? null;
        $what = ($v('request') ?? null) === 'tow' ? 'Письмо на вывоз' : 'Письмо на приём';

        return new self($what.': '.$c->title(), implode(', ', array_filter([$v('vendor'), $v('location'), $v('insured_phone')])), '/requests/from-mail');
    }

    /** Заявку завела почта (`AutoRequest`): марку и VIN, которых нет в письме, дочитывают документы. */
    public static function arrived(Request $r): self
    {
        $v = $r->vehicle;

        return new self($r->type->label().': '.$v->titleWithYear(), implode(', ', array_filter([$v->vendor?->name, $v->ref, $r->from_address, $r->contact_phone])), '/cars/'.$v->id, $v->id);
    }

    public static function mail(Vehicle $v, Message $m): self
    {
        // Заголовок — смысл письма («Вендор ждёт документы или фото»), а не тема; у дела — шаг «Нужно ответить».
        $intent = Intent::tryFrom((string) $m->intent);
        $title = $intent && $intent->needsReply() ? $intent->title() : 'Письмо';

        return new self($title.': '.$v->titleWithYear(), ($m->from_name ?: $m->from_email).', '.($m->subject ?: 'без темы'), '/cars/'.$v->id, $v->id,
            telegram: $intent?->needsReply() ?? false);
    }

    public static function sold(Vehicle $v, ?Message $m = null): self
    {
        $who = trim(($v->pickup_name ?? '').' '.($v->pickup_phone ?? ''));

        return new self('На выдачу: '.$v->titleWithYear(), $who ? 'Заберёт '.$who : ($m?->subject ?: null), '/cars/'.$v->id, $v->id);
    }

    public static function closing(CarbonInterface $month, int $count, float $sum): self
    {
        return new self('Закрытие '.mb_strtolower($month->translatedFormat('F')).': '.$count.' '.Plural::of($count, ['счёт', 'счёта', 'счетов']).' на '.Money::rub($sum), 'Проверьте и выставьте', '/money/closing?month='.$month->format('Y-m'),
            category: 'park_money');
    }

    public static function assigned(Request $r): self
    {
        return new self('Вам: '.mb_strtolower($r->type->label()).' — '.$r->vehicle->titleWithYear(), $r->planned_at?->translatedFormat('j M, H:i'), '/cars/'.$r->vehicle_id, $r->vehicle_id, true);
    }

    public static function due(Request $r, bool $overdue): self
    {
        return new self(($overdue ? 'Срок вышел: ' : 'Через два часа: ').mb_strtolower($r->type->label()).' — '.$r->vehicle->titleWithYear(), $r->planned_at?->translatedFormat('j M, H:i'), '/cars/'.$r->vehicle_id, $r->vehicle_id, $overdue);
    }

    /** Управляющий принял или выдал ТС — администраторам парковки (в Telegram им пишет `NotifyOwner`, здесь — лента и пуш). */
    public static function handled(Vehicle $v, User $by, bool $released): self
    {
        return new self($by->shortName().' '.($released ? 'выдал' : 'принял').': '.$v->titleWithYear(), implode(', ', array_filter([$v->vendor?->name, $v->yard?->name, $v->ref])),
            '/cars/'.$v->id, $v->id, telegram: false);
    }

    public static function call(Request $r): self
    {
        return new self('Перезвонить: '.$r->vehicle->titleWithYear(), $r->contactLine() ?: null, '/cars/'.$r->vehicle_id, $r->vehicle_id);
    }

    public static function idle(Vehicle $v, int $days): self
    {
        return new self('Стоит '.$days.' дн: '.$v->titleWithYear(), ($v->vendor?->name ?? '').($v->yard ? ', '.$v->yard->name : ''), '/cars/'.$v->id, $v->id, telegram: false);
    }

    /** Покупатель заполнил анкету: прочитать ответ страховой и подтвердить. */
    public static function buyerForm(Vehicle $v, string $name, CarbonInterface $on): self
    {
        return new self('Покупатель заполнил анкету: '.$v->titleWithYear(), $name.', заберёт '.$on->translatedFormat('j M'), '/cars/'.$v->id, $v->id);
    }

    /** Просроченный счёт парковки — управляющему: письмо плательщику он пишет сам, автописьма нет. */
    public static function overdue(Invoice $i): self
    {
        return new self(($i->isOwed() ? 'Мы просрочили ' : 'Просрочен счёт ').$i->label().' — '.$i->party->name, Money::rub($i->remaining()),
            '/money/invoices/'.$i->id, $i->vehicle_id, true, 'park_money');
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

    public function category(): string
    {
        return $this->category;
    }

    public function critical(): bool
    {
        return $this->critical;
    }

    /** Важное — только критичное (назначено, срок вышел, просрочка): письма и продажи — карточкой без звука, как раньше. */
    public function important(): bool
    {
        return $this->critical;
    }

    public function toTelegram(): ?array
    {
        return $this->telegram ? ['title' => $this->title, 'lines' => [$this->text ?: null], 'button' => 'Открыть'] : null;
    }

    /** В Telegram парковка пишет только управляющему (роль «Парковка»); админам хватает ленты, владельцу — `NotifyOwner`. */
    public function via(User $user): array
    {
        $via = parent::via($user);

        return $user->isParking() ? $via : array_values(array_diff($via, [TelegramChannel::class]));
    }
}
