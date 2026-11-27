import { Controller } from '@hotwired/stimulus';
import { request } from '../lib/http';

// Карточка организации файлами (x-billing.card-files, 27.11.2026): выбранные файлы уходят на сервер сразу, ответ — тот же блок
// заново (строки файлов), он встаёт на место. Пока файлы есть, поля ИП и организации в этой форме не обязательны: снимаем
// `required` (formCheck их пропускает), сервер не просит их же (`PartyRules::passport(files:)`). Удалили последний — поля снова
// обязательны.
const FIELDS = ['name', 'org', 'inn', 'ogrn', 'legal_address', 'director', 'bank_name', 'bik', 'account'];

export default class extends Controller {
    static values = { url: String, key: String, count: Number };

    static targets = ['hint', 'error'];

    connect() {
        this.relax(this.countValue > 0);
    }

    async add(event) {
        const files = [...(event.target.files ?? [])];
        if (!files.length) return;
        const body = new FormData();
        files.forEach((f) => body.append('files[]', f));
        body.append('key', this.keyValue);
        this.busy(true);
        const r = await request(this.urlValue, { method: 'POST', body, accept: 'text/html', quiet: true, statuses: [422] });
        this.busy(false);
        event.target.value = '';
        if (!r) return this.fail('Файлы не загрузились');
        if (r.status === 422) {
            const data = await r.json().catch(() => null);
            return this.fail(Object.values(data?.errors ?? {})[0]?.[0] ?? 'Файлы не загрузились');
        }
        this.replace(await r.text());
    }

    async remove({ params }) {
        const body = new FormData();
        body.append('_method', 'DELETE');
        body.append('key', this.keyValue);
        this.busy(true);
        const r = await request(`${this.urlValue}/${params.id}`, { method: 'POST', body, accept: 'text/html', quiet: true });
        this.busy(false);
        if (!r) return this.fail('Не удалилось');
        this.replace(await r.text());
    }

    replace(html) {
        const form = this.element.closest('form');
        const box = document.createElement('div');
        box.innerHTML = html.trim();
        const fresh = box.firstElementChild;
        if (!fresh) return;
        this.element.replaceWith(fresh);
        // Новый блок подключит свой контроллер; поля формы — по его числу файлов.
        const count = Number(fresh.dataset.cardFilesCountValue || 0);
        if (form) this.constructor.relaxForm(form, count > 0);
    }

    relax(on) {
        const form = this.element.closest('form');
        if (form) this.constructor.relaxForm(form, on);
    }

    static relaxForm(form, on) {
        // Звёздочки у полей ИП и организации гаснут вместе с обязательностью (`.card-filed`, app.css).
        form.classList.toggle('card-filed', on);
        form.querySelectorAll('input, textarea, select').forEach((el) => {
            const m = /\[(\w+)\]$/.exec(el.name || '');
            if (!m || !FIELDS.includes(m[1])) return;
            // Только поля ИП и организации (и ФИО ИП); у физлица паспорт обязателен как был.
            const pane = el.closest('[data-reveal-key]');
            const keys = (pane?.dataset.revealKey ?? '').split(' ');
            if (!keys.includes('ip') && !keys.includes('company')) return;
            if (on) {
                if (el.required) { el.dataset.cardRequired = '1'; el.required = false; }
            } else if (el.dataset.cardRequired) {
                el.required = true;
                delete el.dataset.cardRequired;
            }
        });
    }

    busy(on) {
        this.element.classList.toggle('spark-busy', on);
        this.errorTarget.classList.add('hidden');
    }

    fail(text) {
        this.errorTarget.textContent = text;
        this.errorTarget.classList.remove('hidden');
    }
}
