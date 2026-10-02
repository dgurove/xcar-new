import { Controller } from '@hotwired/stimulus';
import * as Turbo from '@hotwired/turbo';
import { openSheet, closeSheet } from '../sheet';

// Telegram у аккаунта. Два режима:
//   link  — шторка подключения (x-telegram.connect): предложение → ожидание → готово. Открывают её событие
//           telegram:open (карточка «Сделок», строка профиля, настройки) и сама: один раз при входе на
//           /offers или /deals и после подтверждения ценой — если сервер разрешил момент (moments).
//   login — «Войти через Telegram» на странице входа: ожидание прямо в кнопке, вход после «Войти» в чате.
// Под «Войти как» (foreign) шторка, строка и карточка те же, а «Подключить» останавливает тостом.
// Переход в Telegram — сразу в приложение (tg://), а если его нет — через t.me. Ответ приходит событием хаба
// live:telegram или, когда человек вернулся на вкладку, вопросом серверу.
export default class extends Controller {
    static targets = ['step', 'scene', 'label', 'cancel', 'title', 'stop'];
    static values = { mode: String, token: String, moments: Array, app: String, web: String, foreign: Boolean, intro: { type: Array, default: ['/offers', '/deals'] } };

    connect() {
        this.onLive = (e) => this.live(e.detail || {});
        this.onVisible = () => document.visibilityState === 'visible' && this.waiting && this.back();
        document.addEventListener('live:telegram', this.onLive);
        document.addEventListener('visibilitychange', this.onVisible);
        if (this.modeValue !== 'link') return;
        this.onOpen = () => this.open();
        this.onSubmit = (e) => this.submitted(e);
        window.addEventListener('telegram:open', this.onOpen);
        document.addEventListener('turbo:submit-end', this.onSubmit);
        // Шторка постоянная между визитами (data-turbo-permanent): вход проверяем на каждой странице.
        this.onLoad = () => this.intro();
        document.addEventListener('turbo:load', this.onLoad);
        this.intro();
    }

    // Один раз при входе и только на главных экранах: дать странице встать и не лезть поверх другой шторки.
    intro() {
        clearTimeout(this.introTimer);
        // Где шторка открывается сама — с сервера: на сайте списки менеджера, в CRM у модератора — «Предложения» (/).
        if (!this.may('intro') || !this.introValue.includes(location.pathname)) return;
        this.introTimer = setTimeout(() => !document.querySelector('dialog:modal') && this.open('intro'), 1500);
    }

    disconnect() {
        clearTimeout(this.introTimer);
        document.removeEventListener('live:telegram', this.onLive);
        document.removeEventListener('visibilitychange', this.onVisible);
        window.removeEventListener('telegram:open', this.onOpen);
        document.removeEventListener('turbo:load', this.onLoad);
        document.removeEventListener('turbo:submit-end', this.onSubmit);
    }

    may(moment) {
        return this.momentsValue.includes(moment);
    }

    // Подтвердил ценой — самое время: «узнайте первым, если выберут вас». Ждём, пока страница или окошко
    // перерисуются после ответа, иначе морф закрыл бы только что открытую шторку.
    submitted(event) {
        if (!event.detail.success || !event.target.closest('[data-telegram-moment="bid"]') || !this.may('bid')) return;
        const later = () => setTimeout(() => !document.querySelector('dialog:modal') && this.open('bid'), 600);
        ['turbo:load', 'turbo:morph', 'turbo:frame-render'].forEach((name) => document.addEventListener(name, later, { once: true }));
    }

    open(moment) {
        clearTimeout(this.introTimer);
        const dialog = this.element.querySelector('dialog');
        if (!dialog || dialog.open) return;
        if (moment) {
            this.momentsValue = this.momentsValue.filter((m) => m !== moment);
            // Под «Войти как» у человека ничего не тратим: он увидит шторку сам.
            if (!this.foreignValue) this.post('/account/telegram/seen', { moment });
        }
        // После подтверждения ценой заголовок — про эту минуту: «Узнайте первым, если выберут вас».
        if (this.hasTitleTarget) {
            this.titleTarget.dataset.base ??= this.titleTarget.textContent;
            this.titleTarget.textContent = (moment === 'bid' && this.titleTarget.dataset.bid) || this.titleTarget.dataset.base;
        }
        this.show(this.waiting ? 'wait' : 'offer');
        openSheet(dialog);
        this.play();
    }

