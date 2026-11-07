import { Controller } from '@hotwired/stimulus';
import { floorFrom } from '../lib/floor';
import { csrf, request } from '../lib/http';
import { rub, rubles } from '../lib/money';
import * as Turbo from '@hotwired/turbo';

// Цена прямо в строке списка партнёра (08.11.2026, владелец: «таблица выглядит отвратительно»; поставщику — двести машин,
// по каждой вписать оценочную из своей системы). Оценочная → закупочная на лету по ступеням предложения (`rules`, как
// `value_floor`). Enter — 5 секунд «Отменить» на месте поля (как «Отменить отправку» в почте: ушедшая цена из его списка
// пропадает, вернуть её нечем), фокус сразу на поле следующей строки; потом `PUT /offers/{id}` тем же путём, что
// редактор (`_fields[]=value`, ответ JSON) — строка уходит. Уход со страницы раньше — ждущие уходят сразу (`keepalive`).
// Пока ждёт — `data-live-hold`: живое обновление не вернёт строку к серверной. Нажатие по строке мимо поля — редактор.

const waiting = new Set();
const flushAll = () => waiting.forEach((c) => c.send(true));
let bound = false;

export default class extends Controller {
    static targets = ['input', 'floor', 'wait', 'box'];
    static values = { url: String, rules: Array };

    connect() {
        if (!bound) {
            bound = true;
            document.addEventListener('turbo:before-visit', flushAll);
            window.addEventListener('pagehide', flushAll);
        }
    }

    disconnect() {
        if (this.timer) this.send(true);
    }

    sync() {
        this.say('');
        const value = this.value();
        this.floorTarget.textContent = value ? rub(floorFrom(value, this.rulesValue)) : '';
    }

    key(e) {
        if (e.key === 'Escape') { this.inputTarget.value = ''; this.sync(); return; }
        if (e.key !== 'Enter') return;
        e.preventDefault();
        if (!this.value()) return;
        this.arm();
        this.next()?.focus();
    }

    arm() {
        this.boxTarget.classList.add('is-waiting');
        this.waitTarget.hidden = false;
        this.element.dataset.liveHold = '';
        waiting.add(this);
        this.timer = setTimeout(() => this.send(), 5000);
    }

    undo() {
        this.stop();
        this.inputTarget.focus();
    }

    stop() {
        clearTimeout(this.timer);
        this.timer = null;
        waiting.delete(this);
        this.boxTarget.classList.remove('is-waiting');
        this.waitTarget.hidden = true;
        delete this.element.dataset.liveHold;
    }

    async send(leaving = false) {
        if (!this.timer) return;
        const body = { value: this.value(), _fields: ['value'] };
        this.stop();
        if (leaving) {
            // Страница уходит — ответ уже некому показать; запрос доживёт и без неё.
            fetch(this.urlValue, { method: 'PUT', keepalive: true, credentials: 'same-origin', body: JSON.stringify(body),
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest' } });
            this.gone();
            return;
        }
        this.boxTarget.classList.add('is-sending');
        const r = await request(this.urlValue, { method: 'PUT', json: body, statuses: [422] });
        this.boxTarget.classList.remove('is-sending');
        if (!r) return;
        const data = await r.json().catch(() => ({}));
        if (r.status === 422) {
            this.say(Object.values(data.errors ?? {})[0]?.[0] ?? 'Не получилось');
            return;
        }
        if (data.sent) this.gone();
    }

    // Строка ушла к нам: сворачивается, число в заголовке на одну меньше.
    gone() {
        const count = document.querySelector('[data-shell-count]');
        const n = Number(count?.textContent);
        if (count && n > 0) count.textContent = String(n - 1);
        this.element.classList.add('is-gone');
        setTimeout(() => this.element.remove(), 260);
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
