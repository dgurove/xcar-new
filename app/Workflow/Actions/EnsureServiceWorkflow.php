<?php

namespace App\Workflow\Actions;

use App\Vendors\Vendor;
use App\Workflow\Preset;
use App\Workflow\Track;
use App\Workflow\Workflow;

/**
 * Маршрут вывоза у вендора, которому его не заводили (Каркаде и прочие): гаражную машину везут всегда (06.10.2026,
 * «Доставки» в гараже больше нет — это вывоз). Заготовка «Вывоз», но **без запуска с каждым предложением**: иначе
 * каждый новый оффер вендора получил бы вывоз и заявку на эвакуацию. Выключенный руками маршрут не включаем.
 */
final class EnsureServiceWorkflow
{
    public function __construct(private ApplyPreset $apply) {}

    public function __invoke(Vendor $vendor): ?Workflow
    {
        $workflow = $vendor->workflow(Track::Service);
        if ($workflow?->stages()->exists()) {
            return $workflow->is_active ? $workflow : null;
        }
        $workflow ??= $vendor->workflows()->create(['track' => Track::Service, 'auto_start' => false, 'is_active' => false]);
        $workflow->update(['auto_start' => false]);
        ($this->apply)($workflow, Preset::Pickup);
        $vendor->unsetRelation('workflows');

        return $workflow->fresh();
    }
}
