import { Controller } from '@hotwired/stimulus';

// «Продано» / «Оплачено» во «Все» (`x-deal.dates-sheet`): ячейка — кнопка с `data-deal-dates` (сделка, что, дата, машина),
// нажатие ловится здесь одним слушателем на документ (в строках своих контроллеров нет), шторка заполняется и открывается.
const TITLES = { sold: 'Продано', paid: 'Оплачено поставщику' };
const HINTS = {
    sold: 'Когда нам за неё заплатили. Наши счета по сделке, если не оплачены, отметятся оплаченными этой датой',
    paid: 'Когда мы заплатили поставщику. Сделка ждёт этой оплаты — шаг пройдёт; «Очистить» у закрытой вернёт её на оплату поставщику',
};

export default class extends Controller {
    static targets = ['form', 'what', 'car', 'hint'];

    connect() {
        this.onClick = (event) => {
            const cell = event.target.closest?.('[data-deal-dates]');
            if (!cell) return;
            event.preventDefault();
            event.stopPropagation();
            this.open(cell.dataset);
        };
        document.addEventListener('click', this.onClick, true);
    }

    disconnect() {
        document.removeEventListener('click', this.onClick, true);
    }

    open({ dealDates: deal, what, value, car }) {
        this.formTarget.action = `/work/deals/${deal}/dates`;
        this.whatTarget.value = what;
        this.carTarget.textContent = car || '';
        this.hintTarget.textContent = HINTS[what] || '';
        const title = this.element.querySelector('dialog h2');
        if (title) title.textContent = TITLES[what] || 'Дата';
        const date = this.element.querySelector('[data-controller~="date"]');
        const input = date?.querySelector('input[type=hidden]');
        if (input) input.value = value || new Date().toISOString().slice(0, 10);
        this.application.getControllerForElementAndIdentifier(date, 'date')?.render?.();
        this.application.getControllerForElementAndIdentifier(this.element, 'sheet')?.open();
    }
}
