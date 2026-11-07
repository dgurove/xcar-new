<?php

namespace App\Http\Partner;

use App\Http\Admin\OfferPhotoController;
use App\Media\Hidden;
use App\Media\PhotoIngest;
use App\Offers\Offer;
use App\Offers\Partner\PartnerOffers;
use App\Park\Sale;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

/**
 * Фото и документы черновика у поставщика (partner.xcar.ru, 07.11.2026). Отдаются только своими адресами — `/photos/{id}`
 * и `/files/{id}`, с нейтральным именем («Фото 3», «Документ 2»): имя файла на диске и адрес медиатеки (`/media/…`) у
 * кадров, взятых не руками, говорят, откуда они. Видно — кадры и документы машины, кроме скрытых и файлов парковки.
 * Загрузка — простая (`PhotoIngest`, свойство `partner`): ни снятия знаков, ни разрезки листов, ни чтения документов.
 * Удаляет поставщик только своё. Принимаются только кадры и документы (PDF, Word, Excel); на экране открываются только
 * картинки и PDF, остальное — скачиванием: HTML или SVG от внешнего человека не должны выполниться на хосте `*.xcar.ru`.
 */
class MediaController
{
    /** Что поставщик может приложить — по расширению файла. */
    public const TYPES = ['jpg', 'jpeg', 'png', 'webp', 'heic', 'heif', 'pdf', 'doc', 'docx', 'xls', 'xlsx'];

