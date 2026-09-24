import { Controller } from '@hotwired/stimulus';
import { QrScanner } from '../qr/scanner';
import { haptic } from '../qr/haptics';

// Выдача по QR в деле ТС: «Сканировать QR» открывает сканер, прочитанный код проверяет сервер (`/cars/{id}/pass-check`),
// ответ рисуется плашкой на месте кнопки. Код подошёл — в форму встаёт скрытое `pass`, кнопка становится «Выдать».
// Страховая ещё не подтвердила — оранжевая плашка и «Страховая подтвердила устно, выдаю». Код из адреса (?pass=,
// отсканировали камерой телефона) проверяется сразу.
export default class extends Controller {
    static targets = ['pass', 'confirm', 'result', 'scan', 'submit'];
    static values = { checkUrl: String, preset: String };

    connect() {
        if (this.presetValue) this.check(this.presetValue);
    }

    open() {
        new QrScanner({ onScan: (text) => this.check(text) }).open();
    }

    confirmOrally() {
        if (!window.confirm('Страховая подтвердила покупателя устно? Это запишется в историю ТС')) return;
        this.confirmTarget.value = '1';
        this.ready({ code: this.last.code, title: 'Подтверждено устно: ' + this.last.name, text: this.last.text });
    }

    async check(text, confirm = false) {
        this.render('wait', 'Проверяю код…');
        let data;
        try {
            const token = document.querySelector('meta[name="csrf-token"]')?.content;
            const response = await fetch(this.checkUrlValue, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': token ?? '' },
                body: JSON.stringify({ code: text, confirm }),
            });
            data = await response.json();
        } catch {
            this.render('danger', 'Нет связи, попробуйте ещё раз');
            haptic('error');
            return;
        }
        this.last = data;
        if (data.status === 'ok') { haptic('success'); this.ready(data); return; }
        haptic('error');
        this.passTarget.value = '';
        this.submitTarget.hidden = true;
        this.scanTarget.hidden = false;
        if (data.status === 'unconfirmed') {
            this.render('urgent', data.title, data.text, '<button type="button" class="btn btn-s btn-accent mt-3" data-action="qr-release#confirmOrally">Страховая подтвердила устно, выдаю</button>');
            this.passTarget.value = data.code;
            return;
        }
        this.render('danger', data.title, data.text, data.url ? `<a href="${data.url}" class="btn btn-s btn-quiet mt-3">Открыть то ТС</a>` : '');
    }

    ready(data) {
        this.passTarget.value = data.code;
        this.render('open', data.title, data.text);
        this.scanTarget.hidden = true;
        this.submitTarget.hidden = false;
    }

    render(tone, title, text = '', extra = '') {
        const box = this.resultTarget;
        box.hidden = false;
        box.className = `qr-result qr-result--${tone}`;
        box.innerHTML = '';
        const head = document.createElement('div');
        head.className = 'qr-result-title';
        head.textContent = title;
        box.append(head);
        if (text) {
            const p = document.createElement('div');
            p.className = 'qr-result-text';
            p.textContent = text;
            box.append(p);
        }
        if (extra) box.insertAdjacentHTML('beforeend', extra);
    }
}
