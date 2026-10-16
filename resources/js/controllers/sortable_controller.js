import { Controller } from '@hotwired/stimulus';
import { loadSortable } from '../lib/sortable';
import { post } from '../lib/http';

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
        // Обрыв тоже «не сохранился»: раньше он пропадал молча
        if (!(await post(this.urlValue, { blocks }, { quiet: true }))) window.toast?.('Порядок не сохранился', 'danger');
    }
}
