{{-- Главная CRM — космос с конвейером (04.11.2026, владелец): весь путь машины от поступления до выдачи, у этапа — кто его
     держит и сколько у него висит (`App\Space\Conveyor`). Звёзды рисует WebGL (`resources/js/space/sky.js`) поверх фона
     темы `.space-back`, там же поток по линиям конвейера; сдвиг и масштаб — камера (`space/camera.js`), всех ведёт
     `space_controller`. Слой `.space-world` двигается той же камерой, что звёзды на глубине 1: в нём линии (SVG) и карточки
     этапов. Числа живые: событие сотрудникам — кусок `#conveyor` перечитывается (`/space/conveyor`). --}}
<x-ui.shell title="Главная" :heading="false" :back="false" stage>
    <div class="space" data-controller="space" data-space-url-value="/space/conveyor" data-space-links-value="{{ json_encode(\App\Space\Conveyor::LINKS) }}" data-no-pull>
        <canvas class="space-back" data-space-target="back" aria-hidden="true"></canvas>
        <canvas class="space-sky" data-space-target="sky" aria-hidden="true"></canvas>
        <div class="space-world" data-space-target="world">
            <svg class="space-links" data-space-target="links" aria-hidden="true"></svg>
            <div id="conveyor" class="contents">
                @include('admin.space.conveyor')
            </div>
        </div>
        <button type="button" class="space-home" data-space-target="home" data-action="space#home" aria-label="К схеме" title="К схеме" hidden>
            <x-ui.icon name="locate" class="size-5"/>
        </button>
    </div>
</x-ui.shell>
