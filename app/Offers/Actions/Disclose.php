<?php

namespace App\Offers\Actions;

use App\Chats\Actions\OpenChat;
use App\Chats\Actions\PlatformMessage;
use App\Chats\StepAsk;
use App\Live\Publisher;
use App\Live\Topics;
use App\Mail\Message;
use App\Notifications\DealInfoNotice;
use App\Notifications\YourTurnNotice;
use App\Offers\Deal;
use App\Offers\Disclosure;
use App\Offers\InsurerReplies;
use App\Offers\OfferEventType;
use App\Offers\ReplyReview;
use App\Offers\SigningLetter;
use App\Offers\SupplierInvoice;
use App\Support\Nav;
use App\Users\User;
use App\Vendors\DealFormat;
use App\Workflow\Actions\TakeExit;
use App\Workflow\Actor;
use App\Workflow\Outcome;
use App\Workflow\Requirement;
use App\Workflow\Track;
use App\Workflow\WaitsFor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * «Показать менеджеру» (03.11.2026, владелец: «админ отмечает, что из этого показать, жмёт кнопку — и только тогда менеджеру
 * приходит уведомление и показывается ТОЛЬКО то, что выбрано»). Под замком сделки одной транзакцией: строка `Disclosure` с
 * выбранным (правленым), документам — «Видно менеджеру», ждущие разборы сделки — разобраны; галка шага — исход «поставщик
 * согласовал» / «ответ получен» / «просит реквизиты», который раньше жало письмо само (`OnInsurerReply`). После — показанное
 * сообщением в чат сделки (`toChat`), без пунктов и с шагом — «Ваш ход». «Ничего не показывать» — `dismiss`.
 */
final class Disclose
{
    // Альфа после оплаты (11.11.2026): «договор готов» и «запись подтверждена» — письмами. Свободное время — не галкой, а
    // шторкой слотов (`OfferSigningSlots`).
    private const EXITS = ['поставщик согласовал', 'ответ поставщика получен', 'договор готов', 'запись подтверждена'];

    /** Исход «просят реквизиты» (`Presets\Alfa::ASKS_REQUISITES`) — по письму, где их просят, а не по телефону. */
    private const REQUISITES = ['поставщик просит реквизиты'];

    public function __construct(private TakeExit $take) {}

