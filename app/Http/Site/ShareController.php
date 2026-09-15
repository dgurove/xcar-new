<?php

namespace App\Http\Site;

use App\Offers\Offer;
use App\Offers\Share\SharePdf;
use App\Offers\Share\Subject;
use App\Purchases\Car;
use App\Purchases\Purchase;
use App\Purchases\Restriction;
use App\Support\Surface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * PDF для «Поделиться»: одним обработчиком по GET (открыть во встроенном
 * браузере приложения или вкладке) и по POST (клиент тянет в память для
 * системного листа). Ошибка сборки — 422 с текстом.
 */
class ShareController
{
    public function pdf(Request $request, Offer $offer, SharePdf $pdf)
    {
        $user = $request->user();
        abort_unless($user->role->canShare() && $offer->isVisibleTo($user), 404);
        if ($offer->share_locked) {
            return response()->json(['message' => Subject::LOCKED], 422);
        }

        return $this->respond($request, Subject::offer($offer), $pdf);
    }

    public function carPdf(Request $request, Purchase $purchase, Car $car, SharePdf $pdf)
    {
        abort_unless($car->purchase_id === $purchase->id, 404);
        if (Surface::current() !== Surface::Crm) {
            abort_unless($purchase->state->isPublic() && $car->is_published, 404);
            abort_if(in_array($car->kind->value, Restriction::hiddenFor($request->user()), true), 404);
        }
        if ($car->share_locked) {
            return response()->json(['message' => Subject::LOCKED], 422);
        }

        return $this->respond($request, Subject::car($car), $pdf);
    }

    private function respond(Request $request, Subject $subject, SharePdf $pdf)
    {
        $data = $request->validate(['photos' => ['required', 'array', 'max:30'], 'photos.*' => ['integer'], 'watermark' => ['nullable']]);
        // Знак снимает только сотрудник: файл с витрины уходит человеку, которого мы не знаем.
        $watermark = $request->user()->isStaff() ? $request->boolean('watermark', true) : true;

        try {
            $path = $pdf->build($subject, array_map('intval', $data['photos']), $watermark);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->file($path, ['Content-Type' => 'application/pdf', 'Content-Disposition' => "inline; filename*=UTF-8''".rawurlencode($subject->fileName()), 'Cache-Control' => 'private, max-age=3600']);
    }

    /** Сбой на телефоне — единственный след того, почему файл не ушёл. */
    public function report(Request $request)
    {
        $data = $request->validate([
            'stage' => 'required|string|max:20',
            'name' => 'nullable|string|max:60',
            'message' => 'nullable|string|max:300',
            'standalone' => 'nullable|boolean',
        ]);

        Log::warning('share: '.$data['stage'].' — '.($data['name'] ?? '?'), [
            ...$data,
            'user' => $request->user()?->id,
            'host' => $request->getHost(),
            'ua' => $request->userAgent(),
        ]);

        return response()->noContent();
    }
}
