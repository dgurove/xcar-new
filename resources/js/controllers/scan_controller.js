import { Controller } from '@hotwired/stimulus';
import * as Turbo from '@hotwired/turbo';
import { closeSheet } from '../sheet';

// «✨ Распознать» (admin/mail/scan). Фрейм обновляется морфом — контроллер не переподключается, поэтому всё
// держится на колбэках Stimulus: появилась кнопка шага — пересчитать её, поменялся busy — включить или снять
// ожидание. Файлы: число отмеченных на кнопке, больше max — кнопка молчит; «Выбрать все» у фото; форма с
// data-scan-go (один файл из шторки документов) уходит сама. Чтение (busy): фрейм перечитывается по событию live:scan
// своего предмета (цепочка c:…, ТС v:…, предложение o:…) и при возврате на вкладку — без опроса по таймеру.
// Поля: «Подставить N» — сколько полей изменится; ничего — кнопка молчит. Итог (done): окно закрывается, тост, и
// перечитывается то, откуда окно открыли: открытая карточка строки — само, без страницы; под окном писем — страница,
// когда его закроют; иначе страница морфом на месте, а открытая шторка документов возвращается на тот же файл.
export default class extends Controller {
    static targets = ['count', 'submit', 'all', 'apply', 'changes', 'done'];
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

    submitTargetConnected(button) {
        this.count();
        const form = button.form;
        if (form?.hasAttribute('data-scan-go') && !button.disabled) {
            form.removeAttribute('data-scan-go');
            form.requestSubmit(button);
        }
    }

    doneTargetConnected(el) {
        const dialog = this.element.closest('dialog');
        const frame = this.element.closest('turbo-frame');
        window.toast?.(el.dataset.message);
        (dialog ? closeSheet(dialog) : Promise.resolve()).then(() => {
            // Итог показан — фрейм без адреса: перечитка страницы морфом не должна снова его показать.
            frame?.removeAttribute('src');
            // Открыта карточка строки рядом со списком — перечитывается только она (тот же адрес списка в её фрейм).
            if (document.querySelector('turbo-frame#detail > .detail-card')) { window.Turbo.visit(location.pathname + location.search, { frame: 'detail', action: 'replace' }); return; }
            const refresh = () => {
                window.dispatchEvent(new CustomEvent('docs:keep'));
                // Раскрытое под руками (поля ТС в деле) остаётся раскрытым: морф вернул бы свёрнутое с сервера.
                const open = [...document.querySelectorAll('details[open][id]')].map((d) => d.id);
                document.addEventListener('turbo:morph', () => open.forEach((id) => { const d = document.getElementById(id); if (d) d.open = true; }), { once: true });
                Turbo.visit(location.href, { action: 'replace' });
            };
            const under = [...document.querySelectorAll('dialog[open]')].find((d) => d !== dialog && d.matches(':modal'));
            under ? under.addEventListener('close', refresh, { once: true }) : refresh();
        });
    }

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
