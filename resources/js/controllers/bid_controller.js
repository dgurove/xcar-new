import { Controller } from '@hotwired/stimulus';
import { nums, rub } from '../lib/money';
import { confirmSheet } from '../confirm';

// Форма подтверждения: видимое поле с разделителями, на сервер — число; кнопка
// полной цены и скидок — готовой суммой с сервера (`Offer::bidDiscounts`: доля разницы заявленной и продажной, только
// не ниже порога). Сам порог на клиенте не известен намеренно.
// Можно в гараж — отправка сначала спрашивает, куда берёт (шторка `x-offer.bid-choice`): «Для покупателя за N ₽»
// (без цены выключена) или «В гараж на подготовку» (цена не нужна). Enter в поле тоже ведёт в шторку.
// Админ (staff) подтверждает за менеджера: шторка всегда, сверху «Менеджер» — без него кнопки выключены.
// Зона цены (26.10.2026, `Offer::bidZone`) — только при отправке, вопросом серверу (check): ниже порога — «Вы уверены?»
// янтарным листом и `low_ok`, недопустимая — поле красным с тряской, на сервер не уходит. На лету не спрашиваем — порог
// не нащупать вводом.
export default class extends Controller {
    static targets = ['display', 'amount', 'submit', 'choice', 'buyer', 'sum', 'garage', 'low', 'error'];
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
        if (this.hasBuyerTarget) this.buyerTarget.disabled = !value || noManager;
        if (this.hasGarageTarget) this.garageTarget.disabled = noManager;
        if (this.hasSumTarget) {
            this.sumTarget.textContent = value ? ` за ${rub(value)}` : '';
            this.sumTarget.hidden = !value;
        }
    }

    // Отправка мимо шторки (кнопка, Enter в поле) — сначала спросить, куда; цену для покупателя — сверить с зоной.
    guard(event) {
        if (this.passing) return;
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
            // Шторка «куда берёт» закрывала бы ошибку под собой — ответ зоны рисуем уже без неё.
            if (zone !== 'ok' && this.hasChoiceTarget && this.choiceTarget.querySelector('dialog')?.open) {
                this.application.getControllerForElementAndIdentifier(this.choiceTarget, 'sheet')?.close();
            }
            if (zone === 'bad') return this.refuse('Цена недопустимая');
            if (zone === 'low') {
                const sure = await confirmSheet('Вы уверены?', { text: 'Такая цена требует дополнительного согласования с поставщиком', label: 'Уверен', warn: true, danger: false });
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
