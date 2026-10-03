<?php

use App\Mail\Scope;
use App\Mail\Template;
use App\Workflow\Actions\RevalidateWorkflow;
use App\Workflow\Block;
use App\Workflow\Outcome;
use App\Workflow\Stage;
use App\Workflow\Track;
use App\Workflow\Workflow;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Гаражная ветка в боевых маршрутах продажи (03.10.2026): маршруты — данные, на занятые `vendors:refill` не ходит,
 * поэтому ветка вставляется на месте, по именам этапов, как в заготовках (`Route::garageSegment`, снимок ниже):
 * — «Согласие менеджера» (Альфа, Каркаде): «Забираю в гараж» менеджера, «Покупаю» — ветка покупателя;
 * — Совкомбанк (согласия нет): «Поставщик согласовал, в гараж» сотрудника, прежние согласия — ветка покупателя;
 * — Т-Страхование — без ветки: машину забирают у владельца, платит менеджер.
 * Каркаде писем не пишет — ветка начинается с оплаты поставщику. Чего не нашли — в лог, маршрут не трогаем.
 * Состав ветки — снимком на 03.10.2026, а не из заготовок: правка заготовки потом не должна менять миграцию.
 */
return new class extends Migration
{
    private const DAY = 1440;

    private const BLOCKS = [
        'agreement_garage' => ['name' => 'Оформляем на нас', 'text' => 'Машина уходит к Вам в гараж: подтверждаем покупку поставщику, оплачиваем и оформляем документы на нас.'],
        'handover_garage' => ['name' => 'Забрать в гараж', 'text' => 'Автомобиль оплачен и оформлен. Заберите его и отметьте, что забрали.'],
    ];

    private function rows(bool $confirm): array
    {
        $rows = [
            'garage_confirmed' => [
                'name' => 'Подтвердили покупку поставщику — в гараж', 'block' => 'agreement_garage', 'waits_for' => 'supplier', 'limit_minutes' => self::DAY, 'letter' => true,
                'exits' => [['Ответ поставщика получен', 'staff', 'garage_payment']],
            ],
            'garage_payment' => [
                'name' => 'Оплата поставщику — в гараж', 'block' => 'agreement_garage', 'waits_for' => 'us', 'limit_minutes' => 2 * self::DAY,
                'staff_fields' => [['label' => 'Дата оплаты'], ['label' => 'Номер платёжки']],
                'exits' => [['Поставщику оплачено', 'staff', 'garage_papers']],
            ],
            'garage_papers' => [
                'name' => 'Документы на нас — в гараж', 'block' => 'agreement_garage', 'waits_for' => 'supplier', 'limit_minutes' => 5 * self::DAY,
                'exits' => [['Документы получены', 'staff', 'garage_pickup']],
            ],
            'garage_pickup' => [
                'name' => 'Выдача автомобиля — в гараж', 'block' => 'handover_garage', 'waits_for' => 'manager', 'limit_minutes' => 5 * self::DAY,
                'ask_title' => 'Заберите автомобиль', 'ask_text' => 'Автомобиль оплачен и оформлен на нас. Заберите его и отметьте, что забрали.',
                'staff_fields' => [['label' => 'Адрес', 'type' => 'textarea'], ['label' => 'Контакт'], ['label' => 'Дата выдачи']],
                'exits' => [['Автомобиль забрал', 'manager', 'closed_won'], ['Автомобиль передан', 'staff', 'closed_won']],
            ],
        ];

        return $confirm ? $rows : array_diff_key($rows, ['garage_confirmed' => true]);
    }

    public function up(): void
    {
        foreach (Workflow::where('track', Track::Sale)->get() as $workflow) {
            $stages = Stage::where('workflow_id', $workflow->id)->with('exits')->get();
            $named = fn (string $name) => $stages->firstWhere('name', $name);
            $won = $named('Сделка закрыта');
            if (! $won || $stages->contains(fn ($s) => str_ends_with($s->name, ' — в гараж'))) {
                continue;
            }
            if ($named('Контакты владельца переданы менеджеру')) {
                continue;
            }
            $confirm = $named('Согласие менеджера');
            $claimed = $named('Уведомили поставщика о покупке');
            $sovcom = $claimed && $claimed->exits->contains(fn ($e) => $e->label === 'Поставщик согласовал, на себя');
            if (! $confirm && ! $sovcom) {
                Log::warning("Гаражная ветка: маршрут {$workflow->id} — не нашли, куда вставить");

                continue;
            }
            $stock = (bool) $named('Выдача на площадке поставщика');

            DB::transaction(function () use ($workflow, $won, $confirm, $claimed, $stock) {
                $rows = $this->rows(! $stock);
                // Блоки — перед «Сделка закрыта»: лестница не ведёт к блоку, стоящему раньше текущего.
                $at = $won->block->position;
                Block::where('workflow_id', $workflow->id)->where('position', '>=', $at)->increment('position', 2);
                $blocks = [];
                $i = 0;
                foreach (self::BLOCKS as $key => $block) {
                    $blocks[$key] = Block::create($block + ['workflow_id' => $workflow->id, 'position' => $at + $i++]);
                }
                $letter = Template::where('scope', Scope::Offers)->where('name', 'Поставщику: подтверждение покупки')->value('id');

                $created = [];
                $positions = [];
                foreach ($rows as $key => $row) {
                    $created[$key] = Stage::create([
                        'workflow_id' => $workflow->id, 'block_id' => $blocks[$row['block']]->id,
                        'position' => $positions[$row['block']] = ($positions[$row['block']] ?? -1) + 1,
                        'name' => $row['name'], 'waits_for' => $row['waits_for'], 'limit_minutes' => $row['limit_minutes'] ?? null,
                        'deadline_source' => 'own', 'asks' => 'nothing', 'ask_title' => $row['ask_title'] ?? null, 'ask_text' => $row['ask_text'] ?? null,
                        'fields' => [], 'staff_fields' => Stage::keyFields($row['staff_fields'] ?? []),
                        'template_id' => ! empty($row['letter']) ? $letter : null,
                    ]);
                }
                foreach ($rows as $key => $row) {
                    foreach ($row['exits'] as $p => [$label, $actor, $to]) {
                        $created[$key]->exits()->create(['label' => $label, 'actor' => $actor, 'to_stage_id' => $to === 'closed_won' ? $won->id : $created[$to]->id, 'position' => $p]);
                    }
                }
                $entry = reset($created);

                if ($confirm) {
                    $confirm->exits()->where('label', 'Покупаю')->update(['branch' => Outcome::BUYER]);
                    $confirm->exits()->where('label', 'Отказываюсь')->increment('position');
                    $confirm->exits()->create(['label' => 'Забираю в гараж', 'actor' => 'manager', 'to_stage_id' => $entry->id, 'position' => 1, 'branch' => Outcome::GARAGE]);
                } else {
                    $claimed->exits()->where('label', 'like', 'Поставщик согласовал%')->update(['branch' => Outcome::BUYER]);
                    $claimed->exits()->create(['label' => 'Поставщик согласовал, в гараж', 'actor' => 'staff', 'to_stage_id' => $entry->id,
                        'position' => $claimed->exits()->max('position') + 1, 'branch' => Outcome::GARAGE]);
                }
                // Включённый проверяем с `true`: дыра — ошибка и откат, а не молча погасший боевой маршрут.
                app(RevalidateWorkflow::class)($workflow->fresh(), $workflow->is_active ?: null);
            });
        }
    }

    public function down(): void {}
};
