import { Controller } from '@hotwired/stimulus';
import { nums, rub } from '../lib/money';
import { confirmSheet } from '../confirm';

// Форма подтверждения: видимое поле с разделителями, на сервер — число; кнопка
// полной цены и скидок — готовой суммой с сервера (`Offer::bidDiscounts`: доля разницы заявленной и продажной, только
// не ниже порога). Сам порог на клиенте не известен намеренно.
// Можно в гараж — отправка сначала спрашивает, куда берёт (шторка `x-offer.bid-choice`): «Для покупателя за N ₽» или
// «В гараж на подготовку» (цена не нужна). Enter в поле тоже ведёт в шторку. «Для покупателя» жмётся и без цены
// (12.11.2026, Сахиб: серая кнопка без объяснений): тогда второй панелью шторки — шаг «Цена для покупателя» с ценой машины,
// чипами и своим полем (`ask`), оно и поле формы — одно значение.
// Админ (staff) подтверждает за менеджера: шторка всегда, сверху «Менеджер» — без него кнопки выключены.
// Зона цены (26.10.2026, `Offer::bidZone`) — только при отправке, вопросом серверу (check): ниже порога — «Вы уверены?»
// янтарным листом и `low_ok`, недопустимая — поле красным с тряской, на сервер не уходит. На лету не спрашиваем — порог
// не нащупать вводом.
export default class extends Controller {
    static targets = ['display', 'amount', 'submit', 'choice', 'buyer', 'sum', 'garage', 'low', 'error', 'choicePane', 'pricePane', 'ask', 'askError', 'askSubmit'];
    static values = { asking: Number, garage: Boolean, staff: Boolean, check: String };

    connect() {
        this.update();
    }

    set(event) {
        this.write(Number(event.params.amount));
    }

    // Цена целая, а клавиатура с запятой (inputmode="decimal") даёт набрать «150 000,5». Копейки видны, пока человек
    // в поле (иначе следующая цифра приклеилась бы к рублям: 1 500 005), на сервер не идут и уходят при выходе из
    // поля. Разделитель — запятая; точка — только одна и не перед тремя цифрами («1.500.000» из буфера — разряды).
    split(text) {
        let at = text.indexOf(',');
        if (at < 0 && text.split('.').length === 2 && !/\.\d{3}/.test(text)) at = text.indexOf('.');
        if (at < 0) return [text.replace(/\D/g, ''), null];
        return [text.slice(0, at).replace(/\D/g, ''), text.slice(at + 1).replace(/\D/g, '').slice(0, 2)];
    }

    input() {
        const [rubs, kop] = this.split(this.displayTarget.value);
        const whole = rubs ? nums(Number(rubs)) : '';
        this.displayTarget.value = kop === null ? whole : `${whole || '0'},${kop}`;
        this.update();
    }

    displayTargetConnected(el) {
        this.onBlur ??= () => this.trim();
        el.addEventListener('blur', this.onBlur);
    }

    displayTargetDisconnected(el) {
        el.removeEventListener('blur', this.onBlur);
    }

    trim() {
        const [rubs, kop] = this.split(this.displayTarget.value);
        if (kop === null) return;
        this.displayTarget.value = Number(rubs) ? nums(Number(rubs)) : '';
        this.update();
    }

    // Кнопка цены или скидки — как ввод руками: change снимает ошибку «Укажите цену» (formCheck в app.js).
    write(value) {
        this.displayTarget.value = value ? nums(value) : '';
        this.update();
        this.displayTarget.dispatchEvent(new Event('change', { bubbles: true }));
    }

    update() {
        this.refuse(null);
        const value = Number(this.split(this.displayTarget.value)[0]) || 0;
        this.amountTarget.value = value || '';
        if (this.hasSubmitTarget) this.submitTarget.disabled = !value && !this.garageValue;
        const noManager = this.staffValue && !this.element.querySelector('input[name="manager_id"]')?.value;
        // Без цены «Для покупателя» не выключается — ведёт на шаг цены (`buyer`).
        if (this.hasBuyerTarget) this.buyerTarget.disabled = noManager;
        if (this.hasAskSubmitTarget) this.askSubmitTarget.disabled = noManager;
        if (this.hasGarageTarget) this.garageTarget.disabled = noManager;
        this.sumTargets.forEach((sum) => {
            sum.textContent = value ? ` за ${rub(value)}` : '';
            sum.hidden = !value;
        });
        if (this.hasAskTarget && document.activeElement !== this.askTarget) this.askTarget.value = this.displayTarget.value;
        if (value) this.askRefuse(null);
    }

    // «Для покупателя» в шторке: цены нет — шаг «Цена для покупателя» вместо отправки.
    buyer(event) {
        if (this.amountTarget.value || !this.hasPricePaneTarget) return;
        event.preventDefault();
        this.showPrice();
    }

    showPrice() {
        this.choicePaneTarget.hidden = true;
        this.pricePaneTarget.hidden = false;
        this.title('Цена для покупателя');
        this.askTarget.value = this.displayTarget.value;
        this.askTarget.focus({ preventScroll: true });
    }

    // Шторку закрыли — в следующий раз снова выбор «куда».
    showChoice() {
        if (!this.hasPricePaneTarget) return;
        this.pricePaneTarget.hidden = true;
        this.choicePaneTarget.hidden = false;
        this.title(this.choiceTarget.dataset.title);
        this.askRefuse(null);
    }

