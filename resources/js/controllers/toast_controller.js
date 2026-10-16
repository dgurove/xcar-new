import { Controller } from '@hotwired/stimulus';
import { isClosing } from '../sheet';

// Всплывающие сообщения. Узел #toasts постоянный (data-turbo-permanent), серверный
// flash приходит соседним <template id="flash"> и читается после каждого рендера —
// и после полного, и после морфа (редирект на тот же адрес connect не повторяет).
// Клиент зовёт window.toast('текст'), toast('текст', 'danger'),
// toast('текст', { href }) — сообщение можно нажать, или
// toast('текст', { action: { label, run } }) — с кнопкой внутри.
export default class extends Controller {
    connect() {
        window.toast = (text, options) => this.show(text, options);
        window.flashOptions = (flash, scope) => this.options(flash, scope);
        this.onRender = () => this.flash();
        document.addEventListener('turbo:load', this.onRender);
        document.addEventListener('turbo:render', this.onRender);
        this.flash();
    }

    disconnect() {
        document.removeEventListener('turbo:load', this.onRender);
        document.removeEventListener('turbo:render', this.onRender);
    }

    leave(el) {
        if (!el.isConnected || el.classList.contains('is-leaving')) return;
        el.classList.add('is-leaving');
        const box = el.parentElement;
        // Пустой ящик тостов внутри шторки уходит вместе с последним тостом.
        const remove = () => { el.remove(); if (box && box !== this.element && !box.childElementCount) box.remove(); };
        el.addEventListener('animationend', remove, { once: true });
        setTimeout(remove, 400);
    }

    flash() {
        const flash = document.getElementById('flash');
        if (!flash) return;
        flash.remove();
        this.show(flash.dataset.message, this.options(flash, document));
    }

    // Серверный flash с data-undo — «Отменить» в тосте: отмена шага пути отправляет ту же форму, что пункт меню
    // шага (form[data-step-back]), но без подтверждения — человек только что нажал сам.
    options(flash, scope) {
        const kind = flash.dataset.kind || '';
        // data-href — тост ведёт туда нажатием («Вендор «ТИТ» заведён» → его настройки).
        if (flash.dataset.href) return { kind, href: flash.dataset.href };
        if (!('undo' in flash.dataset)) return kind;
        return { kind, action: { label: 'Отменить', run: () => {
            const form = scope.querySelector('form[data-step-back]');
            if (!form) return;
            const copy = form.cloneNode(true);
            delete copy.dataset.turboConfirm;
            copy.hidden = true;
            form.after(copy);
            copy.requestSubmit();
        } } };
    }

    // Открыта модальная шторка — тост внутри неё: она в верхнем слое (showModal), и тост из #toasts лёг бы под её
    // затемнение — не видно и не нажать («Нет связи / Повторить», «Отметьте фото» в «Поделиться»). Верхняя — та, где
    // фокус, иначе последняя.
    host() {
        // Закрывающаяся шторка (ответ её же формы) тост не принимает: он уехал бы вместе с ней.
        const open = [...document.querySelectorAll('dialog:modal')].filter((d) => !isClosing(d));
        const top = open.find((d) => d.contains(document.activeElement)) ?? open.pop();
        if (!top) return this.element;
        let box = top.querySelector(':scope > .toasts');
        if (!box) {
            box = Object.assign(document.createElement('div'), { className: 'toasts' });
            top.append(box);
        }
        return box;
    }

    show(text, options = '') {
        const kind = typeof options === 'string' ? options : options?.kind || '';
        const href = typeof options === 'object' ? options?.href : null;
        const action = typeof options === 'object' ? options?.action : null;
        const el = document.createElement(href ? 'a' : 'div');
        // Тост без ссылки и кнопки нажатия не ловит (toast-pass): он висел 4 с поверх полосы листа карточки — стрелки и
        // «Закрыть» под ним не нажимались (07.10.2026).
        el.className = 'toast' + (kind ? ` toast-${kind}` : '') + (href || action ? '' : ' toast-pass');
        el.textContent = text;
        if (href) el.href = href;
        if (action) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'toast-action';
            button.textContent = action.label;
            button.addEventListener('click', () => { this.leave(el); action.run(); });
            el.append(button);
        }
        this.host().append(el);
        // Уходит вверх сам, при удержании ждёт, смахивается вверх.
        const ttl = href || action ? 8000 : 4000;
        let timer = setTimeout(() => this.leave(el), ttl);
        let y = 0;
        el.addEventListener('touchstart', (e) => { clearTimeout(timer); y = e.touches[0].clientY; }, { passive: true });
        el.addEventListener('touchmove', (e) => { if (e.touches[0].clientY - y < -30) this.leave(el); }, { passive: true });
        el.addEventListener('touchend', () => { timer = setTimeout(() => this.leave(el), 2500); });
    }
}
