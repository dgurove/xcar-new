<?php

use App\Park\Vehicle;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Кадры ТС без стадии считались «из письма» — так и записываем; новые ручные кадры получают `storage` при загрузке. */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('media')->where('model_type', Vehicle::class)->where('collection_name', 'photos')
            ->whereRaw("coalesce(custom_properties->>'stage', '') = ''")
            ->update(['custom_properties' => DB::raw("coalesce(custom_properties, '{}'::json)::jsonb || '{\"stage\": \"mail\"}'::jsonb")]);
    }

    public function down(): void {}
};
