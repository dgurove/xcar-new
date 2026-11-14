import { Controller } from '@hotwired/stimulus';

// Радио открывает свою группу полей (data-reveal-key = value радио, можно несколько через пробел), остальные прячет
// и выключает, чтобы их значения не ушли в форму. С labelSelector кнопка (обычно в плашке,
// по селектору) получает подпись выбранного исхода — data-reveal-label у радио.
// Вложенный reveal (способ оплаты → кто платит) ведёт свои панели сам: чужие цели здесь не трогаются.
// После morph-визита (форма с ошибкой вернулась на тот же адрес) контроллер не переподключается,
// а серверные hidden и disabled ложатся поверх — поэтому раскладка пересчитывается по turbo:morph.
export default class extends Controller {
    static targets = ['pane'];
    static values = { labelSelector: String };

    connect() {
        this.sync();
        this.onMorph = () => this.sync();
        document.addEventListener('turbo:morph', this.onMorph);
    }

    disconnect() {
        document.removeEventListener('turbo:morph', this.onMorph);
    }

    sync() {
        const checked = [...this.element.querySelectorAll('input[type=radio][data-action*="reveal#pick"]:checked')].find((r) => this.owns(r));
        if (checked) this.show(checked.value, checked.dataset.revealLabel);
        const select = [...this.element.querySelectorAll('select[data-action*="reveal#choose"]')].find((s) => this.owns(s));
        if (select) this.show(select.value);
        const box = [...this.element.querySelectorAll('input[type=checkbox][data-action*="reveal#check"]')].find((c) => this.owns(c));
        if (box) this.show(box.checked ? '' : box.dataset.revealKeyParam);
    }

    // Галка «совпадает» прячет свою панель, снятая — открывает (`data-reveal-key-param`): «Продаём там же» → «Город продажи».
    check(event) {
        this.show(event.currentTarget.checked ? '' : event.params.key);
    }

    // Выбор списком открывает панель своего значения («Откуда срок» этапа: свой — «Сколько ждём», срок приёма — дни приёма).
    choose(event) {
        this.show(event.currentTarget.value);
    }

    owns(el) {
        return el.closest('[data-controller~="reveal"]') === this.element;
    }

    pick(event) {
        this.show(event.currentTarget.value, event.currentTarget.dataset.revealLabel);
    }

    // Выбор в списке открывает панели ключа (`data-reveal-key-param`), пока выбрано непустое: «Кто вывозит» — менеджер,
    // и тогда есть «К менеджеру». Спрятанное радио, если было выбрано, уступает соседнему.
    toggle(event) {
        const on = event.currentTarget.value !== '';
        for (const pane of this.paneTargets.filter((p) => this.owns(p) && this.keys(p).includes(event.params.key))) {
            pane.hidden = !on;
            const radio = pane.querySelector('input[type=radio]');
            if (!on && radio?.checked) {
                radio.checked = false;
                const next = [...this.element.querySelectorAll(`input[name="${radio.name}"]`)].find((r) => r !== radio);
                if (next) next.checked = true;
            }
        }
    }

    // Панель бывает общей нескольким значениям — ключи через пробел (`person ip`: скан паспорта у физлица и ИП).
    keys(pane) {
        return (pane.dataset.revealKey || '').split(/\s+/);
    }

    show(key, label) {
        for (const pane of this.paneTargets.filter((p) => this.owns(p))) {
            const on = this.keys(pane).includes(key);
            pane.hidden = !on;
            // Поля скрытой панели — своей, вложенной или внешнего reveal — так и остаются выключенными. Прочее скрытое
            // (список, шторка, плашка, что прячет и показывает другой контроллер) reveal не трогает: выключенным оно
            // оставалось навсегда — шторка «Назначить (N)» логистики не отдавала логиста, поле вендора в «Ценах», а
            // 12.11.2026 — галочки менеджеров в «Показе» окна (список выбора «Кому» скрыт до «Выбранным»; начальник
            // опубликовал «всем», потому что не смог отметить никого).
            // Включает обратно только своё (`data-reveal-off`) и видимое; скрытое чужим контроллером выключенным (оценка
            // менеджеров: `declared[…]` у неотмеченных) так и остаётся.
            for (const field of pane.querySelectorAll('input, select, textarea')) {
                if (!on || field.closest('[data-reveal-target~="pane"][hidden]')) {
                    if (!field.disabled) field.dataset.revealOff = '';
                    field.disabled = true;
                } else if ('revealOff' in field.dataset || !field.closest('[hidden]')) {
                    delete field.dataset.revealOff;
                    field.disabled = false;
                }
            }
        }
        if (label && this.labelSelectorValue) {
            const button = document.querySelector(this.labelSelectorValue);
            if (button) button.textContent = label;
        }
    }
}
