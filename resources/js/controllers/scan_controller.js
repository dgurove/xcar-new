import { Controller } from '@hotwired/stimulus';

// «✨ Распознать» (park/requests/scan). Фрейм обновляется морфом — контроллер не переподключается, поэтому всё
// держится на колбэках Stimulus: появилась кнопка шага — пересчитать её, поменялся busy — включить или снять
// ожидание. Файлы: число отмеченных на кнопке, больше max — кнопка молчит; «Выбрать все» у фото. Чтение (busy):
// фрейм перечитывается по событию live:scan своего предмета (цепочка c:…, ТС v:…) и при возврате на вкладку — без
// опроса по таймеру. Поля: «Подставить N» — сколько полей изменится; ничего — кнопка молчит.
export default class extends Controller {
    static targets = ['count', 'submit', 'all', 'apply', 'changes'];
    static values = { busy: Boolean, subject: String, max: Number, url: String };

    initialize() {
        this.onScan = (e) => { if (e.detail?.subject === this.subjectValue) this.reload(); };
        this.onVisible = () => { if (document.visibilityState === 'visible') this.reload(); };
    }

    disconnect() {
        this.busyValueChanged(false);
    }

    busyValueChanged(busy) {
        document.removeEventListener('live:scan', this.onScan);
        document.removeEventListener('visibilitychange', this.onVisible);
        if (busy && this.element.isConnected) {
            document.addEventListener('live:scan', this.onScan);
            document.addEventListener('visibilitychange', this.onVisible);
        }
    }

    submitTargetConnected() { this.count(); }

    applyTargetConnected() { this.tally(); }

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

    tally() {
        if (!this.hasApplyTarget) return;
        const n = new Set([...this.element.querySelectorAll('input[name^="pick["]:checked')].filter((i) => i.value !== '').map((i) => i.name)).size;
        this.changesTarget.textContent = n || '';
        this.applyTarget.disabled = n === 0;
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
