<?php

namespace App\Http\Admin;

use App\Mail\Extraction\ArchivePhotoExtractor;
use App\Media\Actions\RotatePhoto;
use App\Media\Actions\UnmarkPhoto;
use App\Media\ForManagers;
use App\Media\Hidden;
use App\Media\PhotoBlocks;
use App\Media\PhotoIngest;
use App\Media\Unmark;
use App\Media\Watermarks;
use App\Offers\Actions\UpdateOffer;
use App\Offers\Jobs\ImportMigtorgLot;
use App\Offers\Jobs\ImportOfferArchive;
use App\Offers\Migtorg;
use App\Offers\Offer;
use App\Park\PhotoStage;
use App\Park\Sale;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Blade;
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
            } elseif (! (($uuid = Migtorg::mediaOf($name)) && $offer->media()->where('collection_name', 'photos')->where('custom_properties->migtorg', $uuid)->exists())) {
                // Добавленное руками сначала скрыто (владелец, 04.10.2026): что показать, решают глазом или «Показать все».
                // Кадр с сайта Мигторга (уже взятый лотом — пропущен выше) сам называет лот: номер, поля и остальные кадры.
                $media = $ingest->fromPhone($offer, 'photos', $request, properties: ['hidden' => true]);
                ($uuid = $media->getCustomProperty('migtorg')) && ImportMigtorgLot::byPhoto($offer, $uuid, $request->user());
            }
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return $this->gallery($offer);
    }

    /**
     * Шторка «Мигторг»: `take` — «Это она»: человек сверил машину лота и берёт поля и кадры (номер из одних цифр сам не
     * берётся); `recheck` — «Проверить ещё раз». Номер убытка, вписанный и ещё не сохранённый, приходит полем `ref` —
     * сначала он ложится в предложение.
     */
    public function migtorg(Request $request, Offer $offer, UpdateOffer $update)
    {
        $data = $request->validate(['act' => ['required', 'in:take,recheck'], 'ref' => ['nullable', 'string', 'max:60']]);
        if (filled($data['ref'] ?? null) && trim($data['ref']) !== (string) $offer->claim_ref) {
            $update($offer, ['claim_ref' => trim($data['ref'])], $request->user());
            $offer->refresh();
        }
        if ($data['act'] === 'recheck') {
            return back()->with('toast', ImportMigtorgLot::available($offer) ? 'Лот на Мигторге нашёлся' : 'На Мигторге пока нет');
        }

        return back()->with('toast', ImportMigtorgLot::take($offer, $request->user()));
    }

    /** Чип «Мигторг» по номеру, вписанному в поле и ещё не сохранённому: лот виден сразу, «Это она» его и сохранит. */
    public function migtorgChip(Request $request, Offer $offer)
    {
        $ref = trim((string) $request->query('ref'));
        if ($ref !== '' && $ref !== (string) $offer->claim_ref) {
            $offer = (clone $offer)->forceFill(['claim_ref' => $ref]);
        }

        return response(Blade::render('<x-offer.migtorg-field :offer="$offer"/>', ['offer' => $offer]));
    }

    public function reorder(Request $request, Offer $offer)
    {
        $order = $request->validate(['order' => ['required', 'array'], 'order.*' => ['integer']])['order'];
        // Порядок присылает один блок (от страховой, при приёме): его кадры встают на свои места в общей ленте;
        // звезда (`main`) ставит выбранный кадр первым во всей ленте.
        $all = $offer->photos()->pluck('id')->all();
        $ids = array_values(array_intersect($order, $all));
        if ($ids) {
            Media::setNewOrder(PhotoBlocks::order($all, $ids, $request->boolean('main')));
        }

        return $this->gallery($offer->refresh());
    }

    public function toggle(Offer $offer, Media $media)
    {
        $this->own($offer, $media);
        Hidden::toggle($media);

        return $this->gallery($offer->refresh());
    }

    /** «Видно менеджеру» у документа: открыть его держателю гаража, вывозчику и менеджеру сделки — или закрыть. */
    public function managers(Offer $offer, Media $media)
    {
        $this->own($offer, $media);
        abort_unless($media->collection_name === 'papers', 404);
        ForManagers::toggle($media);

        return $this->gallery($offer->refresh());
    }

    public function visibility(Request $request, Offer $offer)
    {
        // «Показать все» / «Скрыть все» блока — его стадии; без стадии — все кадры.
        $stage = PhotoStage::tryFrom((string) $request->input('stage'));
        Hidden::set($offer->photos()->filter(fn ($m) => ! $stage || PhotoStage::of($m) === $stage), $request->boolean('hidden'));

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
            ->view('admin.offers.gallery-stream', ['offer' => $offer, 'detail' => str_starts_with((string) request()->header('X-Photos-Target'), 'detail-photos')])
            ->header('Content-Type', 'text/vnd.turbo-stream.html');
    }

    public static function safeName(string $name): string
    {
        $ext = pathinfo($name, PATHINFO_EXTENSION);
        $base = trim(preg_replace('/[^\p{L}\p{N}._-]+/u', '-', pathinfo($name, PATHINFO_FILENAME)), '-.') ?: 'dokument';

        return $base.($ext ? '.'.strtolower($ext) : '');
    }
}
