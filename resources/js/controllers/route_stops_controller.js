import { Controller } from '@hotwired/stimulus';
import { loadSortable } from '../lib/sortable';
import { post } from '../lib/http';

// Стоянки маршрута вывоза перетаскиванием за «≡», как остановки в Google Картах (20.10.2026): тянутся только те, до которых
// машину не довезли (`data-stop` с ручкой), отпустил — порядок всех стоянок уходит на сервер, карточка перечитывается
// (точки отрезков сменились). Не вышло — порядок возвращается.
export default class extends Controller {
    static values = { url: String };

    async connect() {
        const Sortable = await loadSortable();
        if (!this.element.isConnected) return;
        this.sortable = Sortable.create(this.element, {
            animation: 150, handle: '[data-handle]', draggable: '[data-stop]', filter: '[data-fixed]', delay: 150, delayOnTouchOnly: true,
            onMove: (e) => !e.related.hasAttribute('data-fixed'),
            onEnd: (e) => (e.oldIndex !== e.newIndex ? this.save() : null),
        });
    }

    disconnect() { this.sortable?.destroy(); }

    async save() {
        const order = [...this.element.querySelectorAll('[data-stop]')].map((el) => el.dataset.stop);
        const ok = await post(this.urlValue, { order }, { quiet: true });
        if (!ok) window.toast?.('Порядок не сохранился', 'danger');
        // Карточка — во фрейме строки (`src` есть — перечитать его), иначе — страница целиком.
        const frame = this.element.closest('turbo-frame');
        if (frame?.src) frame.reload();
        else window.Turbo?.visit(location.href, { action: 'replace' });
    }
}
