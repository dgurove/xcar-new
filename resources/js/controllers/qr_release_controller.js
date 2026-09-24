import { Controller } from '@hotwired/stimulus';
import { QrScanner } from '../qr/scanner';
import { haptic } from '../qr/haptics';
import { confirmSheet } from '../confirm';

// Выдача по QR в деле ТС: «Сканировать QR» открывает сканер, прочитанный код проверяет сервер (`/cars/{id}/pass-check`),
// ответ рисуется карточкой на месте кнопки: иконка в круге, заголовок, человек, пояснение, справа «сканировать снова».
// Код подошёл — в форму встаёт скрытое `pass`, внизу шага появляется «Выдать». Страховая не подтвердила — оранжевая
// карточка и «Подтвердила устно, выдаю». Код из адреса (?pass=, отсканировали камерой телефона) проверяется сразу.
const svg = (d, extra = '') => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" ${extra}>${d}</svg>`;
const ICONS = {
    open: svg('<path d="m5 12.5 4.5 4.5L19 7.5"/>'),
    urgent: svg('<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>'),
    danger: svg('<path d="M7 7l10 10M17 7 7 17"/>'),
    wait: svg('<path d="M12 3a9 9 0 1 0 9 9"/>', 'class="qr-result-spin"'),
    again: svg('<path d="M4 8V5a1 1 0 0 1 1-1h3M16 4h3a1 1 0 0 1 1 1v3M20 16v3a1 1 0 0 1-1 1h-3M8 20H5a1 1 0 0 1-1-1v-3M8 12h8"/>'),
};

export default class extends Controller {
    static targets = ['pass', 'confirm', 'result', 'scan', 'submit'];
    static values = { checkUrl: String, preset: String };

    connect() {
        if (this.presetValue) this.check(this.presetValue);
    }

    open() {
        new QrScanner({ onScan: (text) => this.check(text) }).open();
    }

    async confirmOrally(event) {
        if (!(await confirmSheet('Страховая подтвердила покупателя устно?', { submitter: event.currentTarget }))) return;
        this.confirmTarget.value = '1';
        this.ready({ ...this.last, title: 'Подтверждено устно' });
    }

    async check(text, confirm = false) {
        this.scanTarget.hidden = true;
        this.render('wait', 'Проверяю код…');
        let data;
        try {
            const token = document.querySelector('meta[name="csrf-token"]')?.content;
            const response = await fetch(this.checkUrlValue, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': token ?? '' },
                body: JSON.stringify({ code: text, confirm }),
            });
            if (!response.ok) {
                this.scanTarget.hidden = false;
                this.render('danger', response.status === 419 ? 'Сессия устарела, обновите страницу' : 'Ошибка сервера, попробуйте ещё раз');
                haptic('error');
                return;
            }
            data = await response.json();
        } catch {
            this.scanTarget.hidden = false;
            this.render('danger', 'Нет связи, попробуйте ещё раз');
            haptic('error');
            return;
        }
        this.last = data;
        if (data.status === 'ok') { haptic('success'); this.ready(data); return; }
        haptic('error');
        this.passTarget.value = '';
        this.submitTarget.hidden = true;
        if (data.status === 'unconfirmed') {
            this.scanTarget.hidden = true;
            this.render('urgent', data, '<button type="button" class="btn btn-s btn-accent mt-3" data-action="qr-release#confirmOrally">Подтвердила устно, выдаю</button>');
            this.passTarget.value = data.code;
            return;
        }
        // Не тот код: главное — сканировать другой, поэтому большая кнопка возвращается над карточкой.
        this.scanTarget.hidden = false;
        this.render('danger', data, data.url ? `<a href="${data.url}" class="btn btn-s btn-quiet mt-3">Открыть то ТС</a>` : '');
    }

    ready(data) {
        this.passTarget.value = data.code;
        this.render('open', data);
        this.scanTarget.hidden = true;
        this.submitTarget.hidden = false;
    }

    // Карточка итога: иконка тона, заголовок, человек (или ТС), пояснение; справа — сканировать снова.
    render(tone, data, extra = '') {
        const { title, person, text } = typeof data === 'string' ? { title: data } : data;
        const box = this.resultTarget;
        box.hidden = false;
        box.className = `qr-result qr-result--${tone} mt-3`;
        box.innerHTML = `<span class="qr-result-icon">${ICONS[tone]}</span><div class="qr-result-body"></div>`;
        const body = box.querySelector('.qr-result-body');
        for (const [cls, value] of [['qr-result-title', title], ['qr-result-person', person], ['qr-result-text', text]]) {
            if (!value) continue;
            const el = document.createElement('div');
            el.className = cls;
            el.textContent = value;
            body.append(el);
        }
        if (extra) body.insertAdjacentHTML('beforeend', extra);
        if (tone === 'open' || tone === 'urgent') box.insertAdjacentHTML('beforeend', `<button type="button" class="qr-result-again" data-action="qr-release#open" aria-label="Сканировать снова">${ICONS.again}</button>`);
    }
}
