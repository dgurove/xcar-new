<?php

namespace App\Media\Console;

use App\Media\Actions\UnmarkPhoto;
use App\Media\Unmark;
use App\Offers\Deal;
use App\Offers\Offer;
use App\Offers\OfferNumber;
use App\Offers\OfferState;
use Illuminate\Console\Command;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

/**
 * Снять чужой знак площадки с уже загруженных фото предложений (новые снимает приём фото сам); `--undo` — вернуть.
 * `--migtorg` (21.11.2026, Lada Vesta № 3952: «оригиналы» Мигторга со знаком, приём их не проверял) — живые машины с кадрами
 * Мигторга: первые три кадра проверяются, знак есть — снимается со всех; без `--apply` — только список.
 */
class UnmarkPhotos extends Command
{
    protected $signature = 'media:unmark {offers?* : номера или id предложений} {--undo : вернуть кадры со знаком} {--migtorg : найти живые машины с кадрами Мигторга со знаком} {--apply : с --migtorg — снять}';

    protected $description = 'Снять водяной знак площадки (Мигторг) с фото предложений';

    public function handle(UnmarkPhoto $unmark): int
    {
        if ($this->option('migtorg')) {
            return $this->migtorg($unmark);
        }
        foreach ($this->argument('offers') as $ref) {
            $offer = OfferNumber::find($ref) ?? Offer::find($ref);
            if (! $offer) {
                $this->warn("Предложение {$ref} не найдено");

                continue;
            }
            $this->line($this->one($offer, $unmark, (bool) $this->option('undo')));
        }

        return self::SUCCESS;
    }

    private function one(Offer $offer, UnmarkPhoto $unmark, bool $undo = false): string
    {
        $done = 0;
        $photos = $offer->photos();
        foreach ($photos as $media) {
            try {
                $done += $undo ? (int) $unmark->undo($media) : (int) (bool) $unmark($media);
            } catch (Throwable $e) {
                $this->warn("Кадр {$media->id}: {$e->getMessage()}");
            }
        }

        return ($undo ? 'Возвращено' : 'Снято')." {$done} из {$photos->count()} — предложение {$offer->number}";
    }

    private function migtorg(UnmarkPhoto $unmark): int
    {
        $apply = (bool) $this->option('apply');
        $ids = Offer::query()
            ->where(fn ($q) => $q->whereIn('state', [OfferState::Draft, OfferState::Gallery, OfferState::Open])
                ->orWhereIn('id', Deal::query()->active()->select('offer_id')))
            // Кадры лежат у машины (`Cars\Car`, `OnCar`), не у предложения.
            ->whereIn('car_id', Media::query()->where('model_type', (new \App\Cars\Car)->getMorphClass())->where('collection_name', 'photos')
                ->whereNotNull('custom_properties->migtorg')->whereNull('custom_properties->unmarked')->select('model_id'))
            ->orderBy('id')->pluck('id');
        $this->info($ids->count().' машин с кадрами Мигторга');
        $found = 0;
        foreach ($ids as $id) {
            $offer = Offer::find($id);
            $sample = $offer->photos()->filter(fn (Media $m) => $m->getCustomProperty('migtorg') && ! $m->getCustomProperty('unmarked'))->take(3);
            $marked = $sample->contains(function (Media $m) {
                $clean = is_file($m->getPath()) ? app(Unmark::class)($m->getPath()) : null;
                $clean && @unlink($clean['path']);

                return (bool) $clean;
            });
            if (! $marked) {
                continue;
            }
            $found++;
            $this->line($offer->number.' '.$offer->title().' ('.$offer->carRecord()->claim_ref.')'.($apply ? ' — '.$this->one($offer, $unmark) : ''));
        }
        $this->info($found.' со знаком'.($apply ? ', снято' : ', ничего не трогал (--apply — снять)'));

        return self::SUCCESS;
    }
}
