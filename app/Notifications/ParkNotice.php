<?php

namespace App\Notifications;

use App\Billing\Invoice;
use App\Mail\Candidate;
use App\Mail\Extraction\Intent;
use App\Mail\Message;
use App\Park\Pass;
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
        private string $category = 'park', private bool $telegram = true, private ?string $batch = null, private ?string $line = null) {}

    /** Простой и «управляющий принял / выдал» администраторам — пачкой (`Batch`, 27.10.2026): по одному на ТС — спам. */
    public function batchKey(): ?string
    {
        return $this->batch;
    }

    public function batchLine(): string
    {
        return $this->line ?? $this->title;
    }

    public static function letter(Candidate $c): self
    {
        $v = fn (string $f) => $c->extracted[$f]['value'] ?? null;

        return new self('Письмо на приём: '.$c->title(), implode(', ', array_filter([$v('vendor'), $v('insured_phone')])), '/requests/from-mail');
    }

    /** Заявку завела почта (`AutoRequest`): марку и VIN, которых нет в письме, дочитывают документы. */
    public static function arrived(Request $r): self
    {
        $v = $r->vehicle;

        return new self($r->type->label().': '.$v->titleWithYear(), implode(', ', array_filter([$v->vendor?->name, $v->ref, $r->contact_phone])), '/cars/'.$v->id, $v->id);
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

    /** Письмо страховой «продано, заберёт …» по машине с живым пропуском нашей сделки — кому отдавать, решают люди. */
    public static function insurerSoldDeal(Vehicle $v, Pass $pass): self
    {
        return new self('Страховая продала ТС сделки XCar: '.$v->titleWithYear(), 'По пропуску сделки ждём '.$pass->name.'. Сверьте со сделкой, прежде чем выдавать', '/cars/'.$v->id, $v->id, critical: true);
    }

    /** Отчёт о приёме не ушёл (`Park\Report`, 29.10.2026): тому, кто отправлял, со звуком — в деле «Повторить». */
    public static function reportFailed(Vehicle $v, string $reason): self
    {
        return new self('Отчёт не ушёл: '.$v->titleWithYear(), $reason, '/cars/'.$v->id, $v->id, critical: true);
    }

    /** Получатель по сделке XCar сменился (`SyncParkRelease`): прежний пропуск погашен, заберёт другой. */
    public static function receiverChanged(Vehicle $v): self
    {
        return new self('Сменился получатель: '.$v->titleWithYear(), 'Заберёт '.trim(($v->pickup_name ?? '').' '.($v->pickup_phone ?? '')).', по сделке XCar', '/cars/'.$v->id, $v->id);
    }

    public static function closing(CarbonInterface $month, int $count, float $sum): self
    {
        return new self('Закрытие '.mb_strtolower($month->translatedFormat('F')).': '.$count.' '.Plural::of($count, ['счёт', 'счёта', 'счетов']).' на '.Money::rub($sum), 'Проверьте и выставьте', '/money/closing?month='.$month->format('Y-m'),
            category: 'park_money');
    }

    /** Управляющий принял или выдал ТС — администраторам парковки (в Telegram им пишет `NotifyOwner`, здесь — лента и пуш). */
    public static function handled(Vehicle $v, User $by, bool $released): self
    {
        return new self($by->shortName().' '.($released ? 'выдал' : 'принял').': '.$v->titleWithYear(), implode(', ', array_filter([$v->vendor?->name, $v->yard?->name, $v->ref])),
            '/cars/'.$v->id, $v->id, telegram: false, batch: 'park-handled', line: $by->shortName().' '.($released ? 'выдал' : 'принял').' '.$v->titleWithYear());
    }

    public static function idle(Vehicle $v, int $days): self
    {
        return new self('Стоит '.$days.' дн: '.$v->titleWithYear(), ($v->vendor?->name ?? '').($v->yard ? ', '.$v->yard->name : ''), '/cars/'.$v->id, $v->id, telegram: false,
            batch: 'park-idle', line: $v->titleWithYear().', '.$days.' дн');
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
