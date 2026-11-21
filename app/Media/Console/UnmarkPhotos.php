<?php

namespace App\Media\Console;

use App\Cars\Car;
use App\Media\Actions\UnmarkPhoto;
use App\Media\MigtorgMarks;
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
    protected $signature = 'media:unmark {offers?* : номера или id предложений} {--undo : вернуть кадры со знаком} {--migtorg : живые машины с кадрами Мигторга: знак по лоту} {--apply : с --migtorg — снять}';

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

    /**
     * Живые машины (черновик, галерея, продажа, гараж, идущие сделки) с кадрами Мигторга. Есть снятые — итог по лоту
     * (`MigtorgMarks::settle`: ложные назад, у лота со знаком — остальные с поворотом). Снятых нет — первые три кадра без
     * нашего знака, знак на двух — снимается со всех и тот же итог.
     */
    private function migtorg(UnmarkPhoto $unmark): int
    {
        $apply = (bool) $this->option('apply');
        $marks = app(MigtorgMarks::class);
        $ids = Offer::query()
            ->where(fn ($q) => $q->whereIn('state', [OfferState::Draft, OfferState::Gallery, OfferState::Open, OfferState::Garage])
                ->orWhereIn('id', Deal::query()->active()->select('offer_id')))
            // Кадры лежат у машины (`Cars\Car`, `OnCar`), не у предложения.
            ->whereIn('car_id', Media::query()->where('model_type', (new Car)->getMorphClass())->where('collection_name', 'photos')
                ->whereNotNull('custom_properties->migtorg')->select('model_id'))
            ->orderBy('id')->pluck('id');
        $this->info($ids->count().' машин с кадрами Мигторга');
        $touched = 0;
        foreach ($ids as $id) {
            $offer = Offer::find($id);
            $line = $offer->number.' '.$offer->title().' ('.$offer->carRecord()->claim_ref.')';
            $state = $marks->settle($offer, false);
            if ($state['unmarked']) {
                if (! $state['marked']) {
                    $touched++;
                    $this->line("{$line}: ложных {$state['unmarked']} из {$state['total']}".($apply ? ', возвращено '.$marks->settle($offer)['undone'] : ''));
                } elseif ($state['unmarked'] < $state['total']) {
                    $this->line("{$line}: со знаком, снято {$state['unmarked']} из {$state['total']}".($apply ? ', дочищено '.$marks->settle($offer)['added'] : ', остальные проверю с поворотом'));
                    $touched++;
                }

                continue;
            }
            $sample = $offer->photos()->filter(fn (Media $m) => $m->getCustomProperty('migtorg'))->take(3);
            // Файл без нашего знака (наш XCAR сбивал проверку) и знак хотя бы на двух кадрах из трёх.
            $found = $sample->filter(function (Media $m) {
                $source = UnmarkPhoto::source($m);
                $clean = is_file($source) ? app(Unmark::class)($source) : null;
                $clean && @unlink($clean['path']);

                return (bool) $clean;
            })->count();
            if ($found < min(2, $sample->count())) {
                continue;
            }
            $touched++;
            if (! $apply) {
                $this->line("{$line}: знак на {$found} из 3");

                continue;
            }
            $this->line($line.': '.$this->one($offer, $unmark));
            $settled = $marks->settle($offer);
            $settled['added'] && $this->line("  с поворотом ещё {$settled['added']}");
        }
        $this->info($touched.' машин'.($apply ? ' поправлено' : ', ничего не трогал (--apply — сделать)'));

        return self::SUCCESS;
    }
}
