<?php

namespace App\Http\Admin;

use App\Mail\Extraction\ArchivePhotoExtractor;
use App\Media\Actions\RotatePhoto;
use App\Media\Actions\UnmarkPhoto;
use App\Media\Hidden;
use App\Media\PhotoIngest;
use App\Media\Unmark;
use App\Media\Watermarks;
use App\Offers\Jobs\ImportMigtorgLot;
use App\Offers\Jobs\ImportOfferArchive;
use App\Offers\Offer;
use App\Park\Sale;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

/**
 * Фото и документы оффера. Загрузка по одному файлу за запрос — так работает прогресс на телефоне. В «Фотографии» можно
 * бросить что угодно: документ уйдёт в документы, архив разберёт очередь (кадры — к фото, остальное — к документам).
 */
class OfferPhotoController
{
    public function store(Request $request, Offer $offer, PhotoIngest $ingest)
    {
        $request->validate(['file' => ['required', 'file', 'max:65536']]);
        $file = $request->file('file');
        $name = $file->getClientOriginalName();

        try {
            if ($request->input('collection') !== 'papers' && ArchivePhotoExtractor::isArchiveName($name)) {
                $path = $file->storeAs('archives', Str::uuid().'.'.strtolower($file->getClientOriginalExtension()), 'private');
                ImportOfferArchive::dispatch($offer->id, $path, $name);
            } elseif ($request->input('collection') === 'papers' || ! $this->isImage($file, $ingest)) {
                // Отпечаток — как у документов из писем: «✨» читает один и тот же скан один раз (кеш текста по sha).
                $offer->addMedia($file)->usingFileName(self::safeName($name))->withCustomProperties(['sha' => hash_file('sha256', $file->getRealPath())])->toMediaCollection('papers');
            } else {
                $ingest->fromPhone($offer, 'photos', $request);
            }
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return $this->gallery($offer);
    }

    /** «С Мигторга»: фото лота по номеру убытка к тем, что уже есть (сами они берутся только в пустой ряд). */
    public function migtorg(Offer $offer)
    {
        $lot = ImportMigtorgLot::forButton($offer);
        abort_unless($lot, 404);
        ImportMigtorgLot::start($offer, $lot, manual: true);

        return back()->with('toast', 'Фото с Мигторга скоро будут');
    }

    public function reorder(Request $request, Offer $offer)
    {
        $order = $request->validate(['order' => ['required', 'array'], 'order.*' => ['integer']])['order'];
        Media::setNewOrder(array_values(array_intersect($order, $offer->photos()->pluck('id')->all())));

        return $this->gallery($offer->refresh());
    }

    public function toggle(Offer $offer, Media $media)
    {
        $this->own($offer, $media);
        Hidden::toggle($media);

        return $this->gallery($offer->refresh());
    }

    public function rotate(Request $request, Offer $offer, Media $media, RotatePhoto $rotate)
    {
        $this->own($offer, $media);
        $rotate($media, RotatePhoto::turns($request));

        return $this->gallery($offer->refresh());
    }

    /** Шторка «Водяной знак» из просмотрщика: снят ли знак, сравнить со знаком, вернуть, заменить своим файлом. */
    public function mark(Offer $offer, Media $media)
    {
        $this->own($offer, $media);
        $unmarked = $media->getCustomProperty('unmarked');

        return view('admin.offers.mark-sheet', [
            'offer' => $offer,
            'media' => $media,
            'manual' => $unmarked === UnmarkPhoto::MANUAL,
            'title' => $unmarked === UnmarkPhoto::MANUAL ? null : Watermarks::title($unmarked),
            'marked' => is_file(UnmarkPhoto::markedPath($media)),
            'marks' => Watermarks::all(),
            // «Со всех фото» — кадры предложения, с которых знак ещё не снимали (этот — первым)
            'rest' => $offer->photos()->reject(fn (Media $m) => $m->getCustomProperty('unmarked'))
                ->sortBy(fn (Media $m) => $m->id === $media->id ? 0 : 1)->pluck('id')->values()->all(),
        ]);
    }

    /** Кадр со знаком площадки — копия на закрытом диске, для сравнения в шторке. */
    public function marked(Offer $offer, Media $media)
    {
        $this->own($offer, $media);
        $path = UnmarkPhoto::markedPath($media);
        abort_unless(is_file($path), 404);

        return response()->file($path, ['Cache-Control' => 'private, no-cache']);
    }

    /**
     * «Вернуть со знаком» (`act=undo`), снять выбранный знак (`act=mark`, `mark`: не определился сам) или свой файл вместо
     * кадра (`file`: почистили знак сами). «Со всех фото» шлёт `act=mark` по кадру за запрос: ручной поиск — секунды.
     */
    public function unmark(Request $request, Offer $offer, Media $media, UnmarkPhoto $unmark, PhotoIngest $ingest)
    {
        $this->own($offer, $media);
        try {
            if ($request->hasFile('file')) {
                $request->validate(['file' => ['file', 'max:65536']]);
                $file = $request->file('file');
                if (! $this->isImage($file, $ingest)) {
                    return response()->json(['message' => 'Это не фото'], 422);
                }
                $unmark->replace($media, $file->getRealPath(), $ingest);
            } elseif ($request->input('act') === 'mark') {
                $mark = (string) $request->input('mark');
                abort_unless(array_key_exists($mark, Watermarks::all()), 422);
                if ($media->getCustomProperty('unmarked')) {
                    return $this->gallery($offer->refresh());
                }
                if (! $unmark($media, $mark)) {
                    return response()->json(['message' => 'Знак не нашёлся на фото'], 422);
                }
            } elseif ($request->input('act') !== 'undo' || ! $unmark->undo($media)) {
                return response()->json(['message' => 'Кадра со знаком нет'], 422);
            }
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return $this->gallery($offer->refresh());
    }

    /**
     * Новый знак рамкой: обвели знак на этом фото, знак собирается по нему и остальным фото предложения (до 12 — хватает
     * с запасом, а запрос укладывается в лимит Octane). Ответ — название и сколько фото пошло; шторка открывается заново.
     */
    public function learn(Request $request, Offer $offer, Media $media, Unmark $unmark)
    {
        $this->own($offer, $media);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:40'],
            'x0' => ['required', 'numeric', 'between:0,1'], 'y0' => ['required', 'numeric', 'between:0,1'],
            'x1' => ['required', 'numeric', 'between:0,1'], 'y1' => ['required', 'numeric', 'between:0,1'],
        ]);
        if ($data['x1'] - $data['x0'] < 0.03 || $data['y1'] - $data['y0'] < 0.02) {
            return response()->json(['message' => 'Обведите знак целиком'], 422);
        }
        $photos = collect([$media])->merge($offer->photos()->reject(fn (Media $m) => $m->id === $media->id))
            ->reject(fn (Media $m) => $m->getCustomProperty('unmarked') === UnmarkPhoto::MANUAL)
            ->map(fn (Media $m) => UnmarkPhoto::markedSource($m))->filter()->take(12)->values()->all();
        try {
            $mark = $unmark->learn(trim($data['title']), [$data['x0'], $data['y0'], $data['x1'], $data['y1']], $photos);
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => "Знак «{$data['title']}» собран по {$mark['used']} фото"]);
    }

    public function destroy(Offer $offer, Media $media)
    {
        $this->own($offer, $media);
        // Кадр парковки — её запись о машине: из продажи он уходит глазом, а не корзиной.
        abort_if(Sale::parkOwned($media), 403);
        $media->delete();

        return $this->gallery($offer->refresh());
    }

    /** Кадр по содержимому: картинка или HEIC (его mime бывает любым). */
    private function isImage(UploadedFile $file, PhotoIngest $ingest): bool
    {
        return str_starts_with((string) $file->getMimeType(), 'image/') || $ingest->isHeic($file->getRealPath());
    }

    private function own(Offer $offer, Media $media): void
    {
        // Свой кадр или кадр ТС парковки, что идёт в продажу (`SaleMedia`).
        abort_unless($offer->media()->whereKey($media->id)->exists(), 404);
    }

    private function gallery(Offer $offer)
    {
        $offer->unsetRelation('media');

        // Ряд кадров карточки строки — свой (без корзины, нажатие прячет); его id присылает photos_controller.
        return response()
            ->view('admin.offers.gallery-stream', ['offer' => $offer, 'detail' => request()->header('X-Photos-Target') === 'detail-photos'])
            ->header('Content-Type', 'text/vnd.turbo-stream.html');
    }

    public static function safeName(string $name): string
    {
        $ext = pathinfo($name, PATHINFO_EXTENSION);
        $base = trim(preg_replace('/[^\p{L}\p{N}._-]+/u', '-', pathinfo($name, PATHINFO_FILENAME)), '-.') ?: 'dokument';

        return $base.($ext ? '.'.strtolower($ext) : '');
    }
}
