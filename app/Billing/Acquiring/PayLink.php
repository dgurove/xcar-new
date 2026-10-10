<?php

namespace App\Billing\Acquiring;

use App\Billing\ChargeKind;
use App\Billing\Invoice;
use App\Billing\InvoiceState;
use App\Billing\Payment;
use App\Billing\Seller;
use App\Garage\Car;
use App\Search\Term;
use App\Support\Money;
use App\Support\Surface;
use App\Users\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Ссылка на оплату счёта: наша, постоянная (`/pay/{code}`), отдаётся плательщику как есть. За ней —
 * попытки у провайдера (`AcquiringPayment`): каждое «Оплатить» берёт свежую или заводит новую, так что
 * ссылка не протухает вместе со страницей оплаты. У счёта открыта одна ссылка.
 */
#[Fillable(['code', 'invoice_id', 'amount', 'payer_kind', 'payer_user_id', 'payer_name', 'payer_phone', 'payer_email', 'state', 'payment_id', 'paid_at', 'canceled_at', 'error', 'error_at', 'created_by', 'auto'])]
class PayLink extends Model
{
    protected $table = 'billing_pay_links';

    /** Без 0/O, 1/l/I: ссылку диктуют по телефону. */
    private const ALPHABET = '23456789abcdefghjkmnpqrstuvwxyz';

    protected function casts(): array
    {
        return ['amount' => 'float', 'payer_kind' => PayerKind::class, 'state' => PayLinkState::class, 'paid_at' => 'datetime', 'canceled_at' => 'datetime', 'error_at' => 'datetime', 'auto' => 'bool'];
    }

