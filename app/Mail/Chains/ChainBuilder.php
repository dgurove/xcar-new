<?php

namespace App\Mail\Chains;

use App\Mail\Actions\ArchiveThread;
use App\Mail\Actions\CloseChain;
use App\Mail\Actions\FreezeMessages;
use App\Mail\Candidate;
use App\Mail\CandidateStage;
use App\Mail\CandidateState;
use App\Mail\Direction;
use App\Mail\Extraction\Code;
use App\Mail\Extraction\Extractor;
use App\Mail\Extraction\Intent;
use App\Mail\Extraction\ParkExtractor;
use App\Mail\Extraction\Patterns;
use App\Mail\Extraction\QuotationStripper;
use App\Mail\Extraction\ScanFields;
use App\Mail\Jobs\ImportCandidateFiles;
use App\Mail\Message;
use App\Mail\Reading\ReadLetter;
use App\Mail\Scope;
use App\Mail\Thread;
use App\Offers\Actions\AutoOffer;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Park\Actions\AutoRequest;
use App\Park\City;
use App\Park\CityGuess;
use App\Park\Events\CandidateArrived;
use App\Park\Vehicle;
use App\Park\VehicleState;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Цепочка «Из писем» (`mail_candidates`) — производное от прочитанных писем: письма, связанные общим номером
 * убытка, VIN, госномером или веткой, лежат в одной цепочке (`mail_candidate_messages`); её поля, номер,
 * заказчик и этапы — свёртка `fold()` по этим письмам. Ничего не «дописывается» и не «чинится»: новое письмо
 * находит цепочки по индексу `mail_message_keys`, объединяет их, если связало две, и цепочка сворачивается заново.
 * Рукотворное — состояние (ждёт / архив / заведена), ТС, архив веток — свёртка не трогает. Закрытая цепочка
 * (`closed`: выдана или реализована) — конечная: её письма заморожены (`FreezeMessages`), в пересборку не идут,
 * новое письмо о той же машине начинает новую цепочку.
 */
final class ChainBuilder
{
    public function __construct(private ReadLetter $reader, private ArchiveThread $archive, private CloseChain $close, private FreezeMessages $freeze) {}

    /**
     * Письмо в цепочку. `force` — руками из почты: цепочка заводится, даже если письмо не похоже на заявку.
     * Возвращает цепочку, куда легло письмо (первую, если о нескольких машинах), или null.
     */
    public function attach(Message $message, bool $force = false, bool $quiet = false, bool $files = true): ?Candidate
    {
        return City::unscoped(fn () => $this->attachNow($message, $force, $quiet, $files));
    }

    private function attachNow(Message $message, bool $force, bool $quiet, bool $files): ?Candidate
    {
        $message->loadMissing(['account', 'thread', 'attachments']);
        if (! $message->isRead()) {
            $this->reader->apply($message);
        }

        // Планировщик и воркер IDLE принимают письма одного ящика параллельно: два письма одной ветки на двух
        // процессах не найдут цепочку друг друга — по одному письму за раз на ящик.
        $fresh = false;
        $candidate = Cache::lock('chains:'.$message->account_id, 60)->block(60, function () use ($message, $force, $quiet, $files, &$fresh) {
            return $this->place($message, $force, $quiet, $files, $fresh);
        });
        // Новая цепочка — людям: парковке сначала заводится заявка (`AutoRequest`, вне замка ящика — там импорт файлов),
        // CRM — черновик (`AutoOffer`, когда уверены, что это предложение), и уведомление уже о них; иначе «Новое из
        // писем» модераторам (Notify::candidateArrived). История ящика — молча.
        if ($fresh && ! $quiet && $candidate) {
            try {
                $candidate->scope === Scope::Park ? app(AutoRequest::class)($candidate) : app(AutoOffer::class)($candidate);
            } catch (Throwable $e) {
                // Не завелась сама — цепочка ждёт человека в «Из писем», а письмо всё равно принято.
                report($e);
            }
            CandidateArrived::dispatch($candidate->fresh());
        }

        return $fresh ? $candidate?->fresh() : $candidate;
    }

