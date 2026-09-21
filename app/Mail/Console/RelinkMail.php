<?php

namespace App\Mail\Console;

use App\Mail\Actions\ArchiveThread;
use App\Mail\Actions\LinkThread;
use App\Mail\Candidate;
use App\Mail\CandidateState;
use App\Mail\Direction;
use App\Mail\Extraction\Keys;
use App\Mail\Jobs\ExtractCandidate;
use App\Mail\Message;
use App\Mail\Scope;
use App\Mail\Thread;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Park\Vehicle;
use App\Park\VehicleState;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Разово после выкладки (и когда надо пересобрать): номера всем веткам, письма «ч.2» и наши ответы — под своих
 * кандидатов, все ветки с номером ТС или предложения — к ним, архив кандидатов — на их ветки.
 */
final class RelinkMail extends Command
{
    protected $signature = 'mail:relink';

    protected $description = 'Пересчитать номера веток, досадить письма под кандидатов, привязать ветки к ТС и предложениям';

    public function handle(Keys $keys, LinkThread $link, ArchiveThread $archive): int
    {
        $n = 0;
        Thread::query()->each(function (Thread $t) use ($keys, &$n) {
            $keys->rekey($t);
            $n++;
        });
        $this->line("Номера пересчитаны: {$n}");

        // Ветки писем существующих кандидатов.
        $pairs = DB::table('mail_candidate_messages')->join('mail_messages', 'mail_messages.id', '=', 'mail_candidate_messages.message_id')
            ->whereNotNull('mail_messages.thread_id')->get(['mail_candidate_messages.candidate_id', 'mail_messages.thread_id']);
        foreach ($pairs as $pair) {
            Thread::whereKey($pair->thread_id)->whereNull('candidate_id')->update(['candidate_id' => $pair->candidate_id]);
        }

        // Входящие письма непривязанных веток без кандидата — под кандидата по номеру (без уведомлений владельцу).
        $joined = 0;
        $created = 0;
        $before = Candidate::count();
        Message::with(['thread', 'account'])->where('direction', Direction::In)
            ->whereHas('thread', fn ($t) => $t->whereNull('candidate_id')->whereNull('vehicle_id')->whereNull('offer_id'))
            ->orderBy('date_at')->each(function (Message $m) use (&$joined) {
                if (ExtractCandidate::run($m, quiet: true)) {
                    $joined++;
                }
            });
        $created = Candidate::count() - $before;
        $this->line('Писем под кандидатами: '.$joined.', новых кандидатов: '.$created);

        // Наши ответы и пересылки отдельной веткой — под кандидата по номерам.
        $toCandidates = 0;
        Candidate::where('scope', Scope::Park)->each(function (Candidate $c) use ($link, &$toCandidates) {
            $toCandidates += $link->forCandidate($c);
        });
        $this->line("Веток привязано к кандидатам: {$toCandidates}");

        $linked = 0;
        Vehicle::whereNotIn('state', [VehicleState::Released, VehicleState::Cancelled])->each(function (Vehicle $v) use ($link, &$linked) {
            $linked += $link->forVehicle($v);
        });
        Offer::where('state', '!=', OfferState::Archived)->each(function (Offer $o) use ($link, &$linked) {
            $linked += $link->forOffer($o);
        });
        $this->line("Веток привязано к ТС и предложениям: {$linked}");

        Candidate::where('state', CandidateState::Rejected)->each(fn (Candidate $c) => $archive->candidate($c));
        $this->line('Архив кандидатов — на их ветки');

        return self::SUCCESS;
    }
}
