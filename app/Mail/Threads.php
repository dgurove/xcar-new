<?php

namespace App\Mail;

use App\Vendors\Vendor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Ветка для письма: по In-Reply-To/References, иначе по теме и собеседникам за 90 дней, иначе новая. */
final class Threads
{
    public function __construct(private Parser $parser) {}

    public function resolve(Account $account, array $parsed): Thread
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

    public function refresh(Thread $thread): void
    {
        $messages = Message::where('thread_id', $thread->id)->get(['id', 'subject', 'is_seen', 'has_attachments', 'date_at', 'direction', 'intent']);
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
            'messages_count' => $messages->count(),
            'unread_count' => $messages->where('is_seen', false)->where('direction', Direction::In)->count(),
            'has_attachments' => $messages->contains('has_attachments', true),
            // Число файлов ветки — колонкой: подзапрос на строку списка стоил секунду на экран.
            'attachments_count' => DB::table('mail_attachments')->whereIn('message_id', $messages->pluck('id'))->where('is_inline', false)->count(),
            'participants' => $participants,
            // Вендор ветки — по первому чужому адресу: фильтр «Вендор», бейдж в строке, правило «Прочее».
            'vendor_id' => self::vendorOf($thread, $participants)?->id,
            // Номера ветки — объединение номеров её писем из индекса (`ReadLetter`), письма заново не читаются.
            'keys' => DB::table('mail_message_keys')->whereIn('message_id', $messages->pluck('id'))->distinct()->orderBy('key')->pluck('key')->all(),
        ])->save();
    }

    /** Вендор по первому адресу, который не наш ящик и не сотрудник. */
    public static function vendorOf(Thread $thread, array $participants): ?Vendor
    {
        $own = [mb_strtolower((string) $thread->account?->email), ...Message::ownEmails()];
        $email = collect($participants)->pluck('email')->first(fn ($e) => $e && ! in_array(mb_strtolower((string) $e), $own, true));

        return $email ? Vendor::forSender($email) : null;
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
