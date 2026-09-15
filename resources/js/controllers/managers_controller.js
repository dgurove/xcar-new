import { Controller } from '@hotwired/stimulus';

// Круг менеджеров у оффера: чип «Все» гасит остальные; отметил кого-то — «Все» снимается.
export default class extends Controller {
    static targets = ['all', 'chip', 'limited'];

    toggleAll() {
        const all = this.allTarget.checked;
        this.limitedTarget.value = all ? 0 : 1;
        this.chipTargets.forEach((c) => { c.disabled = all; if (all) c.checked = false; });
    }

    pick() {
        if (this.chipTargets.some((c) => c.checked)) {
            this.allTarget.checked = false;
            this.limitedTarget.value = 1;
            this.chipTargets.forEach((c) => { c.disabled = false; });
        }
    }
}
