<?php

namespace App\Http\Site;

use App\Offers\Offer;
use App\Offers\Share\SharePdf;
use Illuminate\Http\Request;

class ShareController
{
    public function pdf(Request $request, Offer $offer, SharePdf $pdf)
    {
        $user = $request->user();
        abort_unless($offer->state->isPublic() || $offer->state->acceptsInterest() || $user->isStaff(), 404);
        $data = $request->validate(['photos' => ['required', 'array', 'max:30'], 'photos.*' => ['integer'], 'watermark' => ['nullable']]);
        // Знак снимает только сотрудник: файл с витрины уходит человеку, которого мы не знаем.
        $watermark = $user->isStaff() ? $request->boolean('watermark', true) : true;

        try {
            $path = $pdf->build($offer, array_map('intval', $data['photos']), $watermark);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->file($path, ['Content-Type' => 'application/pdf', 'Content-Disposition' => "inline; filename*=UTF-8''".rawurlencode($pdf->fileName($offer)), 'Cache-Control' => 'private, max-age=3600']);
    }
}