    public function store(Request $request, Offer $offer, PhotoIngest $ingest)
    {
        $user = $request->user();
        abort_unless(PartnerOffers::opens($user, $offer), 404);
        $request->validate(['file' => ['required', 'file', 'max:65536']]);
        $file = $request->file('file');
        if (! in_array(strtolower($file->getClientOriginalExtension()), self::TYPES, true)) {
            return response()->json(['message' => 'Можно фото, PDF, Word и Excel'], 422);
        }
        try {
            if ($request->input('collection') === 'papers' || ! $this->isImage($file, $ingest)) {
                $offer->addMedia($file)->usingFileName(OfferPhotoController::safeName($file->getClientOriginalName()))
                    ->withCustomProperties(['sha' => hash_file('sha256', $file->getRealPath()), 'partner' => $user->id])->toMediaCollection('papers');
            } else {
                $ingest->fromPhone($offer, 'photos', $request, properties: ['partner' => $user->id] + Hidden::incoming($offer));
            }
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'Файл не принят'], 422);
        }

        return $this->stream($offer);
    }

    /** Порядок кадров перетаскиванием и звездой — та же дверь, что в CRM (`OfferPhotoController::applyOrder`). */
    public function order(Request $request, Offer $offer)
    {
        abort_unless(PartnerOffers::opens($request->user(), $offer), 404);
        $data = $request->validate(['order' => ['required', 'array'], 'order.*' => ['integer']]);
        OfferPhotoController::applyOrder($offer, $data['order'], $request->boolean('main'));

        return $this->stream($offer->refresh());
    }

    public function destroy(Request $request, Offer $offer, Media $media)
    {
        $user = $request->user();
        abort_unless(PartnerOffers::opens($user, $offer) && $offer->media()->whereKey($media->id)->exists(), 404);
        abort_unless((int) $media->getCustomProperty('partner') === $user->id, 403);
        $media->delete();

        return $this->stream($offer->refresh());
    }

    public function photo(Request $request, Media $media, ?string $size = null)
    {
        $this->visible($request, $media, 'photos');
        $path = $size && $media->hasGeneratedConversion($size) ? $media->getPath($size) : $media->getPath();
        abort_unless(is_file($path), 404);

        return response()->file($path, [
            'Content-Type' => $size && $media->hasGeneratedConversion($size) ? 'image/webp' : ($media->mime_type ?: 'image/jpeg'),
            'Content-Disposition' => 'inline; filename="'.self::photoName($media).'"',
            'Cache-Control' => 'private, max-age=86400',
            'X-Robots-Tag' => 'noindex',
        ]);
    }

    public function file(Request $request, Media $media)
    {
        $this->visible($request, $media, 'papers');
        abort_unless(is_file($path = $media->getPath()), 404);
        $name = self::paperName($media);
        // На экране — только картинки (не SVG) и PDF, как у CRM (`Site\FileController`); остальное — скачиванием.
        $mime = (string) $media->mime_type;
        $inline = ! $request->boolean('download') && ((str_starts_with($mime, 'image/') && $mime !== 'image/svg+xml') || $mime === 'application/pdf');

        return response()->file($path, [
            'Content-Type' => $mime ?: 'application/octet-stream',
            'Content-Disposition' => ($inline ? 'inline' : 'attachment').'; filename*=UTF-8\'\''.rawurlencode($name),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=3600',
            'X-Robots-Tag' => 'noindex',
        ]);
    }

    /**
     * Что поставщику показывать из файлов машины: не скрытое и не парковочное.
     *
     * @return array{photos: \Illuminate\Support\Collection<int, Media>, papers: \Illuminate\Support\Collection<int, Media>}
     */
    public static function shown(Offer $offer): array
    {
        $ok = fn (Media $m) => ! Hidden::is($m) && ! Sale::parkOwned($m);

        return ['photos' => $offer->photos()->filter($ok)->values(), 'papers' => $offer->papers()->filter(fn (Media $m) => ! Sale::parkOwned($m))->values()];
    }

    /**
     * Имя документа на экране и при скачивании: своё — как загрузил, остальное — «Документ N» с расширением. N — место в
     * списке документов машины: список отдаёт его сам (`$n`), отдача файла считает одним запросом.
     */
    public static function paperName(Media $media, ?int $n = null): string
    {
        $ext = strtolower(pathinfo($media->file_name, PATHINFO_EXTENSION));
        if ($media->getCustomProperty('partner')) {
            return $media->file_name;
        }
        // Тот же счёт, что в списке (`shown`: без файлов парковки), — иначе «Документ 3» скачивался бы «Документом 4».
        $n ??= (($offer = Offer::where('car_id', $media->model_id)->first()) ? (int) self::shown($offer)['papers']->search(fn (Media $m) => $m->id === $media->id) : 0) + 1;

        return 'Документ '.$n.($ext ? '.'.$ext : '');
    }

    public static function photoName(Media $media): string
    {
        return 'foto-'.$media->id.'.'.(strtolower(pathinfo($media->file_name, PATHINFO_EXTENSION)) ?: 'jpg');
    }

    /**
     * Кадр или документ машины черновика, открытого поставщику; скрытого и парковочного — нет. Машины его списка — одним
     * запросом на минуту (миниатюр на экране десятки, у каждой свой запрос): ушедшее от него закрывается не позже чем через
     * минуту и сразу — при его сохранении (`forget`); новое — сразу (промах переспрашивает).
     */
    private function visible(Request $request, Media $media, string $collection): void
    {
        abort_unless($media->model_type === \App\Cars\Car::class && $media->collection_name === $collection && ! Sale::parkOwned($media)
            && ($collection !== 'photos' || ! Hidden::is($media)), 404);
        $user = $request->user();
        $cars = fn () => PartnerOffers::for($user)->pluck('offers.car_id')->map(fn ($id) => (int) $id)->all();
        $car = (int) $media->model_id;
        // Нет в запомненном — спросить заново: черновик могли завести наши минуту назад.
        if (! in_array($car, Cache::remember(self::carsKey($user->id), 60, $cars), true)) {
            Cache::put(self::carsKey($user->id), $fresh = $cars(), 60);
            abort_unless(in_array($car, $fresh, true), 404);
        }
    }

    public static function carsKey(int $userId): string
    {
        return "partner:cars:{$userId}";
    }

    /** Список поставщика поменялся (отдал цену, завёл, удалил) — кадры его машин считаются заново. */
    public static function forget(int $userId): void
    {
        Cache::forget(self::carsKey($userId));
    }

    private function isImage(UploadedFile $file, PhotoIngest $ingest): bool
    {
        return str_starts_with((string) $file->getMimeType(), 'image/') || $ingest->isHeic($file->getRealPath());
    }

    private function stream(Offer $offer)
    {
        $offer->unsetRelation('media');

        return response()->view('partner.offers.media-stream', ['offer' => $offer])->header('Content-Type', 'text/vnd.turbo-stream.html');
    }
}
