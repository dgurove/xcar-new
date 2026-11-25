<?php

namespace App\Http\Admin;

use App\Mail\Extraction\ArchivePhotoExtractor;
use App\Mail\Extraction\DocumentText;
use App\Mail\Scan\OfferSubject;
use App\Mail\Scan\Paper;
use App\Mail\Scan\Reader;
use App\Media\Actions\RotatePhoto;
use App\Media\DocName;
use App\Media\ForManagers;
use App\Media\Hidden;
use App\Media\PdfPhotos;
use App\Media\PhotoBlocks;
use App\Media\PhotoIngest;
use App\Offers\Actions\UpdateOffer;
use App\Offers\Jobs\ImportMigtorgLot;
use App\Offers\Jobs\ImportOfferArchive;
use App\Offers\Migtorg;
use App\Offers\Offer;
use App\Offers\OfferEventType;
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
            } elseif ($request->input('collection') !== 'papers' && str_ends_with(mb_strtolower($name), '.pdf')
                && ($pages = PdfPhotos::extract((string) file_get_contents($file->getRealPath()), $name))) {
                // PDF со снимками по кадру на страницу — это фото машины, а не документ (`PdfPhotos`).
                $stage = $offer->parkVehicle ? PhotoStage::tryFrom((string) $request->input('stage')) : null;
                foreach ($pages as $page) {
                    if (! $offer->hasFile(hash('sha256', $page['contents']))) {
                        $ingest->fromString($offer, 'photos', $page['contents'], $page['name'], array_filter(['stage' => $stage?->value]) + Hidden::incoming($offer));
                    }
                }
            } elseif ($request->input('collection') === 'papers' || ! $this->isImage($file, $ingest)) {
                // Отпечаток — как у документов из писем: «✨» читает один и тот же скан один раз (кеш текста по sha).
                $offer->addMedia($file)->usingFileName(self::safeName($name))->withCustomProperties(['sha' => hash_file('sha256', $file->getRealPath())])->toMediaCollection('papers');
            } elseif (! (($uuid = Migtorg::mediaOf($name)) && $offer->media()->where('collection_name', 'photos')->where('custom_properties->migtorg', $uuid)->exists())) {
                // Скрыт ли новый кадр — одно правило (`Hidden::incoming`): в черновике виден сразу, у опубликованного — после глаза.
                // Кадр с сайта Мигторга (уже взятый лотом — пропущен выше) сам называет лот: номер, поля и остальные кадры.
                // Блок, куда добавили (от страховой, при приёме), — стадия кадра машины, если она на парковке.
                $stage = $offer->parkVehicle ? PhotoStage::tryFrom((string) $request->input('stage')) : null;
                $media = $ingest->fromPhone($offer, 'photos', $request, properties: array_filter(['stage' => $stage?->value]) + Hidden::incoming($offer));
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
        self::applyOrder($offer, $order, $request->boolean('main'));

        return $this->gallery($offer->refresh());
    }

    /**
     * Порядок присылает один блок (от страховой, при приёме): его кадры встают на свои места в общей ленте; звезда (`main`)
     * ставит выбранный кадр первым во всей ленте. Чужие id отбрасываются. Одна дверь CRM и партнёра.
     *
     * @param  list<int>  $order
     */
    public static function applyOrder(Offer $offer, array $order, bool $main): void
    {
        $all = $offer->photos()->pluck('id')->all();
        $ids = array_values(array_intersect(array_map('intval', $order), $all));
        if ($ids) {
            Media::setNewOrder(PhotoBlocks::order($all, $ids, $main));
        }
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

    /**
     * «Распознать» в меню документа (23.11.2026, владелец: «распознания очень не хватает: документ прикреплён, почему-то не
     * распознался, и непонятно, как запустить»): прочитанный текст забыт, документ читается заново впереди очереди; по концу
     * чтения найденное встаёт само или вариантами в сводке ТС (`AutoScan::finished` → `Cars\Sources\Collect`).
     */
    public function scan(Request $request, Offer $offer, Media $media)
    {
        $this->own($offer, $media);
        abort_unless($media->collection_name === 'papers', 404);
        $file = new Paper($media);
        abort_unless(DocumentText::scannable($file), 422, 'Этот файл не распознать');
        DocumentText::forget($file);
        Reader::read(OfferSubject::for($offer, $request->user()), [$file->scanId()]);

        return back()->with('toast', 'Распознаём документ: найденное встанет в данные ТС');
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

    public function destroy(Offer $offer, Media $media)
    {
        $this->own($offer, $media);
        // Кадр парковки — её запись о машине: из продажи он уходит глазом, а не корзиной.
        abort_if(Sale::parkOwned($media), 403);
        $media->delete();

        return $this->gallery($offer->refresh());
    }

    /**
     * Переименовать документ предложения (`x-ui.file-rename`, `Media\DocName`). И документ парковки (23.11.2026, владелец:
     * «почему не везде карандашик»): имя одно на CRM и парковку, переименовать не значит удалить; удаляют его на парковке.
     */
    public function rename(Request $request, Offer $offer, Media $media)
    {
        $this->own($offer, $media);
        abort_if($media->collection_name !== 'papers', 403);
        $data = $request->validate(['name' => ['nullable', 'string', 'max:200']]);
        [$was, $now] = DocName::rename($media, (string) ($data['name'] ?? ''));
        if ($was !== $now) {
            $offer->log(OfferEventType::Note, $request->user(), ['text' => 'Переименован документ: '.$was.' → '.$now]);
        }

        return $request->expectsJson() ? response()->json(['name' => $now]) : back()->with('toast', 'Переименовано');
    }

    /** Кадр по содержимому: картинка или HEIC (его mime бывает любым). */
    private function isImage(UploadedFile $file, PhotoIngest $ingest): bool
    {
        return str_starts_with((string) $file->getMimeType(), 'image/') || $ingest->isHeic($file->getRealPath());
    }

    private function own(Offer $offer, Media $media): void
    {
        // Кадр машины, что виден продаже (`Offer::carMediaScope`).
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
