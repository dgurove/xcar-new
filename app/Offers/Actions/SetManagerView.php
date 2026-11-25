<?php

namespace App\Offers\Actions;

use App\Billing\Invoice;
use App\Cars\OwnerContacts;
use App\Mail\Message;
use App\Offers\Deal;
use App\Offers\Disclosure;
use App\Offers\ManagerView;
use App\Offers\OfferEventType;
use App\Users\User;
use App\Workflow\Outcome;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Что видит менеджер — одна дверь изменений (25.11.2026, `Offers\ManagerView`, окно `x-deal.manager-view`). Ключи пунктов: `contact`,
 * `file:{id}`, `invoice:{id}`, `contract`.
 * - **Показать**: контакт и документы — одним показом (`Disclose`: сообщение «Администрации XCAR» в чат с файлами, уведомление, разбор
 *   письма, галка шага, счёт поставщика), контакт — живой (`deals.manager_sees.contact`); наш счёт и договор — `ShowToManager`.
 * - **Скрыть**: контакт и документ пропадают у менеджера; сообщения в чате, где они были, — без них (`EditDisclosure`: прежнее
 *   удаляется, остальное уходит заново; было только оно — сообщение удаляется). Наш счёт и договор — «Отозвать».
 * `$lines` — строки письма менеджеру («Подписание: …», `SigningLetter`), `$text` — своё сообщение.
 */
final class SetManagerView
{
    public function __construct(private Disclose $disclose, private EditDisclosure $edit, private ShowToManager $show) {}

    /**
     * @param  list<string>  $show
     * @param  list<string>  $hide
     * @param  list<string>  $lines
     * @return array{shown: list<string>, hidden: list<string>, moved: bool}
     */
    /** `$reviews` — окно открыли из письма на разборе (строка «Письмо от …» в шаге): показ разбирает ждущие письма сделки. */
    public function __invoke(Deal $deal, User $by, array $show, array $hide = [], ?string $text = null, ?Message $letter = null, ?Outcome $step = null, array $lines = [], bool $reviews = false): array
    {
        $deal->loadMissing('offer');
        $items = collect(ManagerView::items($deal))->keyBy('key');
        $show = array_values(array_filter(array_unique($show), fn ($k) => isset($items[$k]) && ! $items[$k]['visible'] && $items[$k]['can']));
        $hide = array_values(array_filter(array_unique($hide), fn ($k) => isset($items[$k]) && $items[$k]['visible'] && $items[$k]['can'] && ! in_array($k, $show, true)));
        $label = fn ($k) => $items[$k]['label'];

        // Скрыть — сначала: сообщения без убранного уходят до нового показа.
        if ($hide) {
            $this->hide($deal, $by, $hide);
        }
        foreach (array_filter($hide, fn ($k) => $k === 'contract' || str_starts_with($k, 'invoice:')) as $k) {
            $k === 'contract' ? $this->show->recallContract($deal, $by) : $this->show->recallInvoice($deal, Invoice::findOrFail((int) substr($k, 8)), $by);
        }

        $disclose = [];
        $contact = in_array(ManagerView::CONTACT, $show, true);
        if ($contact) {
            foreach (OwnerContacts::of($deal->offer) as $c) {
                $c['name'] && $disclose[] = ['kind' => 'name', 'value' => $c['name']];
                $c['phone'] && $disclose[] = ['kind' => 'phone', 'value' => $c['phone']];
            }
            $deal->offer->inspection_address && $disclose[] = ['kind' => 'address', 'value' => $deal->offer->inspection_address];
        }
        foreach (array_filter($show, fn ($k) => str_starts_with($k, 'file:')) as $k) {
            $disclose[] = ['kind' => 'file', 'value' => $label($k), 'media' => (int) substr($k, 5)];
        }
        $text = trim((string) $text);
        $lines = array_values(array_filter(array_map(fn ($l) => trim((string) $l), $lines)));
        $words = [...$lines, ...($text !== '' ? [$text] : [])];
        $disclose = [...array_map(fn ($l) => ['kind' => 'text', 'value' => $l], $words), ...$disclose];
        $moved = false;
        if ($disclose || $step || $letter || $reviews) {
            $result = ($this->disclose)($deal, $by, $disclose, $step, null, $letter, card: $letter === null && ! $reviews,
                lead: $contact && $words === [] ? 'Контакт для получения ТС' : null);
            $moved = $result['moved'];
            // Контакт без телефона (одно имя) — `Disclose` флаг не ставит.
            if ($contact && ! ManagerView::sees($deal->fresh(), ManagerView::CONTACT)) {
                DB::table('deals')->where('id', $deal->id)->update(['manager_sees' => json_encode([ManagerView::CONTACT => now()->toDateTimeString()] + ($deal->fresh()->manager_sees ?? []))]);
            }
        }
        foreach (array_filter($show, fn ($k) => $k === 'contract' || str_starts_with($k, 'invoice:')) as $k) {
            $k === 'contract' ? $this->show->contract($deal, $by) : $this->show->invoice($deal, Invoice::findOrFail((int) substr($k, 8)), $by);
        }

        return ['shown' => array_map($label, $show), 'hidden' => array_map($label, $hide), 'moved' => $moved];
    }

    /** Контакт и документы — у менеджера их больше нет; сообщения в чате, где они были, — без них. @param list<string> $keys */
    private function hide(Deal $deal, User $by, array $keys): void
    {
        $contact = in_array(ManagerView::CONTACT, $keys, true);
        $files = array_map(fn ($k) => (int) substr($k, 5), array_filter($keys, fn ($k) => str_starts_with($k, 'file:')));
        if (! $contact && ! $files) {
            return;
        }
        $drop = fn (array $i) => ($contact && in_array($i['kind'] ?? null, ['name', 'phone', 'address'], true)) || (isset($i['media']) && in_array((int) $i['media'], $files, true));
        foreach (Disclosure::where('deal_id', $deal->id)->orderBy('id')->get() as $d) {
            $items = collect($d->items ?? []);
            if (! $items->contains($drop)) {
                continue;
            }
            $left = $items->reject($drop)->values();
            // Остались только строки заголовка без сути — показ целиком.
            ($this->edit)($d, $by, $left->isEmpty() ? null : $left->all());
        }
        DB::transaction(function () use ($deal, $contact, $files, $by) {
            if ($contact) {
                $sees = $deal->fresh()->manager_sees ?? [];
                unset($sees[ManagerView::CONTACT]);
                DB::table('deals')->where('id', $deal->id)->update(['manager_sees' => $sees ? json_encode($sees) : null]);
            }
            $deal->offer->getMedia('papers')->whereIn('id', $files)->each(fn ($m) => $m->setCustomProperty('managers', false)->save());
            $deal->offer->log(OfferEventType::Note, $by, ['text' => 'Скрыли от менеджера: '.implode(', ', array_filter([$contact ? 'контакт' : null, $files ? 'документов '.count($files) : null]))]);
        });
        Disclose::publish($deal);
    }

    /** Письмо не для менеджера: разбор закрыт, ход по письму снят. */
    public static function skip(Deal $deal, User $by): void
    {
        app(Disclose::class)->dismiss($deal, $by);
    }

    /** Ничего не выбрано и письма нет — нечего делать. */
    public static function guard(array $show, array $hide, ?string $text, ?Message $letter): void
    {
        if ($show === [] && $hide === [] && trim((string) $text) === '' && ! $letter) {
            throw ValidationException::withMessages(['view' => 'Ничего не изменилось']);
        }
    }
}