    private function place(Message $message, bool $force, bool $quiet, bool $files, bool &$fresh): ?Candidate
    {
        $park = $message->account->scope === Scope::Park;
        $scope = $park ? Scope::Park : Scope::Offers;
        if (! $park && $message->direction !== Direction::In) {
            return null;
        }
        $fields = $message->fields();
        $keys = $message->keys();
        $codes = array_values(array_filter($keys, fn ($k) => str_starts_with($k, 'code:')));
        $matches = $this->matches($scope, $keys, $codes, $message->thread_id);

        if ($matches->isEmpty()) {
            if (! $force && ! $this->starts($message, $park, $fields)) {
                return null;
            }
            $candidate = Candidate::create([
                // Состояние явно: умолчание базы в модель не возвращается, и `AutoRequest` видел у новой цепочки пустое
                // состояние вместо «ждёт» — заявки заводили только часы через пять минут.
                'scope' => $scope, 'state' => CandidateState::New, 'key' => Candidate::strongest($keys) ?? 'message:'.$message->id, 'message_id' => $message->id, 'thread_id' => $message->thread_id,
                'subject' => $message->subject, 'extracted' => $fields, 'messages_count' => 0, 'last_message_at' => $message->date_at ?? now(),
            ]);
            $matches = collect([$candidate]);
            $fresh = true;
        } elseif (count($codes) > 1) {
            // «Выдать ТС А и Б» — письмо о нескольких машинах: каждой в цепочку, но цепочки не сливаются.
            $fresh = false;
        } else {
            $matches = collect([$this->merge($matches)]);
            $fresh = false;
        }

        foreach ($matches as $candidate) {
            if (! $candidate->messages()->whereKey($message->id)->exists()) {
                $candidate->messages()->attach($message->id, ['created_at' => now()]);
                // Цепочка в архиве («не заявка»), а вендор написал снова — или сотрудник заново переслал его письмо (20.10.2026:
                // Катя переслала 677209/2026 повторно, а цепочка осталась в архиве, и её никто не увидел): снова ждёт, как
                // новая — с «Новое из писем» и черновиком, если уверены. История ящика (`quiet`) и служебные письма — нет.
                if (! $quiet && $candidate->state === CandidateState::Rejected && $message->isFromVendor()
                    && ! Intent::isService($message->subject, $message->from_email) && ! in_array($message->intent, ['auto', 'billing'], true)) {
                    $candidate->update(['state' => CandidateState::New]);
                    $this->archive->candidate($candidate, false);
                    $fresh = true;
                }
            }
            $this->fold($candidate);
            $candidate = $candidate->fresh();
            if ($files && $candidate->stage !== CandidateStage::Released && $candidate->state !== CandidateState::Closed && $message->direction === Direction::In) {
                ImportCandidateFiles::dispatch($candidate->id, $message->id);
            }
        }

        return $matches->first();
    }

    /**
     * Свёртка цепочки по её письмам: поля — первое непустое по письмам вендора по дате, марка и госномер ещё и из
     * имён файлов наших ответов; номер, заказчик, тема — оттуда же; этапы — по смыслам писем; выданная — в архив.
     */
    public function fold(Candidate $candidate): void
    {
        City::unscoped(fn () => $this->foldNow($candidate));
    }

