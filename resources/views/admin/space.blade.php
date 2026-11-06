{{-- Главная CRM — конвейер работы (04.11.2026, владелец; этапы по людям — 05.11.2026): этапы слева направо, у этапа —
     кто делает и подэтапы с числом задач и горящих, нажатие — их список (`App\Space\Conveyor`). Фон — фото паркинга со страницы
     входа по теме (`.parking-photo`), над ним знаки бренда на WebGL (`resources/js/space/marks.js`, 06.11.2026 вместо звёзд);
     сдвиг и масштаб — камера (`space/camera.js`), всех ведёт `space_controller`. Слой `.space-world` двигается той же камерой
     на глубине 1. Числа живые: событие сотрудникам — кусок `#conveyor` перечитывается морфом (`/space/conveyor`). Вбок — по
     этапам, мир в рамке доски; в углу — фильтр «Ответственный» (`admin/space/filter`). --}}
<x-ui.shell title="Главная" :heading="false" :back="false" stage>
    <div class="space" data-controller="space" data-space-url-value="/space/conveyor" data-space-me-value="user:{{ auth()->id() }}" data-no-pull>
        <div class="space-photo parking-photo" data-space-target="photo" aria-hidden="true"></div>
        <div class="space-shade" aria-hidden="true"></div>
        <canvas class="space-marks" data-space-target="marks" aria-hidden="true"></canvas>
        <div class="space-top" aria-hidden="true"></div>
        <div class="space-world" data-space-target="world">
            <div id="conveyor" class="contents" data-space-target="conveyor">
                @include('admin.space.conveyor')
            </div>
        </div>
        @include('admin.space.filter')
    </div>
</x-ui.shell>
