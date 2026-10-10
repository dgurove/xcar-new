<?php

use App\Workflow\Actions\EnterStage;
use App\Workflow\Actions\RevalidateWorkflow;
use App\Workflow\Position;
use App\Workflow\Stage;
use App\Workflow\Workflow;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Т-Страхование без согласия менеджера (10.10.2026, владелец: «один раз написали в Т-Страхование — передумать уже нельзя;
 * пришёл паспорт — менеджер берёт в работу без вопросов»). «Поставщик согласовал» ведёт сразу на «Контакты владельца
 * переданы менеджеру»; «Согласие менеджера» и «Подтвердили покупку поставщику» уходят. Маршрут — по признаку (этап
 * «Контакты владельца переданы менеджеру»), не по имени вендора. Кто стоит на убранных этапах — на контакты, не тихо:
 * менеджеру «Ваш ход» с контактом, счёт подбора встаёт сам.
 *
 * Заодно просьба согласия у тех, у кого оно осталось (Альфа, Каркаде): «Подтвердите, что берёте в работу».
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('workflow_stages')->where('ask_title', 'Возьмите сделку в работу')->update(['ask_title' => 'Подтвердите, что берёте в работу']);
        DB::table('requirements')->whereNull('done_at')->where('title', 'Возьмите сделку в работу')->update(['title' => 'Подтвердите, что берёте в работу']);

        $ids = Stage::where('name', 'Контакты владельца переданы менеджеру')->pluck('workflow_id')->unique();
        foreach (Workflow::whereIn('id', $ids)->get() as $workflow) {
            $stages = Stage::where('workflow_id', $workflow->id)->with('exits')->get()->keyBy('name')->toBase();
            $claimed = $stages['Уведомили поставщика о покупке'] ?? null;
            $contacts = $stages['Контакты владельца переданы менеджеру'];
            $doomed = $stages->only(['Согласие менеджера', 'Подтвердили покупку поставщику'])->values();
            if (! $claimed || $doomed->isEmpty()) {
                continue;
            }
            DB::transaction(function () use ($workflow, $claimed, $contacts, $doomed) {
                $gone = $doomed->pluck('id');
                $claimed->exits()->where('label', 'Поставщик согласовал')->update(['to_stage_id' => $contacts->id, 'branch' => null]);
                DB::table('workflow_exits')->whereIn('to_stage_id', $gone)->whereNotIn('stage_id', $gone)->update(['to_stage_id' => $contacts->id]);

                $enter = app(EnterStage::class);
                Position::whereIn('stage_id', $gone)->with('offer')->get()->each(fn (Position $p) => $enter($p->offer, $contacts));
                DB::table('requirements')->whereIn('stage_id', $gone)->update(['stage_id' => $contacts->id]);

                Stage::whereIn('id', $gone)->delete();
                app(RevalidateWorkflow::class)($workflow);
            });
        }
    }

    public function down(): void {}
};
