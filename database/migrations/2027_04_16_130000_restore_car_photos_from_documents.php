<?php

use App\Cars\Car;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Кадры машин, что ушли в «Документы» (17.11.2026, владелец: «почему в 638141/2026 все фотки в документы ушли»): письмо страховой
 * в сделке с пачкой фото лота легло картинками-документами и утащило за собой кадры с Мигторга с теми же картинками
 * (`ShareReplyFiles::photoToDocument`, правило поправлено). Три машины — картинки того разбора (окно в секундах) обратно в кадры:
 * с Мигторга — видимыми, как были; из письма — скрытыми, как кадры писем; повтор одной картинки (письмо и лот) — один кадр.
 * Настоящие документы-картинки (акт, ПЗН) лежали раньше и не трогаются.
 */
return new class extends Migration
{
    /** car_id => [с, по] разбора письма. */
    private const BATCHES = [
        2597 => ['2026-11-06 14:49:50', '2026-11-06 14:50:10'], // Changan CS35 Plus, 638141/2026, письмо 8012
        2546 => ['2026-11-09 11:55:20', '2026-11-09 11:55:35'], // Тонар 998940, письмо 8051
        1747 => ['2026-11-02 10:14:20', '2026-11-02 10:14:40'], // Renault T-SERIES, письмо 7771
    ];

    public function up(): void
    {
        foreach (self::BATCHES as $carId => [$from, $to]) {
            $car = Car::find($carId);
            if (! $car) {
                continue;
            }
            $batch = Media::where('model_type', $car->getMorphClass())->where('model_id', $car->id)->where('collection_name', 'papers')
                ->where('mime_type', 'like', 'image/%')->whereBetween('created_at', [Carbon::parse($from), Carbon::parse($to)])->get()
                // С Мигторга — первыми: повтор той же картинки из письма тогда отпадает.
                ->sortByDesc(fn (Media $m) => (bool) $m->getCustomProperty('migtorg'))->values();
            $seen = [];
            foreach ($batch as $m) {
                $sha = (string) $m->getCustomProperty('sha');
                if ($sha !== '' && isset($seen[$sha])) {
                    $m->delete();

                    continue;
                }
                $seen[$sha] = true;
                $props = $m->custom_properties;
                $migtorg = (bool) ($props['migtorg'] ?? false);
                try {
                    $moved = $m->move($car, 'photos');
                } catch (\Throwable $e) {
                    // Файла нет (копия базы без медиатеки) — пропускаем, запись в журнал.
                    \Illuminate\Support\Facades\Log::info('Кадр не вернулся из документов', ['media' => $m->id, 'error' => $e->getMessage()]);

                    continue;
                }
                $moved->forceFill(['custom_properties' => $migtorg ? array_diff_key($props, ['hidden' => true]) : [...$props, 'hidden' => true]])->save();
            }
        }
    }

    public function down(): void {}
};