    title(text) {
        const h = this.choiceTarget.querySelector('.sheet-bar h2');
        if (h && text) h.textContent = text;
    }

    choiceTargetConnected(el) {
        this.onChoiceClose ??= () => this.showChoice();
        el.querySelector('dialog')?.addEventListener('close', this.onChoiceClose);
    }

    choiceTargetDisconnected(el) {
        el.querySelector('dialog')?.removeEventListener('close', this.onChoiceClose);
    }

    // Поле шага — то же значение, что поле формы: пишем туда и считаем как ввод руками.
    askInput() {
        this.displayTarget.value = this.askTarget.value;
        this.input();
        this.askTarget.value = this.displayTarget.value;
        this.displayTarget.dispatchEvent(new Event('change', { bubbles: true }));
    }

    get pricing() {
        return this.hasPricePaneTarget && !this.pricePaneTarget.hidden && !!this.choiceTarget.querySelector('dialog')?.open;
    }

    askRefuse(message) {
        if (!this.hasAskErrorTarget) return;
        this.askErrorTarget.textContent = message || '';
        this.askErrorTarget.hidden = !message;
        if (message) {
            this.askTarget.setAttribute('aria-invalid', 'true');
            this.askTarget.classList.remove('is-shaking');
            void this.askTarget.offsetWidth;
            this.askTarget.classList.add('is-shaking');
            this.askTarget.focus({ preventScroll: true });
        } else if (this.askTarget.hasAttribute('aria-invalid')) {
            this.askTarget.removeAttribute('aria-invalid');
        }
    }

    // Отправка мимо шторки (кнопка, Enter в поле) — сначала спросить, куда; цену для покупателя — сверить с зоной.
    guard(event) {
        if (this.passing) return;
        // Шаг «Цена для покупателя»: Enter в его поле отправляет первой кнопкой формы — это тоже «для покупателя».
        if (this.pricing && event.submitter?.value !== 'garage') {
            event.preventDefault();
            if (!this.amountTarget.value) return this.askRefuse('Укажите цену');
            if (!this.checkValue) { this.passing = true; this.element.requestSubmit(this.askSubmitTarget); this.passing = false; return; }
            this.verify(this.askSubmitTarget);
            return;
        }
        if ((this.garageValue || this.staffValue) && this.hasChoiceTarget && !this.choiceTarget.querySelector('dialog')?.open) {
            event.preventDefault();
            this.ask();
            return;
        }
        if (!this.checkValue || event.submitter?.value === 'garage' || !this.amountTarget.value) return;
        event.preventDefault();
        this.verify(event.submitter);
    }

    async verify(submitter) {
        if (this.busy) return;
        this.busy = true;
        try {
            const zone = await this.zone();
            // На шаге цены ответ зоны — у его поля, шторка остаётся. Иначе шторка «куда берёт» закрывала бы ошибку под
            // собой — ответ зоны рисуем уже без неё.
            const pricing = this.pricing;
            if (zone === 'bad' && pricing) return this.askRefuse('Цена недопустимая');
            if (zone !== 'ok' && !pricing && this.hasChoiceTarget && this.choiceTarget.querySelector('dialog')?.open) {
                this.application.getControllerForElementAndIdentifier(this.choiceTarget, 'sheet')?.close();
            }
            if (zone === 'bad') return this.refuse('Цена недопустимая');
            if (zone === 'low' || zone === 'high') {
                // Вдвое выше цены продажи — почти всегда лишний ноль (02.11.2026): переспрашиваем сумму.
                const sure = zone === 'high'
                    ? await confirmSheet('Проверьте сумму', { text: 'Цена больше чем вдвое выше цены продажи', label: 'Всё верно', warn: true, danger: false })
                    : await confirmSheet('Вы уверены?', { text: 'Такая цена требует дополнительного согласования с поставщиком', label: 'Уверен', warn: true, danger: false });
                if (!sure) return;
                this.lowTarget.value = '1';
            }
            this.passing = true;
            this.element.requestSubmit(submitter && this.element.contains(submitter) ? submitter : null);
        } finally {
            this.passing = false;
            this.busy = false;
        }
    }

    // Не ответил сервер (сеть, частые попытки) — отправляем как есть: решит store.
    async zone() {
        try {
            const token = this.element.querySelector('input[name="_token"]')?.value;
            const res = await fetch(this.checkValue, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': token }, body: JSON.stringify({ amount: this.amountTarget.value }) });
            return res.ok ? (await res.json()).zone : 'ok';
        } catch {
            return 'ok';
        }
    }

    refuse(message) {
        if (!this.hasErrorTarget) return;
        this.errorTarget.textContent = message || '';
        this.errorTarget.hidden = !message;
        if (message) {
            this.displayTarget.setAttribute('aria-invalid', 'true');
            this.displayTarget.classList.remove('is-shaking');
            void this.displayTarget.offsetWidth;
            this.displayTarget.classList.add('is-shaking');
        } else if (this.displayTarget.hasAttribute('aria-invalid')) {
            this.displayTarget.removeAttribute('aria-invalid');
        }
    }

    ask() {
        this.application.getControllerForElementAndIdentifier(this.choiceTarget, 'sheet')?.open();
    }
}