    private function foldNow(Candidate $candidate): void
    {
        $messages = $candidate->messages()->with(['account', 'attachments'])->get();
        if ($messages->isEmpty() || $candidate->state === CandidateState::Closed) {
            return;
        }
        // Цепочка из архива снова живая (новое письмо вендора): её письма замораживались — читаются заново.
        if ($messages->whereNotNull('frozen_at')->isNotEmpty()) {
            $this->freeze->thaw($messages->whereNotNull('frozen_at')->pluck('id')->all());
            $messages = $candidate->messages()->with(['account', 'attachments'])->get();
        }
        $live = $messages->reject(fn (Message $m) => $m->intent === Intent::Billing->value || Intent::silent($m->intent));
        // Пересылка сотрудником письма вендора — поля вендора (страховая, цена, адрес), а не только марка и номер.
        $theirs = $live->filter(fn (Message $m) => ! $m->isOurs() || $m->isForwardedByStaff());
        $ours = $live->filter(fn (Message $m) => $m->isOurs() && ! $m->isForwardedByStaff());
        // Письмо о нескольких машинах («выдать ТС А и Б») лежит в обеих цепочках — его VIN и госномер про одну из них, полей не даёт.
        $single = fn (Message $m) => count(array_filter($m->keys(), fn ($k) => str_starts_with($k, 'code:'))) <= 1;
        // Выбранное человеком в «✨ Распознать» — поверх писем: новое письмо его не перебивает.
        $fields = $candidate->chosen ?? [];
        foreach ($theirs->filter($single) as $m) {
            $fields += $m->fields();
        }
        foreach ($theirs->reject($single) as $m) {
            $fields += array_diff_key($m->fields(), array_flip(['vin', 'plate', 'brand', 'model', 'year', 'code']));
        }
        foreach ($ours as $m) {
            $fields += array_intersect_key($m->fields(), array_flip(['brand', 'model', 'plate', 'vin', 'year', 'code']));
        }
        // «Когда привезут» — по последнему письму с датой: наш ответ «прием назначен на 15.04 в 15:00» переопределяет дату вендора.
        $dated = $live->filter(fn (Message $m) => $m->field('planned_at') && in_array($m->intent, [Intent::Intake->value, Intent::Accepted->value], true))->sortBy('date_at')->last();
        if ($dated) {
            $fields['planned_at'] = $dated->fields()['planned_at'];
        }
        foreach ($messages->diff($live) as $m) {
            $fields += array_intersect_key($m->fields(), array_flip(['vendor_id', 'vendor'])); // хоть заказчика с автоответа
        }
        $first = $theirs->first() ?? $messages->first();
        $keys = $messages->flatMap(fn (Message $m) => $m->keys())->unique()->values()->all();
        $stages = $this->stages($live);
        $last = $stages ? end($stages)['stage'] : CandidateStage::Intake->value;
        $latestTheirs = $theirs->last();

        $candidate->forceFill([
            'code' => isset($fields['code']['value']) ? Code::normalize($fields['code']['value']) : null,
            'key' => Candidate::strongest([...$keys, 'thread:'.$first->thread_id]) ?? 'message:'.$first->id,
            'vendor_id' => $fields['vendor_id']['value'] ?? null,
            'message_id' => $first->id, 'thread_id' => $first->thread_id, 'subject' => $first->subject,
            'extracted' => $fields,
            // «Пришло ещё письмо — проверьте поля»: поля последнего письма вендора отличаются от свёртки.
            'proposed' => $latestTheirs && $latestTheirs->id !== $first->id ? $latestTheirs->fields() : null,
            'messages_count' => $messages->count(), 'last_message_at' => $messages->max('date_at') ?? now(),
            'stages' => $stages, 'stage' => $last,
        ]);
        // Город цепочки парковки — по её письмам (`Park\CityGuess`): адрес, контакт, отправитель, вендор; решение человека остаётся.
        if ($candidate->scope === Scope::Park && $candidate->city_source !== CityGuess::MANUAL) {
            [$candidate->city_id, $candidate->city_source] = CityGuess::letters($theirs, $candidate->vendor_id);
        }
        $candidate->saveQuietly();

        // Ветки писем — за цепочкой (секция в почте), кроме уже привязанных к ТС или предложению.
        Thread::whereIn('id', $messages->pluck('thread_id')->filter()->unique())->whereNull('candidate_id')->whereNull('vehicle_id')->whereNull('offer_id')
            ->update(['candidate_id' => $candidate->id]);

        // Выдана и вендор после не писал — заводить нечего, цепочка закрыта: письма заморожены, в пересборку не идут.
        if ($last === CandidateStage::Released->value && in_array($candidate->state ?? CandidateState::New, [CandidateState::New, CandidateState::Rejected], true)) {
            ($this->close)($candidate, 'released');
        }
    }

    /** Слить цепочки, которые связало одно письмо: письма и ветки — к старшей, младшие стираются. */
    private function merge(Collection $matches): Candidate
    {
        $matches = $matches->sortBy('id')->values();
        /** @var Candidate $keep */
        $keep = $matches->first();
        foreach ($matches->slice(1) as $dup) {
            /** @var Candidate $dup */
            $keep->messages()->syncWithoutDetaching($dup->messages()->pluck('mail_messages.id')->all());
            Thread::where('candidate_id', $dup->id)->update(['candidate_id' => $keep->id]);
            if ($dup->state === CandidateState::New && $keep->state === CandidateState::Rejected) {
                $keep->forceFill(['state' => CandidateState::New])->saveQuietly();
            }
            $dup->messages()->detach();
            $dup->delete();
        }

        return $keep;
    }

