{{-- Главная CRM — космос (04.11.2026, владелец): фон будущей схемы. Звёзды рисует WebGL (`resources/js/space/sky.js`),
     сдвиг и масштаб — камера (`space/camera.js`), обоих ведёт `space_controller`. Слой `.space-world` двигается той же
     камерой, что звёзды на глубине 1: в нём будет схема, пока — блок «Тест» в начале мира. --}}
<x-ui.shell title="Главная" :heading="false" :back="false" stage>
    <div class="space" data-controller="space" data-no-pull>
        <canvas class="space-sky" data-space-target="sky" aria-hidden="true"></canvas>
        <div class="space-world" data-space-target="world">
            <div class="space-node" style="--x: 0px; --y: 0px">
                <div class="space-float">Тест</div>
            </div>
        </div>
        <button type="button" class="space-home" data-space-target="home" data-action="space#home" aria-label="К схеме" title="К схеме" hidden>
            <x-ui.icon name="locate" class="size-5"/>
        </button>
    </div>
</x-ui.shell>