    /**
     * Поиск ссылки на оплату — лупа «По ссылкам» и шапка CRM: плательщик (двойные пробелы не мешают), код ссылки и счёт
     * (`Invoice::search`: плательщик, менеджер, ТС словами у ручной). `deep: false` — без счёта: в шапке счета уже
     * найдены своим запросом, второй раз весь `Invoice::search` вложенным EXISTS не нужен.
     */
    public function scopeSearch(Builder $q, string|Term $term, bool $deep = true): Builder
    {
        $t = Term::of($term);
        if ($t->blank()) {
            return $q;
        }
        $like = '%'.str_replace(' ', '%', str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], self::squash($t->raw))).'%';

        return $q->where(fn ($s) => $s->where('billing_pay_links.payer_name', 'ilike', $like)
            ->orWhere('billing_pay_links.code', mb_strtolower($t->raw))
            ->when($deep, fn ($s) => $s->orWhereHas('invoice', fn ($i) => $i->search($t))));
    }

    public static function freshCode(): string
    {
        do {
            $code = '';
            foreach (str_split(random_bytes(12)) as $byte) {
                $code .= self::ALPHABET[ord($byte) % strlen(self::ALPHABET)];
            }
        } while (self::where('code', $code)->exists());

        return $code;
    }

    /** Страница оплаты открывается и без входа — демо-счёт по ссылке тоже виден. */
    /** Сумма ссылки по умолчанию: остаток к оплате, но не больше лимита одного платежа. */
    public static function defaultAmount(Invoice $invoice): float
    {
        $left = max(0, round($invoice->remaining() - $invoice->claimed(), 2));
        $max = (float) config('xcar.yookassa.max_amount');

        return $max > 0 ? min($left, $max) : $left;
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class)->withoutGlobalScope('demo');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function payerUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'payer_user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(AcquiringPayment::class, 'link_id')->orderBy('id');
    }

    public function isOpen(): bool
    {
        return $this->state === PayLinkState::Open;
    }

    public function url(): string
    {
        return Surface::Site->url('/pay/'.$this->code);
    }

    /**
     * Счёт, который платят по ссылке: наш (ПРАЙМ) к оплате по сделке или гаражу, шлюз подключён. Парковка (ИП),
     * вознаграждение от вендора, наши обязательства и демо — нет. Одна дверь для `EnsurePayLink` и экранов.
     */
    public static function eligible(Invoice $invoice): bool
    {
        return $invoice->state === InvoiceState::Issued && ! $invoice->isOwed() && ! $invoice->is_demo
            && $invoice->seller === Seller::Prime && $invoice->kind !== ChargeKind::Reward
            // Счёт ПРАЙМ за машину по сделке платят по счёту, без ссылки (Совкомбанк, 05.10.2026); ссылка — у подбора и доли.
            && ! ($invoice->deal_id && $invoice->kind === ChargeKind::Sale)
            && ($invoice->deal_id || $invoice->isService() || Car::ofInvoice($invoice)) && app(Gateway::class)->configured();
    }

    /** Адрес без схемы — так его читают и диктуют: «xcar.ru/pay/k3m…». */
    public function shortUrl(): string
    {
        return preg_replace('~^https?://~', '', $this->url());
    }

    /**
     * Что сейчас со ссылкой — одной строкой и тоном, для сотрудника и менеджера одинаково (05.10.2026: «непонятно,
     * оплатили или нет»): по последней попытке видно, открывал ли плательщик оплату и чем кончилось.
     *
     * @return array{string, ?string}
     */
    public function stateLine(): array
    {
        [$key, $at, $last] = $this->status();
        $when = fn () => $at->translatedFormat('j M, H:i');

        return match ($key) {
            'paid' => ['оплачено '.$at->translatedFormat('j M'), 'open'],
            'canceled' => ['отменена', 'muted'],
            'error' => ['ЮKassa не открыла оплату '.$when(), 'danger'],
            'new' => ['ждём оплату, ещё не открывали', null],
            'pending' => ['открыли оплату '.$when().', ждём', 'urgent'],
            'abandoned' => ['открывали '.$when().', не оплатили', 'urgent'],
            'declined' => ['не прошла '.$when().': '.$last->cancelLabel(), 'danger'],
            default => ['ждём оплату', null],
        };
    }

    /**
     * Что со ссылкой — один разбор на фразу (`stateLine`) и слово строки списка (`MoneyLinks::short`): ключ (paid, canceled,
     * error, new, pending, abandoned, declined, waiting), время события и последняя попытка плательщика.
     *
     * @return array{string, ?Carbon, ?AcquiringPayment}
     */
    public function status(): array
    {
        if ($this->state === PayLinkState::Paid) {
            return ['paid', $this->paid_at ?? $this->updated_at, null];
        }
        if ($this->state === PayLinkState::Canceled) {
            return ['canceled', $this->canceled_at, null];
        }
        $last = $this->relationLoaded('attempts') ? $this->attempts->last() : $this->attempts()->reorder()->latest('id')->first();

        return match (true) {
            $this->error_at && (! $last || $this->error_at->gt($last->created_at)) => ['error', $this->error_at, $last],
            ! $last => ['new', null, null],
            $last->isPending() => ['pending', $last->created_at, $last],
            $last->status === 'canceled' && $last->cancel_reason === 'expired_on_confirmation' => ['abandoned', $last->created_at, $last],
            $last->status === 'canceled' => ['declined', $last->created_at, $last],
            default => ['waiting', null, $last],
        };
    }

    /** Откуда ссылка: счёт сделки, машины гаража или разовая оплата, взятая кнопкой в «Оплатах». */
    public function origin(): string
    {
        $invoice = $this->invoice;

        return match (true) {
            $invoice->isService() => 'manual',
            (bool) $invoice->deal_id => 'deal',
            default => 'garage',
        };
    }

    /** Кто платит полным именем — для текста ссылки и строк «Оплат»; пробелы, вписанные дважды, схлопнуты. */
    public function payerName(): string
    {
        // Сам за себя — имя того, кто взял ссылку; его нет (удалён, скрыт демо-областью) — записанное имя и плательщик счёта,
        // а не пустая строка в списке и карточке.
        $name = ($this->payer_kind === PayerKind::Self ? $this->creator?->name : null)
            ?: $this->payer_name ?: $this->payerUser?->name ?: $this->invoice?->party?->name;

        return self::squash((string) $name);
    }

    public static function squash(string $name): string
    {
        return trim(preg_replace('/\s+/u', ' ', $name));
    }

    /**
     * Текст, которым ссылкой делятся (09.10.2026, владелец: «ФИО, марка модель номер, сумма, а не тот текст»): строками —
     * кто платит, ТС с номером, сумма, как оплатить; адрес строкой ниже дописывает `copy_controller`.
     */
    public function message(): string
    {
        return implode("\n", array_filter([
            $this->payerName(),
            $this->invoice?->carLine(),
            Money::exact($this->amount),
            'Оплата картой, СБП или SberPay по ссылке:',
        ]));
    }

    /** Кто платит — словами для строки: «вы», имя покупателя или человека. */
    public function payerLabel(?User $viewer = null): string
    {
        if ($this->payer_kind === PayerKind::Self) {
            return $viewer && $viewer->id === $this->created_by ? 'вы' : ($this->creator?->name ?? 'менеджер');
        }

        return $this->payer_name ?: $this->payerUser?->name ?: 'покупатель';
    }
}
