<?php

namespace App\Media;

use App\Media\Actions\UnmarkPhoto;
use App\Offers\Offer;
use Illuminate\Support\Facades\Log;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

/**
 * Знак Мигторга — решение по лоту, а не по кадру (21.11.2026): «оригинал» лота либо весь со знаком (75 из 78 кадров), либо
 * без него, а поиск знака изредка срабатывает на листе осмотра или смете (1 из 45, 2 из 101). Приём снимает знак по кадру
 * (`PhotoIngest::place`), итог по машине ставит `settle`: лот со знаком — оставшиеся кадры ещё раз и с поворотом на бок
 * (знак ставят до поворота снимка, Renault 3703); лот без знака — одиночные снятия ложные, кадр возвращается как был.
 * Снятое руками (свой файл, знак выбран в шторке) не трогается. Зовут `ImportMigtorgLot` и `media:unmark --migtorg`.
 */
final class MigtorgMarks
{
    public function __construct(private UnmarkPhoto $unmark) {}

    /** Лот со знаком: снято хотя бы у двух кадров и не меньше пятой части. */
    public static function marked(int $unmarked, int $total): bool
    {
        return $unmarked >= max(2, (int) ceil($total * 0.2));
    }

    /**
     * @return array{marked: bool, total: int, unmarked: int, added: int, undone: int} без $apply — только счёт
     */
    public function settle(Offer $offer, bool $apply = true): array
    {
        $photos = $offer->photos()->filter(fn (Media $m) => $m->getCustomProperty('migtorg'))->values();
        $auto = $photos->reject(fn (Media $m) => $m->getCustomProperty('unmarked') === UnmarkPhoto::MANUAL || $m->getCustomProperty('unmark_chosen'));
        $done = $auto->filter(fn (Media $m) => $m->getCustomProperty('unmarked'));
        $result = ['marked' => self::marked($done->count(), $photos->count()), 'total' => $photos->count(), 'unmarked' => $done->count(), 'added' => 0, 'undone' => 0];
        if (! $apply || ! $done->count()) {
            return $result;
        }
        if ($result['marked']) {
            foreach ($auto->reject(fn (Media $m) => $m->getCustomProperty('unmarked')) as $media) {
                $result['added'] += (int) (bool) $this->safely(fn () => ($this->unmark)($media, null, true), $media);
            }
        } else {
            foreach ($done as $media) {
                $result['undone'] += (int) $this->safely(fn () => $this->unmark->undo($media), $media);
            }
        }

        return $result;
    }

    /** Кадр, что не дался, — в лог: остальные кадры и приём лота из-за него не встают. */
    private function safely(callable $do, Media $media): mixed
    {
        try {
            return $do();
        } catch (Throwable $e) {
            Log::warning("Знак Мигторга: кадр {$media->id} — {$e->getMessage()}");

            return false;
        }
    }
}
