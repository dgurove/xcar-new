import { Controller } from '@hotwired/stimulus';

// Небольшое меню под кнопкой (вид списка): popover в top layer — закрывается
// кликом мимо и Esc сам; место под кнопкой по её правому краю считаем от
// той кнопки, что нажали (без выбора вида их две, видна одна): под её левым
// краем, а у правого края экрана — по правому; у нижнего края — над кнопкой.
export default class extends Controller {
    static targets = ['list'];

    toggle(event) {
        const list = this.listTarget;
        if (list.matches(':popover-open')) { list.hidePopover(); return; }
        const r = event.currentTarget.getBoundingClientRect(), w = document.documentElement.clientWidth, h = innerHeight;
        list.style.top = `${r.bottom + 6}px`;
        list.showPopover();
        // У нижнего края экрана (кнопка в окошке строки) — над кнопкой.
        if (r.bottom + 6 + list.offsetHeight > h - 8) list.style.top = `${Math.max(8, r.top - 6 - list.offsetHeight)}px`;
        // Под кнопкой от её левого края; у правого края экрана — по правому.
        const fits = r.left + list.offsetWidth <= w - 8;
        list.style.left = fits ? `${r.left}px` : '';
        list.style.right = fits ? '' : `${Math.max(8, w - r.right)}px`;
        list.querySelector('[aria-current]')?.focus({ preventScroll: true });
    }

    close() {
        if (this.listTarget.matches(':popover-open')) this.listTarget.hidePopover();
    }

    disconnect() { this.close(); }
}
