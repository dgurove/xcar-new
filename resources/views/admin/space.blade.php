{{-- Главная CRM — космос с конвейером (04.11.2026, владелец; этапы по людям — 05.11.2026): этапы слева направо, у этапа —
     кто делает и подэтапы с числом задач и горящих, нажатие — их список (`App\Space\Conveyor`). Звёзды рисует WebGL
     (`resources/js/space/sky.js`) поверх фона темы `.space-back`; сдвиг и масштаб — камера (`space/camera.js`), всех ведёт
     `space_controller`. Слой `.space-world` двигается той же камерой, что звёзды на глубине 1. Числа живые: событие
     сотрудникам — кусок `#conveyor` перечитывается морфом (`/space/conveyor`). --}}
<x-ui.shell title="Главная" :heading="false" :back="false" stage>
    <div class="space" data-controller="space" data-space-url-value="/space/conveyor" data-no-pull>
        <canvas class="space-back" data-space-target="back" aria-hidden="true"></canvas>
        <canvas class="space-sky" data-space-target="sky" aria-hidden="true"></canvas>
        <div class="space-world" data-space-target="world">
            <div id="conveyor" class="contents" data-space-target="conveyor">
                @include('admin.space.conveyor')
            </div>
        </div>
        <button type="button" class="space-home" data-space-target="home" data-action="space#home" aria-label="К схеме" title="К схеме" hidden>
            <x-ui.icon name="locate" class="size-5"/>
        </button>
    </div>
</x-ui.shell>
