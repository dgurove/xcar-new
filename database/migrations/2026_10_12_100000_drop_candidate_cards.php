<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Кадр карточки цепочки «Из писем» больше не заводится: плиток и окошка того раздела нет, а фото письма и так
 * видны лентой миниатюр в окне писем. `Candidate` уже не `HasMedia`, поэтому старые строки убираются здесь —
 * сами они не уйдут никогда, а чистку в `storage:gc` мы сняли.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = DB::table('media')->where('model_type', 'App\\Mail\\Candidate')->get(['id', 'disk', 'conversions_disk']);
        foreach ($rows as $media) {
            foreach (array_unique(array_filter([$media->disk, $media->conversions_disk])) as $disk) {
                Storage::disk($disk)->deleteDirectory((string) $media->id);
            }
        }
        DB::table('media')->where('model_type', 'App\\Mail\\Candidate')->delete();
    }

    public function down(): void {}
};
