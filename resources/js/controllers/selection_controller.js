import { Controller } from '@hotwired/stimulus';

// Режим выбора в ленте менеджера: кружки на карточках, полоса «Показать… (N)»,
// шторка с фреймом, адрес которого собирается из отмеченных. Нажатие по карточке
// в режиме — переключение, не переход; выбор переживает догрузку страниц. Кнопка «Показать покупателям»
// у одной карточки или в окошке строки — ссылка [data-show-offer] на страницу предложения: здесь она открывает
// ту же шторку для одного предложения, а там, где этой ленты нет (избранное), просто ведёт на страницу.
export default class extends Controller {
    static targets = ['list', 'bar', 'count', 'frame', 'toggle'];
    static values = { url: String, back: String };

    connect() {
        this.onStart = (e) => this.start(e.detail?.card);
        addEventListener('selection:start', this.onStart);
        this.onShowOne = (e) => {
            const link = e.target.closest('[data-show-offer]');
            if (!link || this.active || e.metaKey || e.ctrlKey) return;
            e.preventDefault();
            this.open([link.dataset.showOffer]);
        };
        document.addEventListener('click', this.onShowOne);
    }

    disconnect() {
        removeEventListener('selection:start', this.onStart);
        document.removeEventListener('click', this.onShowOne);
    }

    get active() { return this.hasListTarget && this.listTarget.hasAttribute('data-selecting'); }

    boxes() { return [...this.listTarget.querySelectorAll('.card-check input')]; }

    start(card) {
        this.listTarget.setAttribute('data-selecting', '');
        if (card) { const b = card.querySelector('.card-check input'); if (b) b.checked = true; }
        this.update();
    }

    toggle() { this.active ? this.cancel() : this.start(); }

    cancel() {
        this.boxes().forEach((b) => { b.checked = false; });
        this.listTarget.removeAttribute('data-selecting');
        this.update();
    }

    // В режиме выбора клик по карточке — галка; сам чекбокс работает как есть.
    tap(event) {
        if (!this.active) return;
        const card = event.target.closest('.card');
        if (!card || event.target.closest('.card-check')) return;
        event.preventDefault();
        event.stopPropagation();
        const box = card.querySelector('.card-check input');
        if (box) { box.checked = !box.checked; this.update(); }
    }

    change() { this.update(); }

    update() {
        const n = this.boxes().filter((b) => b.checked).length;
        if (this.hasBarTarget) this.barTarget.hidden = !this.active;
        if (this.hasCountTarget) this.countTarget.textContent = n ? `(${n})` : '';
        if (this.hasToggleTarget) this.toggleTarget.setAttribute('aria-pressed', this.active ? 'true' : 'false');
        this.barTarget?.querySelector('[data-selection-submit]')?.toggleAttribute('disabled', n === 0);
    }

    // Открыть шторку «Показать…»: фрейм грузит форму под отмеченные предложения.
    show() {
        const ids = this.boxes().filter((b) => b.checked).map((b) => b.value);
        if (ids.length) this.open(ids);
    }

    open(ids) {
        const params = new URLSearchParams();
        ids.forEach((id) => params.append('offers[]', id));
        params.set('back', this.backValue || location.pathname + location.search);
        this.frameTarget.src = `${this.urlValue}?${params}`;
        window.dispatchEvent(new CustomEvent('selection:open'));
    }
}
