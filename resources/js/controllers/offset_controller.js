import { Controller } from '@hotwired/stimulus';

// Взаимозачёт со страховой на лету (`x-deal.offset-sheet`, 23.11.2026): закупочная минус «Собственнику по ДКП»; больше
// закупочной — словом красным (сервер такое не примет).
export default class extends Controller {
    static targets = ['out'];
    static values = { cost: Number };

    calc() {
        const input = this.element.querySelector('input[name="owner_price"]');
        if (!input) return;
        const owner = Number(String(input.value).replace(/[^\d]/g, '')) || 0;
        const left = this.costValue - owner;
        this.outTarget.classList.toggle('text-danger', left < 0);
        this.outTarget.textContent = left < 0 ? 'больше закупочной' : `${left.toLocaleString('ru-RU').replace(/\s/g, ' ')} ₽`;
    }
}
