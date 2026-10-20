import { Controller } from '@hotwired/stimulus';
import { request } from '../lib/http';
import { markPush } from '../push-sync';
import { confirm, hintFor, subscribe, subscription, supported, test } from '../lib/push';

// Уведомления на телефон: разрешение, подписка, отправка ключей на сервер. Строка карточки «Не пропускайте…»
// (`row`, x-reach.card) видна, только пока включать есть что: браузер умеет и подписки нет; запретил — подсказка
// вместо кнопки. После каждой отрисовки — push:render, карточка решает, осталось ли в ней что-то.
// Включили — сразу пробный пуш в эту подписку (`/push/test`) и вопрос «Пришло?» (x-push.ask, 20.10.2026): сервер знает
// только, что пуш принят, а показ режет сама система (на Mac Chrome выключен в «Уведомлениях», «Фокусирование»).
// «Нет» — подсказка, где разрешить, для этой системы, и «Проверить ещё раз»; ответ — `/push/confirm`, его видит владелец.
export default class extends Controller {
    static targets = ['on', 'off', 'state', 'toggle', 'ask', 'hint'];
    static values = { row: Boolean };

    async connect() {
        if (!supported()) { this.element.hidden = true; this.dispatch('render'); return; }
        // Включили в окне x-push.offer — строка карточки и тумблер узнают сами.
        this.onSubscribed = async () => { this.sub = await subscription(); this.render(); };
        document.addEventListener('push:subscribed', this.onSubscribed);
        this.sub = await subscription();
        this.render();
    }

    disconnect() {
        document.removeEventListener('push:subscribed', this.onSubscribed);
    }

    render() {
        const granted = Notification.permission === 'granted' && this.sub;
        const denied = Notification.permission === 'denied';
        // Пока спрашиваем «Пришло?» — строка карточки остаётся, хоть подписка уже есть.
        if (this.rowValue) this.element.hidden = !!granted && !this.asking;
        if (this.hasOnTarget) this.onTarget.hidden = !!granted || (this.rowValue && denied);
        if (this.hasOffTarget) this.offTarget.hidden = !granted;
        // Тумблер в строке настроек: одно поле вместо пары кнопок.
        if (this.hasToggleTarget) { this.toggleTarget.checked = !!granted; this.toggleTarget.disabled = denied; }
        if (this.hasStateTarget) {
            const text = denied ? (this.rowValue ? 'Запрещены в браузере: настройки сайта → Уведомления' : 'Уведомления запрещены в настройках телефона') : (this.note || '');
            this.stateTarget.textContent = text;
            this.stateTarget.hidden = !text;
        }
        if (this.hasAskTarget) this.askTarget.hidden = !this.asking || this.silent;
        if (this.hasHintTarget) {
            this.hintTarget.hidden = !this.silent;
            const text = this.hintTarget.querySelector('[data-hint-text]');
            if (text) text.textContent = hintFor(navigator.userAgent);
        }
        this.dispatch('render');
    }

    toggle(event) {
        event.target.checked ? this.enable() : this.disable();
    }

    // Не вышло на любом шаге (подписка браузера, ключи на сервер) — тумблер возвращается как был: подписки, о которой
    // сервер не знает, не остаётся.
    async enable() {
        this.note = '';
        const { state, sub } = await subscribe();
        // Chrome прячет запрос: вместо окна — зачёркнутый колокольчик в адресной строке, «Включить» будто не сработало.
        if (state === 'default') this.note = 'Разрешите уведомления: значок колокольчика в адресной строке';
        if (state === 'failed') window.toast?.('Не получилось включить уведомления', 'danger');
        if (sub) { this.sub = sub; this.check(); }
        this.render();
    }

    // Пробный пуш в эту подписку и вопрос «Пришло?».
    async check() {
        if (!this.sub) return;
        this.asking = true;
        this.silent = false;
        this.render();
        if (!(await test(this.sub))) { this.asking = false; this.render(); window.toast?.('Не получилось отправить пробное', 'danger'); }
    }

    async yes() {
        this.asking = false;
        this.silent = false;
        this.render();
        window.toast?.('Уведомления включены');
        await confirm(this.sub, true);
    }

    async no() {
        this.silent = true;
        this.render();
        await confirm(this.sub, false);
    }

    // Сначала сервер: не ответил — подписка остаётся, тумблер включён.
    async disable() {
        try {
            if (!(await request('/push/subscription', { method: 'DELETE', json: { endpoint: this.sub?.endpoint } }))) return;
            await this.sub?.unsubscribe();
            this.sub = null;
            this.asking = false;
            this.silent = false;
            markPush(false);
        } catch {
            window.toast?.('Не получилось выключить уведомления', 'danger');
        } finally {
            this.render();
        }
    }
}