    /**
     * Открытые цепочки, с которыми письмо связано: по номерам (индекс) и по ветке. Письмо с номером убытка
     * к цепочке с другим номером не идёт, даже если попало в её ветку (вендор ответил не в ту ветку).
     *
     * @return Collection<int, Candidate>
     */
    private function matches(Scope $scope, array $keys, array $codes, ?int $threadId): Collection
    {
        $open = fn () => Candidate::where('scope', $scope)->whereIn('state', [CandidateState::New, CandidateState::Rejected]);
        $ids = collect();
        if ($keys) {
            $ids = $ids->merge(DB::table('mail_candidate_messages as cm')->join('mail_message_keys as mk', 'mk.message_id', '=', 'cm.message_id')
                ->whereIn('mk.key', $keys)->distinct()->pluck('cm.candidate_id'));
            // И по ключу или прежним номерам самой цепочки: при пересборке состав пуст, а решение «в архив» у строки есть.
            $ids = $ids->merge($open()->where(fn ($q) => $q->whereIn('key', $keys)->orWhere(fn ($w) => $this->wherePriorKeys($w, $keys)))->pluck('id'));
        }
        if ($threadId) {
            $ids = $ids->merge(DB::table('mail_candidate_messages as cm')->join('mail_messages as m', 'm.id', '=', 'cm.message_id')
                ->where('m.thread_id', $threadId)->distinct()->pluck('cm.candidate_id'));
            $ids = $ids->merge(Thread::whereKey($threadId)->whereNotNull('candidate_id')->pluck('candidate_id'));
        }
        $candidates = $ids->isEmpty() ? collect() : $open()->whereIn('id', $ids->unique()->all())->orderBy('id')->get();
        if ($codes) {
            $mine = array_map(fn ($k) => substr($k, 5), $codes);
            $candidates = $candidates->filter(fn (Candidate $c) => ! $c->code || in_array(Code::key($c->code), $mine, true));
        }

        return $candidates->values();
    }

    /** Цепочка без писем (пересборка), у которой в прежних полях тот же номер, VIN или госномер. */
    private function wherePriorKeys($query, array $keys): void
    {
        $query->whereDoesntHave('messages');
        $query->where(function ($q) use ($keys) {
            $q->whereRaw('false');
            foreach ($keys as $key) {
                [$kind, $value] = explode(':', $key, 2) + [null, null];
                match ($kind) {
                    'code' => $q->orWhereRaw("lower(regexp_replace(coalesce(code, ''), '[^[:alnum:]]', '', 'g')) = ?", [$value]),
                    'vin' => $q->orWhereRaw("upper(extracted->'vin'->>'value') = ?", [$value]),
                    'plate' => $q->orWhereRaw("upper(replace(extracted->'plate'->>'value', ' ', '')) = ?", [$value]),
                    default => null,
                };
            }
        });
    }

    /** Письмо начинает цепочку: заявка от вендора о машине, которой у нас ещё нет. */
    private function starts(Message $message, bool $park, array $fields): bool
    {
        // Цепочку начинает письмо вендора с номером, VIN или госномером; бухгалтерия, автоответ и «прочее» не от вендора — нет.
        // В CRM письмо извне о машине — и с одной маркой, без ключей (19.10.2026: «Годные Мазда 3» от ТИТ, у него «б/н»).
        if ($message->direction !== Direction::In) {
            return false;
        }
        if (! $message->keys()) {
            return ! $park && $this->carFromOutside($message, $fields);
        }
        if ($park && ($message->intent === Intent::Billing->value || Intent::silent($message->intent))) {
            return false;
        }
        if ($park && ! isset($fields['vendor_id']) && $message->intent === Intent::Other->value) {
            return false;
        }
        if ($park ? ! ParkExtractor::looksLikeRequest($fields) : ! (Extractor::looksLikeOffer($fields) || $this->carFromOutside($message, $fields))) {
            return false;
        }

        return ! $this->known($park, $fields);
    }

    /**
     * CRM: письмо извне о машине без номера убытка и цены — новая страховая просит «ваше предложение по стоимости годных
     * остатков» (ТИТ, Tenet T4, 16.10.2026): госномер, VIN или марка есть, это не ответ в переписке и не служебное. Без
     * этого такое письмо не становилось делом вовсе. Вендора `AutoOffer` не знает — дело ждёт человека в «Из писем».
     */
    private function carFromOutside(Message $message, array $fields): bool
    {
        if (! $message->isFromVendor() || Intent::silent($message->intent)) {
            return false;
        }
        if (! isset($fields['vin']) && ! isset($fields['plate']) && ! isset($fields['brand'])) {
            return false;
        }
        $message->loadMissing('attachments');

        return AutoOffer::notAnOffer((string) $message->subject, (string) $message->text_body, $message->attachments->pluck('filename')->all()) === null;
    }

