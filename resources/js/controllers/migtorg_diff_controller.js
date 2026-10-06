import { Controller } from '@hotwired/stimulus';
import * as Turbo from '@hotwired/turbo';
import { confirmSheet } from '../confirm';

// Поле не совпадает с лотом Мигторга (Offers\MigtorgDiff): у подписи поля — «!», нажатие раскрывает под полем, как на
// Мигторге, и две кнопки: «Взять» — значение Мигторга в поле (страница перечитывается), «Отклонить» — Мигторг неправ,
// этот его текст больше не показывается (06.10.2026). Запрос — fetch, не вложенная форма: вокруг форма с save-bar.
export default class extends Controller {
    static values = { fields: Object, url: String };

    fieldsValueChanged(fields) {
        this.element.querySelectorAll('[data-migtorg-diff]').forEach((el) => el.remove());
        for (const [name, text] of Object.entries(fields ?? {})) {
            const input = document.getElementById(`f-${name}`) ?? this.element.querySelector(`[name="${name}"]`);
            const box = input?.closest('.field');
            const label = box?.querySelector('.field-label');
            if (!label) continue;
            const note = document.createElement('div');
            note.className = 'field-note flex flex-wrap items-baseline gap-x-3';
            note.dataset.migtorgDiff = '';
            note.hidden = true;
            const say = document.createElement('span');
            say.textContent = `На Мигторге: ${text}`;
            note.append(say);
            if (this.urlValue) {
                note.append(this.button('Отклонить', 'text-ink-muted', () => this.send(name, 'reject')));
                note.append(this.button('Взять', 'text-accent-text', async (b) => {
                    const what = label.firstChild?.textContent?.trim() || 'Значение';
                    if (await confirmSheet(`${what} с Мигторга: ${text}?`, { submitter: b })) this.send(name, 'take');
                }));
            }
            const mark = document.createElement('button');
            mark.type = 'button';
            mark.className = 'migtorg-diff';
            mark.dataset.migtorgDiff = '';
            mark.textContent = '!';
            mark.setAttribute('aria-label', 'На Мигторге иначе');
            mark.addEventListener('click', (e) => { e.preventDefault(); note.hidden = !note.hidden; });
            label.append(mark);
            box.append(note);
        }
    }

    button(text, tone, act) {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = `text-sm ${tone}`;
        b.textContent = text;
        b.dataset.turboConfirmLabel = text;
        b.addEventListener('click', (e) => { e.preventDefault(); act(b); });
        return b;
    }

    async send(name, act) {
        const body = new FormData();
        body.append('_token', document.querySelector('meta[name="csrf-token"]')?.content || '');
        const r = await fetch(`${this.urlValue}/migtorg/${encodeURIComponent(name)}/${act}`, { method: 'POST', body, headers: { Accept: 'application/json' } }).catch(() => null);
        if (!r?.ok) return window.toast?.('Не вышло, обновите страницу');
        const { ok, fields } = await r.json();
        if (!ok) window.toast?.('Уже не так, обновите страницу');
        if (act === 'take' && ok) return Turbo.visit(location.href, { action: 'replace' });
        this.fieldsValue = fields;
    }
}