    /**
     * @param  list<array{kind: string, value: string, media?: int}>  $items  сверенные `Disclosures::picked`
     * @return array{disclosure: ?Disclosure, moved: bool} показали и сдвинули ли шаг (галка шага устарела — не сдвигаем)
     */
    public function __invoke(Deal $deal, User $by, array $items, ?Outcome $step = null, ?int $invoice = null, ?Message $message = null): array
    {
        [$disclosure, $moved, $turn] = DB::transaction(function () use ($deal, $by, $items, $step, $invoice, $message) {
            $deal = Deal::whereKey($deal->id)->lockForUpdate()->firstOrFail();
            // Показ одного письма из окна писем (16.11.2026) разбирает его и письма не новее него (17.11.2026, владелец по сделке 123:
            // «показано же менеджеру, зачем всё ещё висит „Показать менеджеру?“» — Альфа 14.11 просила коллегу прислать ПТС, а ответ
            // коллеги с ЭПТС 16.11 показали из окна); пришедшие после него ждут.
            $reviews = ReplyReview::pending()->where('deal_id', $deal->id)
                ->when($message, fn ($q) => $q->where(fn ($w) => $w->where('message_id', $message->id)
                    ->when($message->date_at, fn ($w) => $w->orWhereHas('message', fn ($m) => $m->where('date_at', '<=', $message->date_at)))))
                ->with('message')->get();
            $letters = $message ? collect([$message]) : $reviews->pluck('message')->filter();
            $files = array_filter(array_map(fn ($i) => $i['media'] ?? null, $items));
            // Счёт поставщика (`SupplierInvoice`): выбранный файлом (`$invoice` — шторка «Счёт поставщика») или, у галки шага на оплату
            // поставщику менеджером, отмеченные документы письма. Без счёта такой шаг не проходит.
            $billed = $invoice ? [$invoice] : ($step && SupplierInvoice::needs($deal, $step) ? $files : []);
            if (($invoice && ! in_array($invoice, $files, true)) || ($step && SupplierInvoice::needs($deal, $step) && $billed === [])) {
                throw ValidationException::withMessages(['disclose' => 'Отметьте счёт поставщика — без него менеджеру нечего оплатить']);
            }
            if ($billed) {
                $marked = Media::whereIn('id', $billed)->get()->each(fn (Media $m) => SupplierInvoice::mark($m))->keyBy('id');
                $items = array_map(fn ($i) => isset($i['media'], $marked[$i['media']]) ? ['value' => $marked[$i['media']]->name] + $i : $i, $items);
            }
            $disclosure = $items ? Disclosure::create(['deal_id' => $deal->id, 'items' => $items, 'message_ids' => $letters->pluck('id')->values()->all() ?: null, 'created_by' => $by->id]) : null;
            Media::whereIn('id', $files)->get()->each(fn (Media $m) => $m->setCustomProperty('managers', true)->save());
            $this->done($reviews, $by);
            $letter = $letters->max('id');
            $fits = $invoice ? $step?->stage_id === $deal->offer->position(Track::Sale)?->stage_id : $step?->is(self::stepExit($deal, $letters));
            $moved = $step && $fits && ($this->take)($deal->offer, $step, Actor::Staff, $by, $letter ? ['letter' => $letter] : []);
            // «Договор готов» письмом (11.11.2026, `SigningLetter`): адрес филиала — сделке, а филиал — вендору, если пуст.
            $moved && mb_strtolower(trim($step->label)) === 'договор готов' && self::keepOffice($deal, $letters);
            $disclosure && $deal->offer->log(OfferEventType::Note, $by, ['text' => 'Показали менеджеру: '.$disclosure->summary()]);

            return [$disclosure, (bool) $moved, $moved ? Requirement::where('deal_id', $deal->id)->whereNull('done_at')->latest('id')->first() : null];
        });
        self::publish($deal);
        if ($buyer = $deal->buyer) {
            // Показанное — сообщением в чат сделки; чата у менеджера нет — уведомлением, как раньше (с его ходом в нём же).
            $chat = $disclosure && self::toChat($deal->fresh(['offer', 'buyer']), $disclosure, $turn);
            if ($disclosure && ! $chat) {
                $buyer->notify(new DealInfoNotice($disclosure, $turn));
            } elseif ($turn) {
                $buyer->notify(new YourTurnNotice($turn->load('offer')));
            }
            // Новый ход — и кнопками в чате сделки (`StepAsk`), как на любом шаге: после сообщения с показанным.
            $turn && StepAsk::post($turn);
        }
        // Ответ на доп. просьбу показали — ход менеджера: «Беру в работу» / «Я передумал» (18.11.2026, `Offers\ExtraAsk`). Шаг сдвинулся
        // на согласие — его просьба с теми же кнопками уже ушла, второй не шлём.
        $disclosure && app(SettleExtraAsk::class)->shown($deal, notify: ! $turn);

        return ['disclosure' => $disclosure, 'moved' => $moved];
    }

    /**
     * Показанное — сообщением «Администрации XCar» в чат сделки (17.11.2026, владелец: «нажал „Показать менеджеру“ — ему в чат и
     * телегу ничего не пришло, должно же было»): сообщение менеджеру, ниже имя, телефон и адрес строками, документы и фото —
     * вложениями. Сообщение чата само уходит в ленту, пуш и Telegram с файлами (`Notify::chat`), отдельного `DealInfoNotice` нет.
     * Счёт поставщика, что `StepAsk` на шаге оплаты приложит сам, второй раз не прикладывается. Чат менеджеру закрыт — false.
     */
    private static function toChat(Deal $deal, Disclosure $disclosure, ?Requirement $turn): bool
    {
        $offer = $deal->offer;
        if (! $offer || ! $offer->chatOpenFor($deal->buyer)) {
            return false;
        }
        $items = collect($disclosure->items ?? []);
        $lines = $items->where('kind', 'text')->merge($items->whereIn('kind', ['name', 'phone', 'address']))
            ->map(fn ($i) => trim((string) $i['value']))->filter()->values();
        $files = $disclosure->files();
        if ($turn?->stage && $deal->paysSupplierOn($turn->stage)) {
            $bill = SupplierInvoice::shown($offer)->take(1)->pluck('id');
            $files = $files->reject(fn (Media $m) => $bill->contains($m->id))->values();
        }
        if ($lines->isNotEmpty() || $files->isNotEmpty()) {
            app(PlatformMessage::class)(app(OpenChat::class)($offer, $deal->buyer, greet: false), $lines->implode("\n") ?: 'Документы по сделке', media: $files);
        }

        return true;
    }

    /** «Ничего не показывать»: письма разобраны, менеджеру — ничего. Под тем же замком, что и показ. */
    public function dismiss(Deal $deal, User $by): void
    {
        DB::transaction(function () use ($deal, $by) {
            Deal::whereKey($deal->id)->lockForUpdate()->firstOrFail();
            $this->done(ReplyReview::pending()->where('deal_id', $deal->id)->get(), $by);
        });
        self::publish($deal);
    }

