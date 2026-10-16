<?php

namespace App\Mail;

use App\Mail\Extraction\Intent;
use App\Park\City;
use App\Park\EventType;
use App\Support\Surface;
use App\Users\User;
use App\Vendors\Vendor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Ветка для письма: по In-Reply-To/References, иначе по теме и собеседникам за 90 дней, иначе новая. */
final class Threads
{
    public function __construct(private Parser $parser) {}

    public function resolve(Account $account, array $parsed): Thread
    {
        return City::unscoped(fn () => $this->find($account, $parsed));
    }

    private function find(Account $account, array $parsed): Thread
    {
        $thread = $this->byReferences($account, $parsed) ?? $this->bySubject($account, $parsed) ?? Thread::create([
            'account_id' => $account->id,
            'root_message_id' => $parsed['message_id'],
            'subject' => $parsed['subject'],
            'subject_normalized' => $parsed['subject_normalized'],
            'last_message_at' => $parsed['date'],
        ]);
        $this->adoptOrphans($account, $parsed, $thread);

        return $thread;
    }

    /**
     * Вопрос ветки закрыт без письма: «Сделано» в деле ТС и «Ответ не нужен» в почте (владелец 09.10.2026: «забить на
     * просьбу, потому что уже порешали другим способом», не архивируя письмо). Ветка уходит из «Требуют внимания», новое
     * письмо вендора снова её поднимет (`waiting`). Ветка ТС — запись в истории дела.
     */
    /** Кому «Ответ не нужен»: на парковке — тем, кому открыт `park.manage` (помощнику «только приёмка» — нет), в CRM — всем. */
    public static function canAnswer(?User $user): bool
    {
        return Surface::current() !== Surface::Park || (bool) $user?->canManagePark();
    }

    public function answered(Thread $thread, ?User $by): void
    {
        $thread->forceFill(['answered_at' => now()])->save();
        $this->refresh($thread);
        $last = Message::where('thread_id', $thread->id)->orderByDesc('date_at')->orderByDesc('id')->first(['id', 'subject']);
        $thread->vehicle()->withoutGlobalScope(City::SCOPE)->first()
            ?->log(EventType::LetterAnswered, $by, ['message' => $last?->id, 'subject' => $last?->subject, 'thread' => $thread->id]);
    }

    public function refresh(Thread $thread): void
    {
        City::unscoped(fn () => $this->recount($thread));
    }

    private function recount(Thread $thread): void
    {
        $messages = Message::where('thread_id', $thread->id)->get(['id', 'subject', 'is_seen', 'has_attachments', 'date_at', 'direction', 'intent', 'from_email', 'parsed']);
        if ($messages->isEmpty()) {
            $thread->delete();

            return;
        }
        $participants = Address::whereIn('message_id', $messages->pluck('id'))->whereIn('kind', ['from', 'to', 'cc'])
            ->get(['email', 'name'])->unique('email')->take(20)->map(fn ($a) => ['email' => $a->email, 'name' => $a->name])->values()->all();
        // Последнее письмо ветки — колонками: по ним стоят пилюли почты и поиск, подзапросом это была секунда.
        $sorted = $messages->sortBy([['date_at', 'asc'], ['id', 'asc']])->values();
        $thread->forceFill([
            'subject' => $thread->subject ?: $sorted->first()?->subject,
            'last_message_at' => $messages->max('date_at'),
            'last_direction' => $sorted->last()?->direction?->value,
            'last_intent' => $sorted->last(fn (Message $m) => $m->direction === Direction::In)?->intent,
            // Ждёт ответа — одно правило на почту, дело ТС и ленту: последнее письмо ветки их, с вопросом,
            // и после него мы не писали и не нажимали «Сделано».
            'needs_reply_at' => self::waiting($thread, $sorted->last()),
            'messages_count' => $messages->count(),
            'unread_count' => $messages->where('is_seen', false)->where('direction', Direction::In)->count(),
            'has_attachments' => $messages->contains('has_attachments', true),
            // Число файлов ветки — колонкой: подзапрос на строку списка стоил секунду на экран.
            'attachments_count' => DB::table('mail_attachments')->whereIn('message_id', $messages->pluck('id'))->where('is_inline', false)->count(),
            'participants' => $participants,
            // Вендор ветки — по настоящему отправителю: фильтр «Вендор», бейдж в строке, правило «Прочее».
            'vendor_id' => self::vendorOf($thread, $participants, $sorted)?->id,
            // Номера ветки — объединение номеров её писем из индекса (`ReadLetter`), письма заново не читаются.
            'keys' => DB::table('mail_message_keys')->whereIn('message_id', $messages->pluck('id'))->distinct()->orderBy('key')->pluck('key')->all(),
        ])->save();
    }