    // Бот «печатает», потом приходит сообщение — заново при каждом открытии.
    play() {
        if (!this.hasSceneTarget) return;
        this.sceneTarget.classList.remove('is-playing');
        void this.sceneTarget.offsetWidth;
        this.sceneTarget.classList.add('is-playing');
    }

    show(name) {
        this.stepTargets.forEach((step) => { step.hidden = step.dataset.step !== name; });
    }

    // «Подключить Telegram» / «Открыть Telegram» / «Войти через Telegram».
    go() {
        // Чужой аккаунт («Войти как»): всё видно, но в Telegram не ведём — привязался бы Telegram админа.
        if (this.foreignValue) {
            if (this.hasStopTarget) this.stopTarget.hidden = false;
            navigator.vibrate?.([10, 40, 10]);
            return;
        }
        this.waiting = true;
        if (this.modeValue === 'login') this.pending(true);
        else this.show('wait');
        const phone = matchMedia('(hover: none) and (pointer: coarse)').matches;
        if (!phone) { window.open(this.webValue, '_blank', 'noopener'); return; }
        location.href = this.appValue;
        // Telegram не установлен — страница так и осталась на экране: открываем t.me.
        setTimeout(() => document.visibilityState === 'visible' && (location.href = this.webValue), 1200);
    }

    dismiss() {
        this.waiting = false;
        this.close();
    }

    cancel() {
        this.waiting = false;
        this.pending(false);
    }

    // Подключён: шторка постоянная между визитами — убрать её самим, страница перечитается без карточки и «Подключить».
    finish() {
        this.close().then(() => {
            this.element.remove();
            Turbo.visit(location.href, { action: 'replace' });
        });
    }

    live({ state }) {
        if (this.modeValue === 'link' && state === 'linked') this.done();
        else if (this.modeValue === 'login' && state === 'login') this.check();
        else if (this.modeValue === 'login' && state === 'denied') { this.cancel(); window.toast?.('Вход отклонён'); }
    }

    // Вернулись из Telegram, а события хаба не было (или хаба нет): спросить самим.
    async back() {
        if (this.modeValue === 'login') { this.check(); return; }
        const r = await this.request('/account/telegram/state', 'GET');
        if (r?.ok && (await r.json()).linked) this.done();
    }

    done() {
        this.waiting = false;
        this.momentsValue = [];
        const dialog = this.element.querySelector('dialog');
        this.show('done');
        if (dialog && !dialog.open) openSheet(dialog);
        navigator.vibrate?.(20);
    }

    async check() {
        const r = await this.post(`/login/telegram/${this.tokenValue}`, {});
        if (!r) return;
        if (r.status === 200) {
            this.labelTarget.textContent = 'Входим';
            location.href = (await r.json()).href;
        } else if (r.status === 410) {
            // «Это не я» или ссылка устарела — страница входа выдаст новую.
            Turbo.visit(location.href, { action: 'replace' });
        }
    }

    // Кнопка входа ждёт: кольцо и «Подтвердите вход в Telegram», под ней «Отмена».
    pending(on) {
        this.original ??= this.labelTarget.innerHTML;
        this.labelTarget.innerHTML = on ? '<span class="tg-spin"></span>Подтвердите вход в Telegram' : this.original;
        if (this.hasCancelTarget) this.cancelTarget.hidden = !on;
    }

    close() {
        const dialog = this.element.querySelector('dialog');
        return dialog?.open ? closeSheet(dialog) : Promise.resolve();
    }

    post(url, data) {
        const body = new FormData();
        Object.entries(data).forEach(([k, v]) => body.append(k, v));
        return this.request(url, 'POST', body);
    }

    async request(url, method, body) {
        try {
            return await fetch(url, {
                method, body,
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
            });
        } catch {
            return null;
        }
    }
}
