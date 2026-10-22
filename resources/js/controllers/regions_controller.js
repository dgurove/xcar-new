import { Controller } from '@hotwired/stimulus';

// «Регионы» (cabinet/regions): «Вся Россия» отмечает или снимает все; галка округа — все его регионы; галки округа и «Вся
// Россия» сами встают по регионам (часть — «не до конца»). Поиск прячет строки и пустые округа.
export default class extends Controller {
    static targets = ['all', 'district', 'group', 'row'];

    connect() {
        this.sync();
    }

    boxes(scope = this.element) {
        return [...scope.querySelectorAll('input[name="regions[]"]')];
    }

    all() {
        this.boxes().forEach((b) => { b.checked = this.allTarget.checked; });
        this.sync();
    }

    district(event) {
        const group = event.currentTarget.closest('[data-regions-target="group"]');
        this.boxes(group).forEach((b) => { b.checked = event.currentTarget.checked; });
        this.sync();
    }

    sync() {
        this.groupTargets.forEach((group, i) => this.mark(this.districtTargets[i], this.boxes(group)));
        this.mark(this.allTarget, this.boxes());
    }

    mark(box, list) {
        const on = list.filter((b) => b.checked).length;
        box.checked = on === list.length;
        box.indeterminate = on > 0 && on < list.length;
    }

    search(event) {
        const q = event.currentTarget.value.trim().toLowerCase();
        this.rowTargets.forEach((row) => { row.hidden = q !== '' && !row.dataset.name.includes(q); });
        this.groupTargets.forEach((group) => {
            group.hidden = q !== '' && !group.querySelector('[data-regions-target="row"]:not([hidden])');
        });
    }
}
