import { Controller } from '@hotwired/stimulus';

// Свободные места выбранной площадки — подсказкой в поле «Место»; у парковки со схемой под полями — сама схема
// (фрейм `plan`, `/yards/{id}/plan?pick=`): нажатие на свободное место пишет его в поле (`yard_plan_controller`, pick).
export default class extends Controller {
    static targets = ['yard', 'list', 'plan'];
    static values = { map: Object };

    connect() { this.sync(); }

    sync() {
        const yard = this.yardTarget.value;
        const spots = this.mapValue[yard] || [];
        this.listTarget.replaceChildren(...spots.map((s) => Object.assign(document.createElement('option'), { value: s })));
        if (!this.hasPlanTarget) return;
        const frame = this.planTarget;
        const src = yard ? `/yards/${yard}/plan?${new URLSearchParams({ pick: frame.dataset.pick || '0', at: frame.dataset.at || '' })}` : '';
        if (!src) { frame.removeAttribute('src'); frame.replaceChildren(); return; }
        if (frame.getAttribute('src') !== src) frame.setAttribute('src', src);
    }
}
