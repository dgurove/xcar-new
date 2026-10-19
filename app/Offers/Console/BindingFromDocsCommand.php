<?php

namespace App\Offers\Console;

use App\Mail\Extraction\DocumentFields;
use App\Mail\Extraction\DocumentText;
use App\Mail\Scan\OfferSubject;
use App\Offers\Actions\ApplyCarFields;
use App\Offers\Binding;
use App\Offers\Offer;
use App\Offers\OfferState;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Разово (19.10.2026, владелец: «срок обязывающего предложения есть в письмах в приложенных файлах»): у живых предложений
 * без срока — срок из протоколов торгов в документах и письмах (`DocumentFields::binding`), только прочитанным текстом и
 * текстовым слоем, без OCR. В пустое поле, через `ApplyCarFields` (история «Изменён»). Без `--apply` — что встанет, списком.
 */
class BindingFromDocsCommand extends Command
{
    protected $signature = 'offers:binding-from-docs {--apply}';

    protected $description = 'Срок обязывающего предложения из протоколов торгов в предложения без него';

    public function handle(ApplyCarFields $apply): int
    {
        $write = (bool) $this->option('apply');
        $found = 0;
        $offers = Offer::whereNull('binding_until')
            ->whereNotIn('state', [OfferState::Archived, OfferState::Cancelled, OfferState::Delivered])
            ->orderBy('id');
        foreach ($offers->lazyById() as $offer) {
            $until = null;
            try {
                foreach ((new OfferSubject($offer))->files() as $file) {
                    $text = DocumentText::layer($file);
                    if ($text && ($until = DocumentFields::binding($text))) {
                        break;
                    }
                }
            } catch (Throwable $e) {
                $this->warn("{$offer->id}: {$e->getMessage()}");

                continue;
            }
            if (! $until) {
                continue;
            }
            $found++;
            $at = Carbon::parse($until);
            $this->line("{$offer->id} {$offer->claim_ref} {$offer->titleWithYear()} [{$offer->state->value}]: ".Binding::date($at).($at->isPast() ? ', вышел' : ''));
            if ($write) {
                $apply($offer, ['binding_until' => ['value' => $until]], null, log: ['why' => 'Из протокола торгов']);
            }
        }
        $this->info(($write ? 'Вписано: ' : 'Найдено: ').$found);

        return self::SUCCESS;
    }
}
