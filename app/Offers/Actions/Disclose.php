<?php

namespace App\Offers\Actions;

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
 * согласовал» / «ответ получен» / «просит реквизиты», который раньше жало письмо само (`OnInsurerReply`). После — менеджеру
 * `DealInfoNotice` с его новым ходом, без пунктов и с шагом — «Ваш ход». «Ничего не показывать» — `dismiss`.
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
    public function __invoke(Deal $deal, User $by, array $items, ?Outcome $step = null, ?int $invoice = null): array
    {
        [$disclosure, $moved, $turn] = DB::transaction(function () use ($deal, $by, $items, $step, $invoice) {
            $deal = Deal::whereKey($deal->id)->lockForUpdate()->firstOrFail();
            $reviews = ReplyReview::pending()->where('deal_id', $deal->id)->with('message')->get();
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
            $disclosure = $items ? Disclosure::create(['deal_id' => $deal->id, 'items' => $items, 'message_ids' => $reviews->pluck('message_id')->all() ?: null, 'created_by' => $by->id]) : null;
            Media::whereIn('id', $files)->get()->each(fn (Media $m) => $m->setCustomProperty('managers', true)->save());
            $this->done($reviews, $by);
            $letter = $reviews->max('message_id');
            $fits = $invoice ? $step?->stage_id === $deal->offer->position(Track::Sale)?->stage_id : $step?->is(self::stepExit($deal, $reviews->pluck('message')->filter()));
            $moved = $step && $fits && ($this->take)($deal->offer, $step, Actor::Staff, $by, $letter ? ['letter' => $letter] : []);
            // «Договор готов» письмом (11.11.2026, `SigningLetter`): адрес филиала — сделке, а филиал — вендору, если пуст.
            $moved && mb_strtolower(trim($step->label)) === 'договор готов' && self::keepOffice($deal, $reviews->pluck('message')->filter());
            $disclosure && $deal->offer->log(OfferEventType::Note, $by, ['text' => 'Показали менеджеру: '.$disclosure->summary()]);

            return [$disclosure, (bool) $moved, $moved ? Requirement::where('deal_id', $deal->id)->whereNull('done_at')->latest('id')->first() : null];
        });
        self::publish($deal);
        if ($buyer = $deal->buyer) {
            if ($disclosure) {
                $buyer->notify(new DealInfoNotice($disclosure, $turn));
            } elseif ($turn) {
                $buyer->notify(new YourTurnNotice($turn->load('offer')));
            }
            // Новый ход — и кнопками в чате сделки (`StepAsk`), как на любом шаге.
            $turn && StepAsk::post($turn);
        }

        return ['disclosure' => $disclosure, 'moved' => $moved];
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
        $live->rows(Topics::STAFF, ['/work/deals' => [$deal->id]]);
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
        $offer = $deal->offer->unsetRelation('positions');
        $stage = $offer->position(Track::Sale)?->stage;
        if ($letters->isEmpty() || $offer->vendor?->deal_format === DealFormat::Wholesale || $stage?->waits_for !== WaitsFor::Supplier) {
            return null;
        }
        $find = fn (array $labels) => $stage->exitsFor(Actor::Staff, $deal)->first(fn (Outcome $x) => in_array(mb_strtolower(trim($x->label)), $labels, true));

        // Ждём счёт, а письмо с файлом — «Счёт от поставщика получен» (10.11.2026, 0790/046/11079/26: счёт пришёл, а шаг жали
        // отдельно и менеджер его не видел).
        $billed = $letters->contains(fn (Message $m) => InsurerReplies::hasFiles($m))
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
