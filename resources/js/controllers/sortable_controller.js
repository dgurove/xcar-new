import { Controller } from '@hotwired/stimulus';
import { loadSortable } from '../lib/sortable';

// Редактор маршрута: блоки переставляются за ручку, этапы — между блоками.
// После каждой перестановки порядок целиком уходит на сервер.
export default class extends Controller {
    static targets = ['blocks', 'stages'];
    static values = { url: String };

    async connect() {
        this.instances = [];
        const Sortable = await loadSortable();
        if (!this.element.isConnected) return;
        if (this.hasBlocksTarget) {
            this.instances.push(Sortable.create(this.blocksTarget, { animation: 150, handle: '[data-handle]', onEnd: () => this.save() }));
        }
        for (const list of this.stagesTargets) {
            this.instances.push(Sortable.create(list, { group: 'stages', animation: 150, delay: 150, delayOnTouchOnly: true, onEnd: () => this.save() }));
        }
    }

    disconnect() {
        this.instances.forEach((s) => s.destroy());
    }

    async save() {
        const blocks = [...this.element.querySelectorAll('[data-block-id]')].map((b) => ({
            id: b.dataset.blockId,
            stages: [...b.querySelectorAll('[data-stage-id]')].map((s) => s.dataset.stageId),
        }));
        const r = await fetch(this.urlValue, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
            body: JSON.stringify({ blocks }),
        });
        if (!r.ok) window.toast?.('Порядок не сохранился', 'danger');
    }
}
