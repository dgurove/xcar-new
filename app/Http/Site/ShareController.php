<?php

namespace App\Http\Site;

use App\Offers\Offer;
use App\Offers\Share\SharePdf;
use App\Offers\Share\Subject;
use App\Park\Vehicle;
use App\Purchases\Car;
use App\Purchases\Purchase;
use App\Purchases\Restriction;
use App\Support\Surface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * PDF и фото для «Поделиться»: PDF одним обработчиком по GET (открыть во встроенном
 * браузере приложения или вкладке) и по POST (клиент тянет в память для
 * системного листа). Ошибка сборки — 422 с текстом.
 */
class ShareController
{
    /** Сколько кадров за раз в PDF и ZIP — потолок от ошибки, а не выбор. */
    private const MAX_PHOTOS = 1000;

    /** Кадров за один заход `warm`: 10 ужатий из оригинала — несколько секунд, запрос живёт до 60. */
    private const WARM_BATCH = 10;

    /** ТС парковки: PDF, кадр, кадры заранее и архив — только админам и администраторам парковки (02.11.2026). */
    public function vehiclePdf(Request $request, Vehicle $vehicle, SharePdf $pdf)
    {
        abort_unless($request->user()->sharesPark(), 404);

        return $this->respond($request, Subject::vehicle($vehicle), $pdf);
    }

    public function vehiclePhoto(Request $request, Vehicle $vehicle, int $media, SharePdf $pdf)
    {
        abort_unless($request->user()->sharesPark(), 404);

        return $this->respondPhoto($request, Subject::vehicle($vehicle), $media, $pdf);
    }

    public function vehicleWarm(Request $request, Vehicle $vehicle, SharePdf $pdf)
    {
        abort_unless($request->user()->sharesPark(), 404);

        return $this->warmUp($request, Subject::vehicle($vehicle), $pdf);
    }

    public function vehicleZip(Request $request, Vehicle $vehicle, SharePdf $pdf)
    {
        abort_unless($request->user()->sharesPark(), 404);

        return $this->respondZip($request, Subject::vehicle($vehicle), $pdf);
    }

    public function warm(Request $request, Offer $offer, SharePdf $pdf)
    {
        $user = $request->user();
        abort_unless($user->canShare() && $offer->isVisibleTo($user), 404);
        abort_if($offer->shareLockedFor($user), 422, Subject::LOCKED);

        return $this->warmUp($request, Subject::offer($offer), $pdf);
    }

    public function carWarm(Request $request, Purchase $purchase, Car $car, SharePdf $pdf)
    {
        $this->authorizeCar($request, $purchase, $car);
        abort_if($car->share_locked, 422, Subject::LOCKED);

        return $this->warmUp($request, Subject::car($car), $pdf);
    }

    public function zip(Request $request, Offer $offer, SharePdf $pdf)
    {
        $user = $request->user();
        abort_unless($user->canShare() && $offer->isVisibleTo($user), 404);
        abort_if($offer->shareLockedFor($user), 422, Subject::LOCKED);

        return $this->respondZip($request, Subject::offer($offer), $pdf);
    }

    public function carZip(Request $request, Purchase $purchase, Car $car, SharePdf $pdf)
    {
        $this->authorizeCar($request, $purchase, $car);
        abort_if($car->share_locked, 422, Subject::LOCKED);

        return $this->respondZip($request, Subject::car($car), $pdf);
    }

    private function warmUp(Request $request, Subject $subject, SharePdf $pdf)
    {
        $data = $request->validate(['photos' => ['required', 'array', 'max:'.self::WARM_BATCH], 'photos.*' => ['integer']]);
        $pdf->warm($subject, array_map('intval', $data['photos']), $this->watermark($request));

        return response()->noContent();
    }

