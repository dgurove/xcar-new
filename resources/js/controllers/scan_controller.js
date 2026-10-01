import { Controller } from '@hotwired/stimulus';

// «✨ Распознать» (park/requests/scan). Файлы: число отмеченных на кнопке, больше max — кнопка молчит; «Выбрать все»
// у фото. Чтение (busy): фрейм перечитывается по событию live:scan своей цепочки и при возврате на вкладку — без
// опроса по таймеру. Поля: выбранный вариант встаёт в строку и сворачивает её.
export default class extends Controller {
    static targets = ['count', 'submit', 'all'];
    static values = { busy: Boolean, candidate: Number, max: Number, url: String };

    connect() {
        this.count();
        if (!this.busyValue) return;
        this.onScan = (e) => { if (Number(e.detail?.candidate) === this.candidateValue) this.reload(); };
        this.onVisible = () => { if (document.visibilityState === 'visible') this.reload(); };
        document.addEventListener('live:scan', this.onScan);
        document.addEventListener('visibilitychange', this.onVisible);
    }

    disconnect() {
        document.removeEventListener('live:scan', this.onScan);
        document.removeEventListener('visibilitychange', this.onVisible);
    }

    count() {
        if (!this.hasSubmitTarget) return;
        const n = this.boxes.filter((b) => b.checked).length;
        const over = this.maxValue && n > this.maxValue;
        this.countTarget.textContent = over ? `${n} из ${this.maxValue}` : n;
        this.submitTarget.disabled = n === 0 || over;
        if (this.hasAllTarget) this.allTarget.textContent = this.photos.every((b) => b.checked) ? 'Снять все' : 'Выбрать все';
    }

    all() {
        const on = !this.photos.every((b) => b.checked);
        this.photos.forEach((b) => { b.checked = on; });
        this.count();
    }

    pick(event) {
        const details = event.target.closest('details');
        const shown = details?.querySelector('[data-scan-shown]');
        const option = event.target.closest('label')?.querySelector('[data-scan-option]');
        if (shown && option) shown.innerHTML = option.innerHTML;
        if (details) details.open = false;
    }

    // Адрес шага с отмеченными файлами: после отправки формы src фрейма — ещё адрес открытия окна.
    reload() {
        const frame = this.element.closest('turbo-frame');
        const url = new URL(this.urlValue, location.href).href;
        if (frame.src === url) frame.reload();
        else frame.src = url;
    }

    get boxes() { return [...this.element.querySelectorAll('input[name="ids[]"][type=checkbox]')]; }
    get photos() { return this.boxes.filter((b) => b.hasAttribute('data-photo')); }
}
