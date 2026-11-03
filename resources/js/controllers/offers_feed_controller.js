import { Controller } from '@hotwired/stimulus';
import * as Turbo from '@hotwired/turbo';
import { openSheet, closeSheet } from '../sheet';
import { request } from '../lib/http';

// «Куда присылать предложения» (x-offers-feed.sheet): строки Telegram (бот предложений) и WhatsApp, у каждой — ожидание и
// «Готово». Открывает событие offers-feed:open (строка в «Предложениях», строки профиля). Переход — сразу в приложение
// (tg:// или whatsapp://), нет его — через t.me / wa.me; на ПК — ссылкой в новой вкладке, рядом QR. Ответ — событием хаба
// (live:offers-bot, live:whatsapp) или, когда человек вернулся на вкладку, вопросом серверу. Под «Войти как» (foreign) всё
// видно, но никуда не ведёт — привязался бы чужой Telegram или WhatsApp. Сцена сверху раз в 3,5 с меняет переписку
// Telegram ⇄ WhatsApp (только если на выбор оба).
const CHANNELS = {
    tg: { live: 'live:offers-bot', state: '/account/offers-bot/state' },
    wa: { live: 'live:whatsapp', state: '/account/whatsapp/state' },
};

export default class extends Controller {
    static targets = ['step', 'scene', 'stop'];
    static values = { foreign: Boolean, both: Boolean, tgApp: String, tgWeb: String, waApp: String, waWeb: String };

    connect() {
        this.onOpen = () => this.open();
        this.onVisible = () => document.visibilityState === 'visible' && this.waiting && this.back();
        this.onLive = Object.fromEntries(Object.entries(CHANNELS).map(([channel, c]) => {
            const handler = (e) => e.detail?.state === 'linked' && this.done(channel);
            document.addEventListener(c.live, handler);
            return [channel, handler];
        }));
        window.addEventListener('offers-feed:open', this.onOpen);
        document.addEventListener('visibilitychange', this.onVisible);
        this.onClose = () => this.still();
        this.dialog?.addEventListener('close', this.onClose);
    }

    disconnect() {
        Object.entries(this.onLive).forEach(([channel, handler]) => document.removeEventListener(CHANNELS[channel].live, handler));
        window.removeEventListener('offers-feed:open', this.onOpen);
        document.removeEventListener('visibilitychange', this.onVisible);
        this.dialog?.removeEventListener('close', this.onClose);
        this.still();
    }

    get dialog() {
        return this.element.querySelector('dialog');
    }

    open() {
        const dialog = this.dialog;
        if (!dialog || dialog.open) return;
        this.show(this.waiting ? `wait-${this.waiting}` : 'pick');
        openSheet(dialog);
        this.play();
    }

    // Приходит сообщение: «печатает», потом PDF; при выборе из двух — переписка по очереди Telegram и WhatsApp.
    play() {
        if (!this.hasSceneTarget) return;
        this.still();
        const scene = this.sceneTarget;
        const replay = () => {
            scene.classList.remove('is-playing');
            void scene.offsetWidth;
            scene.classList.add('is-playing');
        };
        replay();
        if (!this.bothValue || matchMedia('(prefers-reduced-motion: reduce)').matches) return;
        this.cycle = setInterval(() => {
            scene.dataset.app = scene.dataset.app === 'tg' ? 'wa' : 'tg';
            replay();
        }, 3500);
    }

    still() {
        clearInterval(this.cycle);
        this.cycle = null;
    }

    show(name) {
        this.stepTargets.forEach((step) => { step.hidden = step.dataset.step !== name; });
        if (name !== 'pick') this.still();
    }

    // «Подписаться» / «Подключить» / «Открыть Telegram» / «Добавить и WhatsApp».
    go({ params: { channel } }) {
        if (this.foreignValue) {
            if (this.hasStopTarget) this.stopTarget.hidden = false;
            navigator.vibrate?.([10, 40, 10]);
            return;
        }
        const app = this[`${channel}AppValue`];
        const web = this[`${channel}WebValue`];
        if (!web) return;
        this.waiting = channel;
        this.show(`wait-${channel}`);
        const phone = matchMedia('(hover: none) and (pointer: coarse)').matches;
        if (!phone) { window.open(web, '_blank', 'noopener'); return; }
        location.href = app || web;
        // Приложения нет — страница так и осталась на экране: открываем t.me / wa.me.
        if (app) setTimeout(() => document.visibilityState === 'visible' && (location.href = web), 1200);
    }

    // «Назад» из ожидания — к выбору.
    pick() {
        this.waiting = null;
        this.show('pick');
        this.play();
    }

    dismiss() {
        this.waiting = null;
        this.close();
    }

    done(channel) {
        if (!this.element.querySelector(`[data-step="done-${channel}"]`)) return;
        this.waiting = null;
        const dialog = this.dialog;
        this.show(`done-${channel}`);
        if (dialog && !dialog.open) openSheet(dialog);
        navigator.vibrate?.(20);
    }

    // Подключено — страница перечитывается: строка в «Предложениях» уходит, профиль показывает подключённое.
    finish() {
        this.close().then(() => {
            this.element.remove();
            Turbo.visit(location.href, { action: 'replace' });
        });
    }

    // Вернулись из приложения, а события хаба не было (или хаба нет): спросить самим.
    async back() {
        const channel = this.waiting;
        const r = await request(CHANNELS[channel].state, { method: 'GET', quiet: true });
        if (r?.ok && (await r.json().catch(() => ({}))).linked && this.waiting === channel) this.done(channel);
    }

    close() {
        const dialog = this.dialog;
        return dialog?.open ? closeSheet(dialog) : Promise.resolve();
    }
}
