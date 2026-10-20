import { Controller } from '@hotwired/stimulus';
import { openSheet, closeSheet } from '../sheet';
import { browserName, confirm, fixSteps, needsHomeScreen, subscribe, subscription, supported, test } from '../lib/push';

// Окно «Уведомления на это устройство» (x-push.offer): предложение → разрешение → пробное и «Видите его?» → готово.
// Само открывается на главном списке приложения, когда на устройстве пушей нет: не раньше шторки Telegram (она первая
// на том же экране), не чаще раза в неделю и не больше трёх раз — память устройства, пуш у каждого устройства свой.
const WEEK = 7 * 24 * 3600 * 1000;

export default class extends Controller {
    static targets = ['step', 'scene', 'fixSteps', 'browser', 'siteSteps', 'blockedTitle'];
    static values = { intro: Array, user: String };

    connect() {
        this.onOpen = () => this.open();
        window.addEventListener('push:open', this.onOpen);
        this.onLoad = () => this.intro();
        document.addEventListener('turbo:load', this.onLoad);
        this.intro();
    }

    disconnect() {
        clearTimeout(this.timer);
        window.removeEventListener('push:open', this.onOpen);
        document.removeEventListener('turbo:load', this.onLoad);
    }

    get key() { return `push:offer:${this.userValue}`; }

    memory() {
        try { return JSON.parse(localStorage.getItem(this.key)) || { n: 0, at: 0 }; } catch { return { n: 3, at: Date.now() }; }
    }

    async intro() {
        clearTimeout(this.timer);
        if (!this.introValue.includes(location.pathname)) return;
        if (!supported() && !needsHomeScreen()) return;
        if ('Notification' in window && Notification.permission === 'denied') return;
        const { n, at } = this.memory();
        if (n >= 3 || Date.now() - at < WEEK) return;
        if (await subscription()) return;
        // Шторка Telegram открывается на том же экране через 1,5 с — пусть будет одна: она открылась — в этот раз молчим.
        this.timer = setTimeout(() => {
            if (document.querySelector('dialog:modal')) return;
            try { localStorage.setItem(this.key, JSON.stringify({ n: n + 1, at: Date.now() })); } catch {}
            this.open();
        }, 2600);
    }

    open() {
        clearTimeout(this.timer);
        const dialog = this.element.querySelector('dialog');
        if (!dialog || dialog.open) return;
        this.show(needsHomeScreen() ? 'ios' : 'offer');
        openSheet(dialog);
        this.play();
    }

    // Уведомление выезжает сверху заново при каждом открытии.
    play() {
        if (!this.hasSceneTarget) return;
        this.sceneTarget.classList.remove('is-playing');
        void this.sceneTarget.offsetWidth;
        this.sceneTarget.classList.add('is-playing');
    }

    show(name) {
        this.stepTargets.forEach((step) => { step.hidden = step.dataset.step !== name; });
    }

    async enable() {
        if (needsHomeScreen()) { this.show('ios'); return; }
        if (Notification.permission === 'default') this.show('prompt');
        const { state, sub } = await subscribe();
        if (state === 'granted') { this.sub = sub; this.again(); return; }
        if (state === 'failed') { this.show('offer'); window.toast?.('Не получилось включить уведомления', 'danger'); return; }
        // 'default' — Chrome не показал окно, а спрятал запрос в значок адресной строки; 'denied' — запрещено для сайта.
        this.blockedTitleTarget.textContent = state === 'default' ? 'Браузер спрятал запрос' : 'Уведомления запрещены для сайта';
        this.fill(this.siteStepsTarget, siteSteps(state));
        this.show('blocked');
    }

    async again() {
        this.show('test');
        if (!(await test(this.sub))) { this.show('offer'); window.toast?.('Не получилось отправить пробное', 'danger'); }
    }

    async yes() {
        this.show('done');
        navigator.vibrate?.(20);
        confirm(this.sub, true);
        document.dispatchEvent(new CustomEvent('push:subscribed'));
    }

    no() {
        this.browserTarget.textContent = `${browserName()} получил его, но система не показала`;
        this.fill(this.fixStepsTarget, fixSteps());
        this.show('fix');
        confirm(this.sub, false);
        document.dispatchEvent(new CustomEvent('push:subscribed'));
    }

    later() {
        closeSheet(this.element.querySelector('dialog'));
    }

    fill(list, steps) {
        list.replaceChildren(...steps.map((s) => Object.assign(document.createElement('li'), { textContent: s })));
    }
}

// Как разрешить уведомления самому сайту: на ПК — значок слева от адреса, на Android — меню браузера.
function siteSteps(state) {
    const agent = navigator.userAgent;
    if (/Android/.test(agent)) return ['Меню браузера ⋮ → Настройки', 'Настройки сайтов → Уведомления', 'Разрешите для этого сайта и нажмите «Попробовать снова»'];
    if (state === 'default') return ['Нажмите значок колокольчика справа в адресной строке', '«Разрешить»', 'Затем «Попробовать снова»'];
    return ['Нажмите значок слева от адреса сайта', '«Уведомления» → «Разрешить»', 'Обновите страницу и нажмите «Попробовать снова»'];
}
