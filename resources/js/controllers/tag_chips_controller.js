import { Controller } from '@hotwired/stimulus';

// Метки в шапке редактора: выбранные в шторке галки (`tags[]`, свой цвет — style метки) сразу встают пилюлями перед
// «+», снятые уходят. Сохраняются формой оффера вместе с полями.
export default class extends Controller {
    static targets = ['chips', 'word'];

    render() {
        const boxes = [...this.element.querySelectorAll('input[name="tags[]"]')].filter((b) => b.checked);
        this.chipsTarget.replaceChildren(...boxes.map((box) => {
            const chip = document.createElement('button');
            chip.type = 'button';
            chip.className = 'pill pill-tag';
            chip.setAttribute('style', box.closest('label')?.getAttribute('style') ?? '');
            chip.dataset.action = 'sheet#open';
            chip.textContent = box.value;
            return chip;
        }));
        if (this.hasWordTarget) this.wordTarget.hidden = boxes.length > 0;
    }
}
