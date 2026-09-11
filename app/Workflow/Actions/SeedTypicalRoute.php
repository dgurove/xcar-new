<?php

namespace App\Workflow\Actions;

use App\Workflow\Track;
use App\Workflow\Workflow;
use Illuminate\Support\Facades\DB;

/** Типовой маршрут в пустой маршрут: от него проще править, чем собирать с нуля. */
final class SeedTypicalRoute
{
    public function __construct(private RevalidateWorkflow $revalidate) {}

    public function __invoke(Workflow $workflow): void
    {
        if ($workflow->stages()->exists()) {
            return;
        }
        DB::transaction(function () use ($workflow) {
            $workflow->track === Track::Sale ? $this->sale($workflow) : $this->service($workflow);
            ($this->revalidate)($workflow, true);
        });
    }

    private function sale(Workflow $workflow): void
    {
        $s = $this->build($workflow, [
            'Подготовка' => [
                ['name' => 'Черновик', 'waits_for' => 'us', 'offer_state' => 'draft'],
            ],
            'Приём предложений' => [
                ['name' => 'Приём ставок', 'waits_for' => 'supplier', 'offer_state' => 'open', 'deadline_source' => 'bids_close'],
                ['name' => 'Выбираем ставку', 'waits_for' => 'us', 'offer_state' => 'closed', 'limit_minutes' => 1440],
            ],
            'Согласование' => [
                ['name' => 'Согласуем со страховой', 'waits_for' => 'supplier', 'offer_state' => 'sold'],
            ],
            'Подтверждение' => [
                ['name' => 'Ждём подтверждения', 'waits_for' => 'manager', 'limit_minutes' => 1440,
                    'ask_title' => 'Подтвердите сделку', 'ask_text' => 'Страховая согласовала вашу цену. Подтвердите, что готовы выкупить машину.'],
            ],
            'Оплата' => [
                ['name' => 'Ждём оплату', 'waits_for' => 'manager', 'limit_minutes' => 4320, 'asks' => 'document',
                    'ask_title' => 'Оплатите счёт', 'ask_text' => 'Приложите платёжное поручение.', 'staff_fields' => [['label' => 'Номер счёта'], ['label' => 'Сумма']]],
                ['name' => 'Проверяем оплату', 'waits_for' => 'us', 'limit_minutes' => 480],
            ],
            'Выдача' => [
                ['name' => 'Готовим выдачу', 'waits_for' => 'us', 'limit_minutes' => 2880],
                ['name' => 'Выдана', 'waits_for' => 'nobody', 'offer_state' => 'delivered'],
            ],
            'Сорвалось' => [
                ['name' => 'Снята', 'waits_for' => 'nobody', 'offer_state' => 'cancelled'],
            ],
        ]);

        $this->exits($s['Черновик'], [['Опубликовать', 'staff', 'Приём ставок'], ['Снять', 'staff', 'Снята']]);
        $this->exits($s['Приём ставок'], [['Приём закрыт', 'timer', 'Выбираем ставку'], ['Закрыть приём', 'staff', 'Выбираем ставку'], ['Ставка принята', 'staff', 'Согласуем со страховой'], ['Снять', 'staff', 'Снята']]);
        $this->exits($s['Выбираем ставку'], [['Ставка принята', 'staff', 'Согласуем со страховой'], ['Открыть приём снова', 'staff', 'Приём ставок'], ['Снять', 'staff', 'Снята']]);
        $this->exits($s['Согласуем со страховой'], [['Согласовано', 'staff', 'Ждём подтверждения'], ['Отказ страховой', 'staff', 'Приём ставок']]);
        $this->exits($s['Ждём подтверждения'], [['Подтверждаю', 'manager', 'Ждём оплату'], ['Отказываюсь', 'manager', 'Выбираем ставку']]);
        $this->exits($s['Ждём оплату'], [['Оплатил', 'manager', 'Проверяем оплату'], ['Отказываюсь', 'manager', 'Выбираем ставку']]);
        $this->exits($s['Проверяем оплату'], [['Деньги пришли', 'staff', 'Готовим выдачу'], ['Денег нет', 'staff', 'Ждём оплату']]);
        $this->exits($s['Готовим выдачу'], [['Выдана', 'staff', 'Выдана']]);
    }

    private function service(Workflow $workflow): void
    {
        $s = $this->build($workflow, [
            'У владельца' => [
                ['name' => 'Договариваемся о вывозе', 'waits_for' => 'us', 'car_place' => 'owner', 'limit_minutes' => 2880],
            ],
            'В пути' => [
                ['name' => 'Эвакуатор в пути', 'waits_for' => 'supplier', 'car_place' => 'moving', 'limit_minutes' => 1440],
            ],
            'На площадке' => [
                ['name' => 'Стоит у нас', 'waits_for' => 'nobody', 'car_place' => 'ours'],
            ],
        ]);
        $this->exits($s['Договариваемся о вывозе'], [['Забрали', 'staff', 'Эвакуатор в пути']]);
        $this->exits($s['Эвакуатор в пути'], [['Приехала', 'staff', 'Стоит у нас']]);
    }

    /** @return array<string, \App\Workflow\Stage> */
    private function build(Workflow $workflow, array $blocks): array
    {
        $stages = [];
        foreach (array_values($blocks) as $bi => $rows) {
            $name = array_keys($blocks)[$bi];
            $block = $workflow->blocks()->create(['name' => $name, 'position' => $bi]);
            foreach ($rows as $si => $row) {
                $row['staff_fields'] = \App\Workflow\Stage::keyFields($row['staff_fields'] ?? []);
                $stages[$row['name']] = $block->stages()->create($row + ['workflow_id' => $workflow->id, 'position' => $si]);
            }
        }

        return $stages;
    }

    private function exits($stage, array $rows): void
    {
        foreach ($rows as $i => [$label, $actor, $to]) {
            $stage->exits()->create(['label' => $label, 'actor' => $actor, 'to_stage_id' => $stage->workflow->stages()->where('workflow_stages.name', $to)->value('workflow_stages.id'), 'position' => $i]);
        }
    }
}
