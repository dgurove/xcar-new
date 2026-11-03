<?php

namespace App\Offers\Actions;

use App\Mail\Message;
use App\Notifications\DealInfoNotice;
use App\Notifications\YourTurnNotice;
use App\Offers\Deal;
use App\Offers\Disclosure;
use App\Offers\InsurerReplies;
use App\Offers\OfferEventType;
use App\Offers\ReplyReview;
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
    private const EXITS = ['поставщик согласовал', 'ответ поставщика получен'];

    /** Исход «просят реквизиты» (`Presets\Alfa::ASKS_REQUISITES`) — по письму, где их просят, а не по телефону. */
    private const REQUISITES = ['поставщик просит реквизиты'];

    public function __construct(private TakeExit $take) {}

    /** @param  list<array{kind: string, value: string, media?: int}>  $items  сверенные `Disclosures::picked` */
    public function __invoke(Deal $deal, User $by, array $items, ?Outcome $step = null): ?Disclosure
    {
        [$disclosure, $turn] = DB::transaction(function () use ($deal, $by, $items, $step) {
            $deal = Deal::whereKey($deal->id)->lockForUpdate()->firstOrFail();
            $reviews = ReplyReview::pending()->where('deal_id', $deal->id)->with('message')->get();
            $disclosure = $items ? Disclosure::create(['deal_id' => $deal->id, 'items' => $items, 'message_ids' => $reviews->pluck('message_id')->all() ?: null, 'created_by' => $by->id]) : null;
            $files = array_filter(array_map(fn ($i) => $i['media'] ?? null, $items));
            Media::whereIn('id', $files)->get()->each(fn (Media $m) => $m->setCustomProperty('managers', true)->save());
            $this->done($reviews, $by);
            $letter = $reviews->max('message_id');
            $moved = $step && $step->is(self::stepExit($deal, $reviews->pluck('message')->filter())) && ($this->take)($deal->offer, $step, Actor::Staff, $by, ['letter' => $letter]);
            $disclosure && $deal->offer->log(OfferEventType::Note, $by, ['text' => 'Показали менеджеру: '.$disclosure->summary()]);

            return [$disclosure, $moved ? Requirement::where('deal_id', $deal->id)->whereNull('done_at')->latest('id')->first() : null];
        });
        if ($buyer = $deal->buyer) {
            if ($disclosure) {
                $buyer->notify(new DealInfoNotice($disclosure, $turn));
            } elseif ($turn) {
                $buyer->notify(new YourTurnNotice($turn->load('offer')));
            }
        }

        return $disclosure;
    }

    /** «Ничего не показывать»: письма разобраны, менеджеру — ничего. */
    public function dismiss(Deal $deal, User $by): void
    {
        $this->done(ReplyReview::pending()->where('deal_id', $deal->id)->get(), $by);
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

        return ($letters->contains(fn (Message $m) => InsurerReplies::asksRequisites($m)) ? $find(self::REQUISITES) : null) ?? $find(self::EXITS);
    }

    /** Галка шага отмечена сама там, где раньше шаг проходило письмо: в нём телефон или просьба реквизитов. */
    public static function stepByDefault(Outcome $exit, Collection $letters): bool
    {
        return in_array(mb_strtolower(trim($exit->label)), self::REQUISITES, true)
            || $letters->contains(fn (Message $m) => InsurerReplies::hasContact($m));
    }

    private function done(Collection $reviews, User $by): void
    {
        $reviews->isNotEmpty() && ReplyReview::whereIn('id', $reviews->pluck('id'))->update(['state' => ReplyReview::DONE, 'done_by' => $by->id, 'done_at' => now()]);
    }
}
