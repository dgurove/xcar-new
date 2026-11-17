import { Controller } from '@hotwired/stimulus';
import { loadSortable } from '../lib/sortable';
import { post } from '../lib/http';

// Порядок строк списка перетаскиванием за ручку `[data-handle]` (17.11.2026, доп. запросы в «Шаблонах»: порядок галок — порядок
// фраз письма). После перестановки номера строк `[data-id]` целиком уходят на сервер.
export default class extends Controller {
    static values = { url: String };

    async connect() {
        const Sortable = await loadSortable();
        if (!this.element.isConnected) return;
        this.sortable = Sortable.create(this.element, { animation: 150, handle: '[data-handle]', draggable: '[data-id]', onEnd: () => this.save() });
    }

    disconnect() {
        this.sortable?.destroy();
    }

    async save() {
        const ids = [...this.element.querySelectorAll('[data-id]')].map((row) => row.dataset.id);
        if (!(await post(this.urlValue, { ids }, { quiet: true }))) window.toast?.('Порядок не сохранился', 'danger');
    }
}
