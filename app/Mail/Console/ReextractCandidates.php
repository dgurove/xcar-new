<?php

namespace App\Mail\Console;

use App\Mail\Candidate;
use App\Mail\CandidateState;
use App\Mail\Extraction\Keys;
use App\Mail\Extraction\ParkExtractor;
use App\Mail\Message;
use App\Mail\Scope;
use Illuminate\Console\Command;

/**
 * Парсер стал умнее — разово переразобрать письма ждущих кандидатов стоянки: найденные поля дописываются туда,
 * где было пусто (тема кандидата, номер и ключ не трогаются), ветки получают номера заново.
 */
final class ReextractCandidates extends Command
{
    protected $signature = 'mail:reextract {--all : и заведённых, и архивных}';

    protected $description = 'Переразобрать письма кандидатов «Из писем» новым парсером, дописать найденные поля';

    public function handle(ParkExtractor $extractor, Keys $keys): int
    {
        $states = $this->option('all') ? CandidateState::cases() : [CandidateState::New, CandidateState::Rejected];
        $stat = ['n' => 0, 'brand' => 0, 'model' => 0, 'year' => 0, 'plate' => 0, 'vin' => 0, 'insured_name' => 0, 'planned_at' => 0];
        $filled = 0;
        Candidate::where('scope', Scope::Park)->whereIn('state', $states)->with(['messages.attachments', 'messages.account'])->each(function (Candidate $c) use ($extractor, $keys, &$stat, &$filled) {
            $stat['n']++;
            $fields = $c->extracted ?? [];
            foreach ($c->messages as $message) {
                /** @var Message $message */
                $new = $extractor->extract($message->subject, $message->text_body ?: $message->html_body, $message->from_email, $message->date_at, $message->attachments->pluck('filename')->all(), $message->attachments);
                foreach ($new as $field => $value) {
                    if (empty($fields[$field]['value']) && ! empty($value['value'])) {
                        $fields[$field] = $value;
                        $filled++;
                    }
                }
            }
            if ($fields !== ($c->extracted ?? [])) {
                $c->forceFill(['extracted' => $fields])->saveQuietly();
            }
            foreach (array_keys($stat) as $k) {
                if ($k !== 'n' && ! empty($fields[$k]['value'])) {
                    $stat[$k]++;
                }
            }
            foreach ($c->threads() as $thread) {
                $keys->rekey($thread);
            }
        });
        $this->line("Кандидатов: {$stat['n']}, дописано полей: {$filled}");
        $this->line("Марка {$stat['brand']}, модель {$stat['model']}, год {$stat['year']}, госномер {$stat['plate']}, VIN {$stat['vin']}, страхователь {$stat['insured_name']}, дата {$stat['planned_at']}");

        return self::SUCCESS;
    }
}
