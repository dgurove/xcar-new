<?php

use App\Mail\Thread;
use App\Park\Actions\UnwindVehicle;
use App\Park\Vehicle;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use App\Workflow\Actions\RevalidateWorkflow;
use App\Workflow\Block;
use App\Workflow\Outcome;
use App\Workflow\Stage;
use App\Workflow\Track;
use App\Workflow\Workflow;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Парковка — только письма storage@ (владелец 08.10.2026: «в park.xcar должно попадать только то, что на storage@»).
 * Вывоз CRM «На парковку» заводил там ТС с заявкой на эвакуацию (`RequestTowFromOffer`, у Совкомбанка — с каждым
 * предложением). Здесь:
 * - ТС, заведённые вывозом («Вывоз по предложению № …», без письма), стираются как «Заведена по ошибке» (`UnwindVehicle`):
 *   заявки и события — каскадом, файлы предложения возвращаются к нему (`Sale::release`). С приёмом, бумагами, деньгами
 *   или письмами парковки — не трогаем, в лог;
 * - вендор без ТС на парковке уходит из её «Вендоров», адреса заявок у вендоров вне парковки снимаются;
 * - выбор шаблона разбора заявок (`park_parser`) снят: заявки storage@ разбирает общий;
 * - из маршрутов вывоза — ветка «Автомобиль вывезен, осмотр проведён» → «Перегон в город присутствия» → «Автомобиль на
 *   площадке»; «куда» у предложений — только к менеджеру или к нам.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->unwindTows();
        $this->dropYardBranch();
        DB::table('offers')->where('evacuation_to', 'yard')->update(['evacuation_to' => 'ours']);
        Schema::table('vendors', fn (Blueprint $t) => $t->dropColumn('park_parser'));
    }

    private function unwindTows(): void
    {
        $ids = DB::table('park_requests')->where('type', 'tow')->whereNull('letter_id')
            ->where('note', 'like', 'Вывоз по предложению №%')->distinct()->pluck('vehicle_id');
        $by = User::withRole(Role::Admin)->orderBy('id')->first();
        $vendors = [];
        foreach (Vehicle::whereIn('id', $ids)->get() as $vehicle) {
            if (! $by || ! UnwindVehicle::allowed($vehicle) || Thread::where('vehicle_id', $vehicle->id)->park()->exists()) {
                Log::warning("Парковка без вывоза CRM: ТС {$vehicle->id} не стёрта — есть приём, бумаги, деньги или письма парковки");

                continue;
            }
            $vendors[] = $vehicle->vendor_id;
            app(UnwindVehicle::class)($vehicle, $by);
        }
        Vendor::whereIn('id', array_filter(array_unique($vendors)))->whereDoesntHave('vehicles')->update(['on_park' => false]);
        Vendor::where('on_park', false)->update(['park_senders' => []]);
    }

    private function dropYardBranch(): void
    {
        foreach (Workflow::where('track', Track::Service)->get() as $workflow) {
            $exits = Outcome::whereIn('stage_id', Stage::where('workflow_id', $workflow->id)->select('id'))->where('branch', 'yard')->get();
            if ($exits->isEmpty()) {
                continue;
            }
            // Этапы ветки: от её выхода вперёд, пока на этап не ведёт ничего, кроме самой ветки.
            $drop = [];
            $next = $exits->pluck('to_stage_id')->filter()->all();
            while ($id = array_shift($next)) {
                if (in_array($id, $drop, true)
                    || Outcome::where('to_stage_id', $id)->whereNotIn('stage_id', $drop)->whereNotIn('id', $exits->pluck('id'))->exists()) {
                    continue;
                }
                $drop[] = $id;
                array_push($next, ...Outcome::where('stage_id', $id)->whereNotNull('to_stage_id')->pluck('to_stage_id')->all());
            }
            if (DB::table('offer_positions')->whereIn('stage_id', $drop)->exists()) {
                Log::warning("Парковка без вывоза CRM: маршрут {$workflow->id} — на ветке парковки стоят предложения, не трогаем");

                continue;
            }
            DB::transaction(function () use ($workflow, $exits, $drop) {
                $blocks = Stage::whereIn('id', $drop)->pluck('block_id')->filter()->unique();
                Outcome::whereIn('id', $exits->pluck('id'))->delete();
                Stage::whereIn('id', $drop)->delete();
                Block::whereIn('id', $blocks)->whereNotIn('id', Stage::whereNotNull('block_id')->select('block_id'))->delete();
                app(RevalidateWorkflow::class)($workflow->fresh(), $workflow->is_active ?: null);
            });
        }
    }

    public function down(): void {}
};