    /** Дата письма, которое ждёт нашего ответа, или null: считается один раз здесь и живёт в `needs_reply_at`. */
    private static function waiting(Thread $thread, ?Message $last): ?Carbon
    {
        if (! $last || $last->direction !== Direction::In || ($last->isOurs() && ! $last->isForwardedByStaff())) {
            return null;
        }
        if (! (Intent::tryFrom((string) $last->intent)?->needsReply() ?? false)) {
            return null;
        }

        // «Сделано» в деле ТС и «Ответ не нужен» в почте закрывают вопрос без письма (`answered`); новое письмо
        // вендора снова его открывает.
        return $thread->answered_at?->gte($last->date_at) ? null : $last->date_at;
    }

    /**
     * Вендор ветки: сначала тот, кого разбор узнал в письмах (последнее входящее с вендором — в пересылке сотрудника он в
     * «От:» цитаты), иначе по первому адресу, который не наш ящик и не сотрудник. Без первого шага всё, что Андрей
     * пересылает со своего mail.ru, оставалось без вендора: 64 из 64 веток offer@ (16.10.2026).
     *
     * @param  iterable<Message>  $messages
     */
    public static function vendorOf(Thread $thread, array $participants, iterable $messages = []): ?Vendor
    {
        foreach (collect($messages)->reverse() as $m) {
            if ($m->direction !== Direction::In) {
                continue;
            }
            if (($id = $m->field('vendor_id')) && ($vendor = Vendor::find($id))) {
                return $vendor;
            }
            // Адрес отправителя пересылки узнали позже разбора (`LearnSenders`, вендора поставили руками).
            if ($m->isForwardedByStaff() && $thread->account && ($vendor = Vendor::forSender($m->field('sender'), $thread->account->scope))) {
                return $vendor;
            }
        }
        $own = [mb_strtolower((string) $thread->account?->email), ...Message::ownEmails()];
        $email = collect($participants)->pluck('email')->first(fn ($e) => $e && ! in_array(mb_strtolower((string) $e), $own, true));

        return $email && $thread->account ? Vendor::forSender($email, $thread->account->scope) : null;
    }

    private function byReferences(Account $account, array $parsed): ?Thread
    {
        $candidates = array_filter([$parsed['in_reply_to'] ?? null, ...array_reverse($this->parser->referenceChain($parsed['references'] ?? null))]);
        if (! $candidates) {
            return null;
        }

        return Message::where('account_id', $account->id)->whereIn('message_id', array_unique($candidates))->whereNotNull('thread_id')
            ->orderByDesc('date_at')->first()?->thread;
    }

    private function bySubject(Account $account, array $parsed): ?Thread
    {
        if (empty($parsed['subject_normalized'])) {
            return null;
        }
        $emails = array_values(array_unique(array_map(fn ($a) => $a['email'], array_filter($parsed['addresses'] ?? [], fn ($a) => in_array($a['kind'], [AddressKind::From, AddressKind::To, AddressKind::Cc], true)))));
        if (! $emails) {
            return null;
        }

        // Окно от даты письма, не от сегодня: у истории ящика ответы без In-Reply-To иначе не склеиваются.
        $at = $parsed['date'] ?? now();

        return Thread::where('account_id', $account->id)->where('subject_normalized', $parsed['subject_normalized'])
            ->whereBetween('last_message_at', [Carbon::instance($at)->subDays(90), Carbon::instance($at)->addDays(90)])
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('mail_addresses')->join('mail_messages', 'mail_messages.id', '=', 'mail_addresses.message_id')
                ->whereColumn('mail_messages.thread_id', 'mail_threads.id')->whereIn('mail_addresses.email', $emails))
            ->orderByDesc('last_message_at')->first();
    }

    /** Ответы, пришедшие раньше родителя, лежали в своих ветках — переезжают к нему. */
    private function adoptOrphans(Account $account, array $parsed, Thread $thread): void
    {
        if (empty($parsed['message_id'])) {
            return;
        }
        $stray = Message::where('account_id', $account->id)->where('in_reply_to', $parsed['message_id'])
            ->whereNotNull('thread_id')->where('thread_id', '!=', $thread->id)->distinct()->pluck('thread_id')->all();
        if (! $stray) {
            return;
        }
        DB::transaction(function () use ($stray, $thread) {
            Message::whereIn('thread_id', $stray)->update(['thread_id' => $thread->id]);
            Thread::whereIn('id', $stray)->delete();
        });
    }
}
