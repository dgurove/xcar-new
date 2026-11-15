<?php

namespace App\Mail\Actions;

use App\Mail\Direction;
use App\Mail\Message;
use App\Mail\Threads;
use App\Offers\Binding;
use App\Offers\ReplyReview;
use App\Support\Nav;
use App\Workflow\Position;
use App\Workflow\Stage;
use App\Workflow\Track;
use App\Workflow\WaitsFor;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\IMAP;

/**
 * Убрать ушедшее наше письмо целиком (15.11.2026, владелец про «интерес за 500 000 ₽» по Mercedes AUT-26-781222, которое
 * я отправил за начальника: «удали это письмо из отправленных в самой нашей почте и в системе»). Поставщику оно уже ушло —
 * отозвать нельзя; убирается наша копия и всё, что отправка сделала у нас:
 * - в ящике — «\Deleted» и UID EXPUNGE только этого письма в его папке (Message-ID сверяется; без этого `mail:backfill`
 *   завёл бы письмо снова);
 * - шаг сделки снова «письмо не отправлено» (`MarkSupplierLetter::sent` наоборот), запись «Письмо поставщику
 *   отправлено» из «Истории», разбор ответа поставщика, закрытый этим письмом (`CloseReviews::answered`), — снова ждёт;
 * - письмо и вложения, ветка пересчитывается (`Threads::refresh`).
 * Команда — `mail:purge-sent {id} [--dry]`.
 */
final class PurgeSentMessage
{
    /** @return list<string> что сделано (или будет сделано при `$dry`) */
    public function __invoke(Message $message, bool $dry = false): array
    {
        if ($message->direction !== Direction::Out) {
            throw new RuntimeException('Это не наше письмо');
        }
        $out = [];
        $sentAt = $message->sent_at ?? $message->date_at;

        // Ящик — первым: не стёрли там, базу не трогаем.
        $folder = $message->folder;
        if ($folder && $message->imap_uid) {
            $client = (new ClientManager)->make($message->account->imapConfig() + ['timeout' => 30]);
            $client->connect();
            $imap = $client->getConnection();
            $imap->selectFolder($folder->path)->validatedData();
            $mid = '<'.trim((string) $message->message_id, '<>').'>';
            $uids = (array) $imap->search(['HEADER', 'Message-ID', $mid], IMAP::ST_UID)->validatedData();
            if (! in_array((int) $message->imap_uid, array_map('intval', $uids), true)) {
                $uids ? $out[] = 'в ящике письмо под другим номером: '.implode(',', $uids) : $out[] = 'в ящике письма нет';
            }
            $uids = array_values(array_unique(array_map('intval', $uids)));
            $out[] = 'ящик '.$message->account->email.', папка '.$folder->name.', UID '.($uids ? implode(',', $uids) : '—');
            if (! $dry && $uids) {
                foreach ($uids as $uid) {
                    $imap->store(['\\Deleted'], $uid, null, '+', true, IMAP::ST_UID)->validatedData();
                    $imap->requestAndResponse('UID EXPUNGE', [(string) $uid]);
                }
                $left = (array) $imap->search(['HEADER', 'Message-ID', $mid], IMAP::ST_UID)->validatedData();
                if ($left) {
                    throw new RuntimeException('Письмо осталось в ящике: UID '.implode(',', $left));
                }
                $out[] = 'из ящика удалено';
            }
            $client->disconnect();
        }

        $thread = $message->thread;
        $offerId = $thread?->offer_id;
        DB::transaction(function () use ($message, $sentAt, $offerId, $dry, &$out) {
            if ($offerId) {
                $events = DB::table('car_events')->where('offer_id', $offerId)->where('type', 'supplier_letter')->whereRaw("payload->>'letter' = ?", [(string) $message->id]);
                $out[] = 'записей «Письмо поставщику отправлено»: '.$events->count();
                $dry || $events->delete();
                // Разбор ответа поставщика из той же ветки, закрытый этой отправкой, — снова ждёт.
                $reviews = ReplyReview::where('offer_id', $offerId)->where('state', ReplyReview::DONE)
                    ->whereIn('message_id', Message::where('thread_id', $message->thread_id)->select('id'))
                    ->whereBetween('done_at', [$sentAt->copy()->subSeconds(5), $sentAt->copy()->addMinutes(2)]);
                $out[] = 'разборов ответа вернуть: '.$reviews->count();
                $dry || $reviews->update(['state' => ReplyReview::PENDING, 'done_by' => null, 'done_at' => null]);
                $notes = DB::table('car_events')->where('offer_id', $offerId)->where('type', 'note')->whereRaw("payload->>'text' like ?", ['Письмо разобрано%'])
                    ->whereBetween('created_at', [$sentAt->copy()->subSeconds(5), $sentAt->copy()->addMinutes(2)]);
                $dry || $notes->delete();
            }
            // Шаг, которому письмо засчиталось, — снова «не отправлено».
            $position = Position::where('track', Track::Sale)->whereRaw("payload->>'letter_id' = ?", [(string) $message->id])->with(['stage', 'offer.deal'])->first();
            if ($position) {
                $out[] = 'шаг «'.$position->stage?->name.'» — снова «письмо не отправлено»';
                if (! $dry) {
                    $payload = array_diff_key($position->payload ?? [], array_flip(['letter_id', 'letter_at']));
                    $offer = $position->offer;
                    $position->update([
                        'waits_for' => WaitsFor::Us,
                        'deadline_at' => Binding::stageDeadline($offer, $offer->deal, WaitsFor::Us, Stage::letterDeadline($position->entered_at), $position->stage),
                        'reminded_at' => null,
                        'overdue_at' => null,
                        'payload' => $payload ?: null,
                    ]);
                }
            }
            if (! $dry) {
                foreach ($message->attachments as $attachment) {
                    $attachment->delete();
                }
                $message->delete();
                $out[] = 'письмо удалено из CRM';
            }
        });
        if (! $dry && $thread) {
            app(Threads::class)->refresh($thread);
            Nav::forgetStaffCounts();
        }

        return $out;
    }
}
