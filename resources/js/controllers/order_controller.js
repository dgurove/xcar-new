import { Controller } from '@hotwired/stimulus';
import { loadSortable } from '../lib/sortable';
import { post } from '../lib/http';

// Список, который переставляют за ручку: после перестановки порядок id уходит на сервер.
export default class extends Controller {
    static values = { url: String };

    async connect() {
        const Sortable = await loadSortable();
        if (!this.element.isConnected) return;
        this.sortable = Sortable.create(this.element, { animation: 150, handle: '[data-handle]', delay: 150, delayOnTouchOnly: true, onEnd: () => this.save() });
    }

    disconnect() { this.sortable?.destroy(); }

    async save() {
        const order = [...this.element.querySelectorAll('[data-id]')].map((el) => el.dataset.id);
        // Обрыв тоже «не сохранился»: раньше он пропадал молча
        if (!(await post(this.urlValue, { order }, { quiet: true }))) window.toast?.('Порядок не сохранился', 'danger');
    }
}
