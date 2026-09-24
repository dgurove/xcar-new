<?php

namespace App\Vendors\Actions;

use App\Media\PhotoIngest;
use App\Vendors\Vendor;
use Illuminate\Http\Request;

/** Логотип вендора из шторки CRM или парковки: новый файл заменяет прежний, «Убрать логотип» — снимает. */
final class SetLogo
{
    public const RULES = [
        'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:4096'],
        'remove_logo' => ['nullable', 'boolean'],
    ];

    public function __invoke(Vendor $vendor, Request $request): void
    {
        if ($request->hasFile('logo')) {
            // 256 px хватает с запасом: логотип стоит в размер шрифта, крупнее всего — в строке списка вендоров.
            app(PhotoIngest::class)->fromUpload($vendor, 'logo', $request->file('logo'), max: 256);
        } elseif ($request->boolean('remove_logo')) {
            $vendor->clearMediaCollection('logo');
        }
    }
}
