import { Controller } from '@hotwired/stimulus';

// Своя метка у предложения: «+» превращается в поле, Enter или уход из поля — отмеченный чип перед «+».
// Такая метка уже есть — её просто отмечаем. change с чипа подхватывает автосохранение окошка.
export default class extends Controller {
    static targets = ['button', 'input'];

    open() {
        this.buttonTarget.hidden = true;
        this.inputTarget.hidden = false;
        this.inputTarget.focus();
    }

    add() {
        const name = this.inputTarget.value.trim().replace(/\s+/g, ' ');
        this.close();
        if (!name) return;
        const boxes = [...this.element.querySelectorAll('input[name="tags[]"]')];
        let box = boxes.find((b) => b.value.toLowerCase() === name.toLowerCase());
        if (!box) {
            const label = document.createElement('label');
            label.className = 'choice';
            box = Object.assign(document.createElement('input'), { type: 'checkbox', name: 'tags[]', value: name });
            const text = document.createElement('span');
            text.textContent = name;
            label.append(box, text);
            this.buttonTarget.before(label);
        }
        if (box.checked) return;
        box.checked = true;
        box.dispatchEvent(new Event('change', { bubbles: true }));
    }

    close() {
        this.inputTarget.value = '';
        this.inputTarget.hidden = true;
        this.buttonTarget.hidden = false;
    }
}
