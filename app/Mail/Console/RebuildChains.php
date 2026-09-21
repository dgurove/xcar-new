<?php

namespace App\Mail\Console;

use App\Mail\Candidate;
use App\Mail\CandidateState;
use App\Mail\Chains\ChainBuilder;
use App\Mail\Message;
use App\Mail\Scope;
use App\Mail\Thread;
use App\Support\Nav;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Цепочки «Из писем» заново из прочитанных писем: состав незаведённых цепочек стирается, письма по дате
 * проходят через `ChainBuilder::attach`, цепочки сворачиваются. Состояние (ждёт / архив) остаётся у цепочек,
 * которые нашлись по прежнему ключу; опустевшие стираются. Заведённые (с ТС или предложением) не трогаются.
 * `--fold` — только пересвернуть существующие цепочки без пересборки состава.
 */
class RebuildChains extends Command
{
    protected $signature = 'mail:rebuild {--fold : только свёртка существующих цепочек}';

    protected $description = 'Пересобрать цепочки «Из писем» из прочитанных писем';

    public function handle(ChainBuilder $chains): int
    {
        $started = microtime(true);
        if (! $this->option('fold')) {
            $open = Candidate::whereIn('state', [CandidateState::New, CandidateState::Rejected]);
            DB::table('mail_candidate_messages')->whereIn('candidate_id', (clone $open)->select('id'))->delete();
            Thread::whereIn('candidate_id', (clone $open)->select('id'))->update(['candidate_id' => null]);
            $this->line('Состав открытых цепочек сброшен: '.(clone $open)->count());
            $n = 0;
            // По дате: цепочку начинает заявка вендора, наш ответ раньше неё в цепочку бы не лёг.
            $messages = Message::with(['account', 'thread', 'attachments'])->whereNotNull('thread_id')->whereHas('thread', fn ($t) => $t->whereNull('vehicle_id')->whereNull('offer_id'))
                ->orderBy('date_at')->orderBy('id')->lazy(200);
            foreach ($messages as $m) {
                $chains->attach($m, quiet: true);
                if (++$n % 1000 === 0) {
                    $this->line("  {$n}");
                }
            }
            $this->line("Писем пройдено: {$n}");
            $empty = Candidate::whereIn('state', [CandidateState::New, CandidateState::Rejected])->whereDoesntHave('messages')->get();
            foreach ($empty as $c) {
                $c->delete();
            }
            $this->line('Опустевших цепочек стёрто: '.$empty->count());
        } else {
            $n = 0;
            Candidate::whereIn('state', [CandidateState::New, CandidateState::Rejected])->each(function (Candidate $c) use ($chains, &$n) {
                $chains->fold($c);
                $n++;
            });
            $this->line("Цепочек свёрнуто: {$n}");
        }
        Nav::forgetStaffCounts();
        $stages = DB::table('mail_candidates')->where('scope', Scope::Park->value)->selectRaw('state, stage, count(*) n')->groupBy('state', 'stage')->orderBy('state')->get()
            ->map(fn ($r) => "{$r->state}/{$r->stage} {$r->n}")->implode(', ');
        $this->line('Цепочки: '.$stages);
        $this->line(sprintf('%.0f с', microtime(true) - $started));

        return self::SUCCESS;
    }
}