    /**
     * Живое обновление (после коммита, `Publisher`): строка сделки в «Сделках» CRM и редактор, у менеджера — его сделка
     * (показали — блок «Информация по сделке» встаёт сам). Числа таб-бара CRM — заново.
     */
    public static function publish(Deal $deal): void
    {
        $live = app(Publisher::class);
        $live->rows(Topics::STAFF, ['/offers' => [$deal->offer->number]]);
        $live->refresh(Topics::STAFF, ['/offers/'.$deal->offer->number]);
        $deal->buyer_id && $live->refresh(Topics::user($deal->buyer_id), [$deal->href(), '/deals']);
        Nav::forgetStaffCounts();
    }

    /**
     * Какой исход предложить галкой: шаг ждёт поставщика, а у него — «поставщик согласовал» / «ответ поставщика получен» или,
     * когда письмо просит реквизиты, «поставщик просит реквизиты». Оптом и без писем — нет.
     *
     * @param  Collection<int, Message>  $letters
     */
    public static function stepExit(Deal $deal, Collection $letters): ?Outcome
    {
        // Шаг перечитывается только в действии (он меняется посреди запроса); на показ — уже загруженный: окно сделки
        // спрашивает исход дважды, и каждый раз это было пять запросов маршрута.
        $shown = ! app()->runningInConsole() && request()->isMethod('GET');
        $offer = $shown ? $deal->offer : $deal->offer->unsetRelation('positions');
        $stage = $offer->position(Track::Sale)?->stage;
        if ($letters->isEmpty() || $offer->vendor?->deal_format === DealFormat::Wholesale || $stage?->waits_for !== WaitsFor::Supplier) {
            return null;
        }
        $find = fn (array $labels) => $stage->exitsFor(Actor::Staff, $deal)->first(fn (Outcome $x) => in_array(mb_strtolower(trim($x->label)), $labels, true));

        // Ждём счёт, а письмо с файлом — «Счёт от поставщика получен» (10.11.2026, 0790/046/11079/26: счёт пришёл, а шаг жали
        // отдельно и менеджер его не видел).
        // Счёт приходит только после нашего письма шага (реквизитов): письмо с файлом раньше него — не счёт (17.11.2026, CTTM
        // 5491/046/00861/26: ЭПТС от 16.11 встал «Счёт от поставщика получен», хотя реквизиты Альфе ещё не уходили).
        $position = $offer->position(Track::Sale);
        $sentAt = $stage->isLetterStep() ? $position?->letterAt() : null;
        $after = fn (Message $m) => ! $stage->isLetterStep() || ($sentAt && $m->date_at && $m->date_at->gt($sentAt));
        $billed = $letters->contains(fn (Message $m) => InsurerReplies::hasFiles($m) && $after($m))
            ? $stage->exitsFor(Actor::Staff, $deal)->first(fn (Outcome $x) => SupplierInvoice::needs($deal, $x)) : null;

        return ($letters->contains(fn (Message $m) => InsurerReplies::asksRequisites($m)) ? $find(self::REQUISITES) : null) ?? $billed ?? $find(self::EXITS);
    }

    /** Галка шага отмечена сама там, где раньше шаг проходило письмо: в нём телефон или просьба реквизитов. */
    public static function stepByDefault(Outcome $exit, Collection $letters, ?Deal $deal = null): bool
    {
        return in_array(mb_strtolower(trim($exit->label)), self::REQUISITES, true)
            || (mb_strtolower(trim($exit->label)) === 'договор готов' && $letters->contains(fn (Message $m) => SigningLetter::ready($m)))
            || SupplierInvoice::needs($deal, $exit)
            || $letters->contains(fn (Message $m) => InsurerReplies::hasContact($m));
    }

    /** @param  Collection<int, Message>  $letters */
    private static function keepOffice(Deal $deal, Collection $letters): void
    {
        $office = $letters->filter(fn (Message $m) => SigningLetter::ready($m))->map(fn (Message $m) => SigningLetter::office($m))->filter()->last();
        if (! $office) {
            return;
        }
        $deal->signing_address || $deal->forceFill(['signing_address' => $office['address']])->save();
        $vendor = $deal->offer->vendor;
        if ($vendor && empty($vendor->signing_office['address'])) {
            $vendor->forceFill(['signing_office' => $office])->save();
        }
    }

    private function done(Collection $reviews, User $by): void
    {
        $reviews->isNotEmpty() && ReplyReview::whereIn('id', $reviews->pluck('id'))->update(['state' => ReplyReview::DONE, 'done_by' => $by->id, 'done_at' => now()]);
    }
}