    private function respondZip(Request $request, Subject $subject, SharePdf $pdf)
    {
        $data = $request->validate(['photos' => ['required', 'array', 'max:'.self::MAX_PHOTOS], 'photos.*' => ['integer']]);
        try {
            $path = $pdf->zip($subject, array_map('intval', $data['photos']), $this->watermark($request));
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return $this->send($request, $path, ['Content-Type' => 'application/zip', 'Content-Disposition' => "attachment; filename*=UTF-8''".rawurlencode($subject->zipName()), 'Cache-Control' => 'private, max-age=3600']);
    }

    public function pdf(Request $request, Offer $offer, SharePdf $pdf)
    {
        $user = $request->user();
        abort_unless($user->canShare() && $offer->isVisibleTo($user), 404);
        if ($offer->shareLockedFor($user)) {
            return response()->json(['message' => Subject::LOCKED], 422);
        }

        return $this->respond($request, Subject::offer($offer), $pdf);
    }

    public function carPdf(Request $request, Purchase $purchase, Car $car, SharePdf $pdf)
    {
        $this->authorizeCar($request, $purchase, $car);
        if ($car->share_locked) {
            return response()->json(['message' => Subject::LOCKED], 422);
        }

        return $this->respond($request, Subject::car($car), $pdf);
    }

    /** Те же фото по одному JPEG: «Фото» в шторке отдаёт их листу пачкой, и WhatsApp собирает альбом. */
    public function photo(Request $request, Offer $offer, int $media, SharePdf $pdf)
    {
        $user = $request->user();
        abort_unless($user->canShare() && $offer->isVisibleTo($user), 404);
        abort_if($offer->shareLockedFor($user), 422, Subject::LOCKED);

        return $this->respondPhoto($request, Subject::offer($offer), $media, $pdf);
    }

    public function carPhoto(Request $request, Purchase $purchase, Car $car, int $media, SharePdf $pdf)
    {
        $this->authorizeCar($request, $purchase, $car);
        abort_if($car->share_locked, 422, Subject::LOCKED);

        return $this->respondPhoto($request, Subject::car($car), $media, $pdf);
    }

    private function authorizeCar(Request $request, Purchase $purchase, Car $car): void
    {
        abort_unless($car->purchase_id === $purchase->id, 404);
        if (Surface::current() !== Surface::Crm) {
            abort_unless($purchase->state->isPublic() && $purchase->showsOnSite($car), 404);
            abort_if(in_array($car->kind->value, Restriction::hiddenFor($request->user()), true), 404);
        }
    }

    private function respondPhoto(Request $request, Subject $subject, int $id, SharePdf $pdf)
    {
        $photos = $subject->photos();
        $index = $photos->search(fn ($m) => $m->id === $id);
        abort_if($index === false, 404);
        $path = $pdf->jpeg($subject, $photos[$index], $this->watermark($request));

        return $this->send($request, $path, ['Content-Type' => 'image/jpeg', 'Content-Disposition' => "inline; filename*=UTF-8''".rawurlencode($subject->photoName($index + 1)), 'Cache-Control' => 'private, max-age=3600']);
    }

    // Галка «Водяной знак на фото» — у всех, по умолчанию выключена везде.
    // Без параметра знак ставится: незнакомый запрос уходит со знаком.
    private function watermark(Request $request): bool
    {
        return $request->boolean('watermark', true);
    }

    private function respond(Request $request, Subject $subject, SharePdf $pdf)
    {
        // Без потолка в 30 кадров (02.11.2026, владелец: «должна быть возможность выбирать все — и сотни»): кадры шторка
        // готовит заранее (`warm`), PDF лишь складывает их.
        $data = $request->validate(['photos' => ['required', 'array', 'max:'.self::MAX_PHOTOS], 'photos.*' => ['integer'], 'watermark' => ['nullable']]);
        $watermark = $this->watermark($request);

        try {
            $path = $pdf->build($subject, array_map('intval', $data['photos']), $watermark);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return $this->send($request, $path, ['Content-Type' => 'application/pdf', 'Content-Disposition' => "inline; filename*=UTF-8''".rawurlencode($subject->fileName()), 'Cache-Control' => 'private, max-age=3600']);
    }

    /**
     * Готовый файл отдаёт Caddy (`X-Accel-Redirect`, `intercept` в `deploy/Caddyfile`), а не PHP (17.10.2026): PDF в
     * 3–4 МБ телефону по слабой сети качался дольше 30 с — воркер был занят всё это время и падал «Maximum execution
     * time» на середине. Заголовок `X-Share-Sendfile` ставит сам Caddy; без него (локально) — как раньше.
     *
     * @param  array<string, string>  $headers
     */
    private function send(Request $request, string $path, array $headers)
    {
        $root = rtrim(Storage::disk(SharePdf::DISK)->path('share'), '/').'/';
        if (! $request->headers->has('X-Share-Sendfile') || ! str_starts_with($path, $root)) {
            return response()->file($path, $headers);
        }

        return response('', 200, $headers + ['X-Accel-Redirect' => '/'.implode('/', array_map('rawurlencode', explode('/', substr($path, strlen($root)))))]);
    }

    /** Сбой на телефоне — единственный след того, почему файл не ушёл. */
    public function report(Request $request)
    {
        $data = $request->validate([
            'stage' => 'required|string|max:20',
            'name' => 'nullable|string|max:60',
            'message' => 'nullable|string|max:300',
            'standalone' => 'nullable|boolean',
            'files' => 'nullable|integer',
            'bytes' => 'nullable|integer',
            'canshare' => 'nullable|boolean',
        ]);

        // Свой файл: stderr контейнера выкладка стирает, а сбой с чужого телефона нужен и через день.
        Log::channel('share')->warning('share: '.$data['stage'].' — '.($data['name'] ?? '?'), [
            ...$data,
            'user' => $request->user()?->id,
            'host' => $request->getHost(),
            'ua' => $request->userAgent(),
        ]);

        return response()->noContent();
    }
}