    /** ТС или предложение с таким номером, VIN или госномером уже есть — ветку к ним привяжет LinkThread. */
    private function known(bool $park, array $fields): bool
    {
        $code = isset($fields['code']['value']) ? Code::key($fields['code']['value']) : null;
        $vin = isset($fields['vin']['value']) ? strtoupper((string) $fields['vin']['value']) : null;
        $plate = Patterns::plateKey($fields['plate']['value'] ?? null);
        // Номер убытка — не тождество, если VIN или марка письма о другой машине (21.10.2026: Совкомбанк прислал Rexton
        // темой с номером прицепа Krone): такое письмо — своя цепочка, а не «известная машина».
        $same = fn ($car) => ! ScanFields::otherCar($car->vin, $car->brand_id, $vin, $fields['brand']['value'] ?? null);
        if ($park) {
            // Выданная или отменённая ТС не в счёт: второй заезд той же машины — новая заявка.
            $live = fn () => Vehicle::whereNotIn('state', [VehicleState::Released, VehicleState::Cancelled]);

            return ($code && $live()->whereCar('claim_ref_key', $code)->get()->contains($same))
                || ($vin && $live()->whereCar('vin', $vin)->exists())
                || ($plate && $live()->whereCar('plate', $plate)->exists());
        }

        // Госномер — только у живого предложения: архивное с тем же номером — давняя история, письмо о новом деле.
        return ($code && Offer::whereCar('claim_ref_key', $code)->get()->contains($same)) || ($vin && Offer::whereCar('vin', $vin)->exists())
            || ($plate && Offer::whereCar('plate', $plate)->whereNotIn('offers.state', [OfferState::Archived, OfferState::Cancelled])->exists());
    }

    /**
     * Этапы цепочки по смыслам писем: «Заявка» — первое письмо вендора о приёме; «Принята» — наш ответ с актом или
     * письмо вендора о принятой (осмотр, бумаги, не выдавать); «Продана» — «реализовано, покупатель заберёт»;
     * «Выдана» — наш «подписанный АПП» или «вывез» от вендора, если вендор после не писал.
     *
     * @return list<array{stage: string, at: ?string, message_id: int, title: string, name?: ?string, phone?: ?string, note?: ?string}>
     */
    private function stages(Collection $messages): array
    {
        $messages = $messages->sortBy('date_at')->values();
        $stages = [];
        $intake = $messages->first(fn (Message $m) => $m->intent === Intent::Intake->value && ! $m->isOurs())
            ?? $messages->first(fn (Message $m) => ! $m->isOurs());
        if ($intake) {
            $stages[] = ['stage' => CandidateStage::Intake->value, 'at' => $intake->date_at?->toDateTimeString(), 'message_id' => $intake->id, 'title' => 'Заявка на приём'];
        }
        $accepted = $messages->first(fn (Message $m) => $m->isOurs() && $m->intent === Intent::Accepted->value)
            ?? $messages->first(fn (Message $m) => ! $m->isOurs() && in_array($m->intent, [Intent::Accepted->value, Intent::Inspect->value, Intent::Docs->value, Intent::CancelRelease->value, Intent::Hold->value], true));
        $sold = $messages->last(fn (Message $m) => ! $m->isOurs() && $m->intent === Intent::Sold->value);
        $released = $messages->last(fn (Message $m) => $m->intent === Intent::Released->value);
        if ($accepted || $sold || $released) {
            $at = $accepted?->date_at ?? $sold?->date_at ?? $released?->date_at;
            $stages[] = ['stage' => CandidateStage::Stored->value, 'at' => $at?->toDateTimeString(), 'message_id' => ($accepted ?? $sold ?? $released)->id,
                'title' => $accepted?->isOurs() ? 'Принята, отчёт отправлен' : 'Принята'];
        }
        if ($sold) {
            // Кому выдать: из письма о продаже без подписей (в подписи Альфы «тел.: (495) 788-09-99» — офис), иначе из писем вендора после него.
            $notice = ParkExtractor::soldNotice($sold->subject, ParkExtractor::beforeSignature(QuotationStripper::strip($sold->text_body ?: strip_tags((string) $sold->html_body)))) ?? [];
            foreach ($messages->filter(fn (Message $m) => ! $m->isOurs() && $m->date_at > $sold->date_at) as $later) {
                if (! empty($notice['name']) && ! empty($notice['phone'])) {
                    break;
                }
                $more = ParkExtractor::soldNotice($later->subject, $later->ownText()) ?? [];
                $notice = array_filter($notice) + array_filter($more);
            }
            $stages[] = ['stage' => CandidateStage::Sold->value, 'at' => $sold->date_at?->toDateTimeString(), 'message_id' => $sold->id, 'title' => 'На выдачу',
                'name' => $notice['name'] ?? null, 'phone' => $notice['phone'] ?? null, 'note' => $notice['note'] ?? null];
        }
        if ($released && ! $messages->contains(fn (Message $m) => ! $m->isOurs() && $m->date_at > $released->date_at && $m->intent !== Intent::Other->value)) {
            $stages[] = ['stage' => CandidateStage::Released->value, 'at' => $released->date_at?->toDateTimeString(), 'message_id' => $released->id, 'title' => 'Выдана'];
        }

        return $stages;
    }
}
