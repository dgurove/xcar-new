import { Controller } from '@hotwired/stimulus';

// Несколько городов одним полем: поиск — обычный combobox, а выбранный им город (скрытое поле меняет значение)
// переезжает в чип с полем cities[]. Свой поиск не пишем — combobox уже умеет справочник.
export default class extends Controller {
    static targets = ['chips'];

    add(event) {
        const hidden = event.target;
        if (!hidden.matches?.('[data-combobox-target="hidden"]') || !hidden.value) return;
        const input = this.element.querySelector('[data-combobox-target="input"]');
        const id = hidden.value;
        const label = input.value;
        if (!this.chipsTarget.querySelector(`[data-id="${id}"]`)) this.chipsTarget.append(this.chip(id, label));
        hidden.value = '';
        input.value = '';
    }

    remove(event) {
        event.currentTarget.closest('[data-id]').remove();
    }

    chip(id, label) {
        const chip = document.createElement('span');
        chip.className = 'chip';
        chip.dataset.id = id;
        const field = document.createElement('input');
        field.type = 'hidden';
        field.name = 'cities[]';
        field.value = id;
        const button = document.createElement('button');
        button.type = 'button';
        button.className = '-mr-1 ml-1 inline-flex size-5 items-center justify-center text-ink-muted';
        button.setAttribute('aria-label', `Убрать ${label}`);
        button.dataset.action = 'city-picker#remove';
        button.textContent = '×';
        chip.append(field, document.createTextNode(label), button);

        return chip;
    }
}
