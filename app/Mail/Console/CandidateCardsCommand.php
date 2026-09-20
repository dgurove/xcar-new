<?php

namespace App\Mail\Console;

use App\Mail\Candidate;
use App\Mail\CandidateState;
use App\Mail\Extraction\CandidateCard;
use App\Media\PhotoIngest;
use Illuminate\Console\Command;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Разово после выкладки: у открытых кандидатов вместо медиатеки со всеми фото письма — один кадр карточки на hot.
 * Кадр делается из первого старого фото (оно уже ужато) или из письма; старые фото стираются.
 */
final class CandidateCardsCommand extends Command
{
    protected $signature = 'mail:candidate-cards {--dry-run : только посчитать}';

    protected $description = 'Оставить у кандидатов «Из писем» один кадр карточки, старые фото стереть';

    public function handle(CandidateCard $card, PhotoIngest $photos): int
    {
        $made = 0;
        $removed = 0;
        $bytes = 0;
        Candidate::whereIn('state', [CandidateState::New, CandidateState::Rejected, CandidateState::Promoted])->with('messages')->orderByDesc('last_message_at')->each(function (Candidate $c) use ($card, $photos, &$made, &$removed, &$bytes) {
            $old = $c->getMedia('photos');
            $bytes += (int) $old->sum('size');
            $removed += $old->count();
            if ($this->option('dry-run')) {
                return;
            }
            if ($c->state !== CandidateState::Promoted && ! $c->card()) {
                /** @var ?Media $first */
                $first = $old->first();
                $file = $first ? $first->getPath() : null;
                if ($file && is_file($file)) {
                    // PhotoIngest стирает исходник после приёма — отдаём ему копию.
                    $temp = tempnam(sys_get_temp_dir(), 'card-');
                    copy($file, $temp);
                    $photos->add($c, 'card', $temp, $first->file_name, [], CandidateCard::MAX_DIMENSION);
                    $made++;
                } elseif ($c->message) {
                    $card->make($c, $c->message) && $made++;
                }
            }
            $c->clearMediaCollection('photos');
        });
        $this->line(($this->option('dry-run') ? 'Будет стёрто: ' : 'Стёрто: ')."{$removed} фото, ".round($bytes / 1048576).' МБ; кадров сделано: '.$made);

        return self::SUCCESS;
    }
}
