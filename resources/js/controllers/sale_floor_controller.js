import { Controller } from '@hotwired/stimulus';

// Шторка «В продажу» на парковке (13.10.2026): оценочная правится, закупочная — по правилу от неё, равна ей или своя;
// суммы в строках выбора и кнопка «Выставить за …» пересчитываются на лету. Правило — зеркало `Park\Sale::floorFrom`,
// менять вместе.
export default class extends Controller {
    static targets = ['value', 'own', 'rule', 'same', 'button'];

    connect() {
        this.update();
    }

    update() {
        const value = this.rubles(this.valueTarget.value);
        const rule = value ? floorFrom(value) : null;
        this.ruleTarget.textContent = rule ? `${money(rule)} (${markup(value)})` : 'нет оценочной';
        this.sameTarget.textContent = value ? money(value) : 'нет оценочной';
        const mode = this.element.querySelector('input[name=floor_mode]:checked')?.value;
        const floor = mode === 'rule' ? rule : mode === 'value' ? value : this.rubles(this.ownTarget.value);
        this.buttonTarget.textContent = floor ? `Выставить за ${money(floor)}` : 'Выставить в продажу';
    }

    rubles(text) {
        const n = parseInt(String(text || '').split(',')[0].replace(/\D+/g, ''), 10);
        return n > 0 ? n : null;
    }
}

function floorFrom(value) {
    const price = value < 500000 ? value + 50000 : value < 2000000 ? value * 1.1 : value + 200000;
    return Math.ceil(Math.round(price * 100) / 100 / 1000) * 1000;
}

function markup(value) {
    return value < 500000 ? '+50 000 ₽' : value < 2000000 ? '+10%' : '+200 000 ₽';
}

function money(n) {
    return `${n.toLocaleString('ru-RU').replace(/\s/g, ' ')} ₽`;
}
