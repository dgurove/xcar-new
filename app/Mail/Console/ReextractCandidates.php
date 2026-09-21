<?php

namespace App\Mail\Console;

use App\Mail\Actions\LinkThread;
use App\Mail\Attachment;
use App\Mail\Candidate;
use App\Mail\CandidateState;
use App\Mail\Direction;
use App\Mail\Extraction\CandidateStages;
use App\Mail\Extraction\Code;
use App\Mail\Extraction\CodeMatcher;
use App\Mail\Extraction\Intent;
use App\Mail\Extraction\Keys;
use App\Mail\Extraction\ParkExtractor;
use App\Mail\Message;
use App\Mail\Scope;
use App\Mail\Thread;
use App\Vendors\Vendor;
use Illuminate\Console\Command;

/**
 * Парсер стал умнее — разово переразобрать письма ждущих кандидатов стоянки: найденные поля дописываются туда,
 * где было пусто (тема кандидата, номер и ключ не трогаются), ветки получают номера заново.
 */
final class ReextractCandidates extends Command
{
    protected $signature = 'mail:reextract {--all : и заведённых, и архивных}';

    protected $description = 'Переразобрать письма кандидатов «Из писем» новым парсером, дописать найденные поля';

    public function handle(ParkExtractor $extractor, Keys $keys, CandidateStages $stagesOf, LinkThread $link): int
    {
        // Смысл — всем письмам стоянки, у которых его ещё нет; с --all — всем заново (правила смысла меняются).
        $intents = 0;
        Message::with(['attachments', 'account'])->when(! $this->option('all'), fn ($q) => $q->whereNull('intent'))->whereHas('account', fn ($a) => $a->where('scope', Scope::Park))->each(function (Message $m) use (&$intents) {
            $m->forceFill(['intent' => Intent::ofMessage($m)->value])->saveQuietly();
            $intents++;
        });
        $this->line("Смысл проставлен: {$intents}");
        $states = $this->option('all') ? CandidateState::cases() : [CandidateState::New, CandidateState::Rejected];
        $stat = ['n' => 0, 'brand' => 0, 'model' => 0, 'year' => 0, 'plate' => 0, 'vin' => 0, 'insured_name' => 0, 'planned_at' => 0];
        $stages = [];
        $filled = 0;
        $detached = 0;
        Candidate::where('scope', Scope::Park)->whereIn('state', $states)->with(['messages.attachments', 'messages.account'])->each(function (Candidate $c) use ($extractor, $keys, $stagesOf, $link, &$stat, &$filled, &$stages, &$detached) {
            $stat['n']++;
            // Чужие письма из цепочки вон: бухгалтерия и письма с другим номером в теме (ответ вендора не в ту ветку).
            $own = $c->code ? Code::key($c->code) : null;
            $stray = $c->messages->filter(function (Message $m) use ($own, $c) {
                if ($m->intent === Intent::Billing->value) {
                    return true;
                }
                $codes = array_map(fn ($x) => Code::key($x), (new CodeMatcher)->findAll($m->subject));

                return $own && $codes && ! in_array($own, $codes, true) && $m->id !== $c->message_id;
            });
            if ($stray->isNotEmpty()) {
                $c->messages()->detach($stray->pluck('id')->all());
                Thread::whereIn('id', $stray->pluck('thread_id')->filter())->where('candidate_id', $c->id)
                    ->whereDoesntHave('messages', fn ($q) => $q->whereIn('mail_messages.id', $c->messages->pluck('id')->diff($stray->pluck('id'))))->update(['candidate_id' => null]);
                $c->load('messages.attachments', 'messages.account');
                $detached += $stray->count();
            }
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
            // Марка и модель из имён файлов наших ответов («Альфа акт выдачи Mazda CX5 3309»), когда в письмах вендора машины нет.
            if (empty($fields['brand']['value'])) {
                $threadIds = $c->messages->pluck('thread_id')->filter()->merge(Thread::where('candidate_id', $c->id)->pluck('id'))->unique();
                $names = Attachment::whereIn('message_id', Message::whereIn('thread_id', $threadIds)->where('direction', Direction::Out)->pluck('id'))->pluck('filename')->all();
                foreach ($extractor->extract(null, null, null, null, $names) as $field => $value) {
                    if (in_array($field, ['brand_id', 'model_id', 'brand', 'model', 'plate', 'vin'], true) && empty($fields[$field]['value']) && ! empty($value['value'])) {
                        $fields[$field] = $value;
                        $filled++;
                    }
                }
            }
            if ($fields !== ($c->extracted ?? [])) {
                $c->forceFill(['extracted' => $fields])->saveQuietly();
            }
            // Заказчик — по отправителю заново: раньше ответ вендора с цитатой нашего письма отправителем считал наш ящик.
            if (! $c->vendor_id && ($vendor = Vendor::forSender($fields['sender']['value'] ?? $c->message?->from_email))) {
                $fields['vendor_id'] = ['value' => $vendor->id, 'source' => 'sender'];
                $fields['vendor'] = ['value' => $vendor->name, 'source' => 'sender'];
                $c->forceFill(['vendor_id' => $vendor->id, 'extracted' => $fields])->saveQuietly();
            }
            if (! $c->code && ! empty($fields['code']['value'])) {
                $c->forceFill(['code' => Code::normalize($fields['code']['value'])])->saveQuietly();
            }
            $link->forCandidate($c->fresh());
            foreach (array_keys($stat) as $k) {
                if ($k !== 'n' && ! empty($fields[$k]['value'])) {
                    $stat[$k]++;
                }
            }
            foreach ($c->threads() as $thread) {
                $keys->rekey($thread);
            }
            $stagesOf->refresh($c);
            $stages[$c->stage->value] = ($stages[$c->stage->value] ?? 0) + 1;
        });
        $this->line("Кандидатов: {$stat['n']}, дописано полей: {$filled}, чужих писем отцеплено: {$detached}");
        $this->line("Марка {$stat['brand']}, модель {$stat['model']}, год {$stat['year']}, госномер {$stat['plate']}, VIN {$stat['vin']}, страхователь {$stat['insured_name']}, дата {$stat['planned_at']}");
        $this->line('Этапы: '.collect($stages)->map(fn ($n, $k) => "$k $n")->implode(', '));

        return self::SUCCESS;
    }
}
