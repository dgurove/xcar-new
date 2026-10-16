<?php

namespace App\Vendors\Actions;

use App\Mail\Candidate;
use App\Mail\CandidateState;
use App\Mail\Chains\ChainBuilder;
use App\Mail\Direction;
use App\Mail\Message;
use App\Mail\Reading\ReadLetter;
use App\Mail\Scope;
use App\Mail\Thread;
use App\Mail\Threads;
use App\Park\City;
use App\Vendors\Vendor;
use Illuminate\Support\Facades\DB;

/**
 * Письма с этого адреса — этого вендора (16.10.2026, владелец: «Выбрать вендора» прямо у письма, где логотип с номером,
 * и остальные письма с адреса тоже ему). Одна дверь для шторки почты и `LearnSenders`:
 * 1. адрес (или весь домен, если он не общий почтовый) — в `senders` / `park_senders` стороны ящика; точный адрес у
 *    другого вендора той же стороны переезжает сюда — выбор человека главнее;
 * 2. письма этого отправителя перечитываются (`ReadLetter`): вендор выбирает шаблон разбора, поля становятся точнее;
 * 3. их ветки (`Threads::refresh`) и ждущие цепочки (`ChainBuilder::fold`) получают вендора, письмо с машиной без
 *    цепочки её заводит — тихо: черновик сам не заводится, «Завести» рядом.
 */
final class BindSender
{
    /** Общие почтовые домены: на них только точный адрес, домен — это вся страна. */
    public const FREE_DOMAINS = ['mail.ru', 'inbox.ru', 'bk.ru', 'list.ru', 'internet.ru', 'yandex.ru', 'ya.ru', 'yandex.com',
        'gmail.com', 'googlemail.com', 'rambler.ru', 'ro.ru', 'lenta.ru', 'autorambler.ru', 'myrambler.ru', 'icloud.com',
        'me.com', 'outlook.com', 'hotmail.com', 'live.com', 'yahoo.com', 'vk.com', 'proton.me', 'protonmail.com'];

    /** @var ?string из какого вендора адрес переехал — тосту */
    public ?string $movedFrom = null;

    public function __construct(private ReadLetter $reader, private Threads $threads, private ChainBuilder $chains) {}

    /** Домен адреса, если его можно отдать вендору целиком; общий почтовый — null. */
    public static function ownDomain(?string $email): ?string
    {
        $domain = mb_strtolower((string) preg_replace('/.*@/u', '', trim((string) $email)));

        return $domain !== '' && str_contains((string) $email, '@') && ! in_array($domain, self::FREE_DOMAINS, true) ? $domain : null;
    }

    /** @return int сколько писем перечитано */
    public function __invoke(Vendor $vendor, string $address, Scope $scope, bool $domain = false): int
    {
        $address = mb_strtolower(trim($address));
        $entry = $domain && ($own = self::ownDomain($address)) ? $own : $address;
        $column = Vendor::sendersColumn($scope);
        $this->movedFrom = null;
        DB::transaction(function () use ($vendor, $entry, $column) {
            foreach (Vendor::whereJsonContains($column, $entry)->whereKeyNot($vendor->id)->get() as $other) {
                $other->forceFill([$column => array_values(array_diff($other->{$column} ?? [], [$entry]))])->save();
                $this->movedFrom = $other->name;
            }
            $list = $vendor->{$column} ?? [];
            if (! in_array($entry, $list, true)) {
                $vendor->forceFill([$column => [...$list, $entry]])->save();
            }
        });
        Vendor::forgetSenders();

        return City::unscoped(fn () => $this->reread($scope, $entry));
    }

    private function reread(Scope $scope, string $entry): int
    {
        $sender = "lower(coalesce(mail_messages.parsed->'fields'->'sender'->>'value', mail_messages.from_email))";
        $messages = Message::with(['account', 'attachments'])
            ->whereIn('account_id', DB::table('mail_accounts')->where('scope', $scope->value)->select('id'))
            ->whereNotNull('thread_id')->whereNull('frozen_at')->where('direction', Direction::In)
            ->when(str_contains($entry, '@'),
                fn ($q) => $q->whereRaw("{$sender} = ?", [$entry]),
                fn ($q) => $q->whereRaw("{$sender} like ?", ['%@'.$entry]))
            ->orderBy('id')->get();
        foreach ($messages as $message) {
            $this->reader->apply($message);
        }
        $threadIds = $messages->pluck('thread_id')->unique()->values();
        foreach (Thread::withoutGlobalScopes()->whereIn('id', $threadIds)->get() as $thread) {
            $this->threads->refresh($thread);
        }
        $candidates = DB::table('mail_candidate_messages')->whereIn('message_id', $messages->pluck('id'))->distinct()->pluck('candidate_id');
        foreach (Candidate::withoutGlobalScopes()->whereIn('id', $candidates)->whereIn('state', [CandidateState::New, CandidateState::Rejected])->get() as $candidate) {
            $this->chains->fold($candidate);
        }
        // Письмо без дела: с узнанным вендором оно может начать цепочку — последнее входящее ветки, тихо.
        foreach (Thread::withoutGlobalScopes()->whereIn('id', $threadIds)->whereNull('offer_id')->whereNull('vehicle_id')->whereNull('candidate_id')->whereNull('archived_at')->get() as $thread) {
            $last = $messages->where('thread_id', $thread->id)->sortByDesc('date_at')->first();
            if ($last) {
                $this->chains->attach($last->fresh(), quiet: true);
            }
        }

        return $messages->count();
    }
}
