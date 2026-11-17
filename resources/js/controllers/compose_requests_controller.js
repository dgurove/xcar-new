import { Controller } from '@hotwired/stimulus';
import { confirmSheet } from '../confirm';

// Доп. запрос поставщику (17.11.2026, `Offers\ExtraRequest`): пилюли-галки пересобирают письмо — фрейм окна писем
// перечитывается с `pick[]` (город — `city`, «Другой» — полем). Текст уже правили руками — сначала «Пересобрать письмо?»,
// иначе правка пропала бы молча. Правка — ввод в самом редакторе (Trix при загрузке сам переписывает поле, по значению не понять).
export default class extends Controller {
    static values = { frame: String };

    connect() {
        this.editor = this.element.closest('form')?.querySelector('trix-editor');
        this.dirty = false;
        this.onEdit = () => { this.dirty = true; };
        ['keydown', 'paste', 'cut', 'drop'].forEach((t) => this.editor?.addEventListener(t, this.onEdit));
    }

    disconnect() {
        ['keydown', 'paste', 'cut', 'drop'].forEach((t) => this.editor?.removeEventListener(t, this.onEdit));
    }

    async go(e) {
        if (!this.dirty) return;
        e.preventDefault();
        const href = e.currentTarget.href;
        if (await this.ask()) this.open(href);
    }

    async city(e) {
        const value = e.currentTarget.value.trim();
        if (!value) return;
        const href = e.currentTarget.dataset.url.replace('__CITY__', encodeURIComponent(value));
        if (this.dirty && !(await this.ask())) return;
        this.open(href);
    }

    ask() {
        return confirmSheet('Пересобрать письмо?', { text: 'Текст, поправленный руками, заменится', label: 'Пересобрать' });
    }

    open(href) {
        const frame = document.getElementById(this.frameValue);
        if (frame) frame.src = href;
    }
}
