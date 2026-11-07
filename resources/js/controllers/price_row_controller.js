import { Controller } from '@hotwired/stimulus';
import * as Turbo from '@hotwired/turbo';
import { floorFrom } from '../lib/floor';
import { request } from '../lib/http';
import { rub, rubles } from '../lib/money';

// Цена прямо в строке списка партнёра (08.11.2026, владелец: «таблица выглядит отвратительно»; поставщику — двести машин,
// по каждой вписать оценочную из своей системы). Оценочная → закупочная на лету по ступеням предложения (`rules`, как
// `value_floor`). Enter — сохранить (`PUT /offers/{id}` тем же путём, что редактор: `_fields[]=value`, ответ JSON), фокус —
// на поле следующей строки; строка остаётся с «Отправить» (владелец: «автоматически не отправлять»). «Отправить» —
// `POST /offers/{id}/send`: строка уходит к нам, числа в заголовке и на «Отправить N» над списком — на одну меньше.
// Нажатие по строке мимо поля и кнопок — редактор.

export default class extends Controller {
    static targets = ['input', 'floor', 'send'];
    static values = { url: String, rules: Array };

    connect() {
        this.saved = this.value();
    }

    sync() {
        this.say('');
        const value = this.value();
        this.floorTarget.textContent = value ? rub(floorFrom(value, this.rulesValue)) : '';
        this.element.classList.toggle('is-dirty', value !== this.saved);
    }

    key(e) {
        if (e.key === 'Escape') { this.inputTarget.value = this.saved ? String(this.saved) : ''; this.inputTarget.dispatchEvent(new Event('input')); return; }
        if (e.key !== 'Enter') return;
        e.preventDefault();
        const next = this.next();
        if (this.value() && this.value() !== this.saved) this.save();
        next?.focus();
    }

    async save() {
        const value = this.value();
        this.element.classList.add('is-busy');
        const r = await request(this.urlValue, { method: 'PUT', json: { value, _fields: ['value'] }, statuses: [422] });
        this.element.classList.remove('is-busy');
        if (!r) return;
        const data = await r.json().catch(() => ({}));
        if (r.status === 422) {
            this.say(Object.values(data.errors ?? {})[0]?.[0] ?? 'Не получилось');
            return;
        }
        this.saved = value;
        this.element.classList.remove('is-dirty');
        this.element.classList.toggle('is-priced', !!data.priced);
        this.waiting(data.waiting);
    }

    async send() {
        // Вписали и сразу «Отправить», не нажав Enter, — сначала сохранить.
        if (this.value() && this.value() !== this.saved) await this.save();
        if (!this.element.classList.contains('is-priced')) return;
        this.sendTarget.disabled = true;
        const r = await request(`${this.urlValue}/send`, { method: 'POST' });
        this.sendTarget.disabled = false;
        if (!r) return;
        const data = await r.json().catch(() => ({}));
        if (!data.sent) return;
        this.waiting(data.waiting);
        const count = document.querySelector('[data-shell-count]');
        const n = Number(count?.textContent);
        if (count && n > 0) count.textContent = String(n - 1);
        this.element.classList.add('is-gone');
        setTimeout(() => this.element.remove(), 260);
    }

    // «Отправить N» над списком: число и видимость; без вписанных главная кнопка — снова «+ Новое».
    waiting(n) {
        if (typeof n !== 'number') return;
        const form = document.querySelector('[data-partner-send-all]');
        if (!form) return;
        form.hidden = n === 0;
        form.querySelector('[data-partner-waiting]').textContent = String(n);
        const plus = document.querySelector('[data-partner-new]');
        plus?.classList.toggle('btn-accent', n === 0);
        plus?.classList.toggle('btn-quiet', n > 0);
    }

    open(e) {
        if (e.target.closest('input, button, a, [role="button"], label') || getSelection()?.toString()) return;
        Turbo.visit(this.urlValue);
    }

    next() {
        const all = [...document.querySelectorAll('[data-price-row-target="input"]')].filter((el) => !el.closest('.is-gone'));
        return all[all.indexOf(this.inputTarget) + 1] ?? null;
    }

    value() {
        return this.hasInputTarget ? (rubles(this.inputTarget.value) ?? 0) : 0;
    }

    say(text) {
        this.floorTarget.classList.toggle('is-error', !!text);
        if (text) this.floorTarget.textContent = text;
    }
}
