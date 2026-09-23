import { Controller } from '@hotwired/stimulus';

// Лестница прайса: ступень по стоимости и по суткам есть только у хранения и негабарита,
// километры в фиксе — только у эвакуации; лишние поля прячутся вместе с подписью во всех
// ступенях, включая добавленную кнопкой (targetConnected).
export default class extends Controller {
    static targets = ['service', 'value', 'tier', 'km'];

    connect() {
        this.sync();
    }

    sync() {
        this.valueTargets.forEach((i) => this.show(i, this.tiered));
        this.tierTargets.forEach((i) => this.show(i, this.tiered));
        this.kmTargets.forEach((i) => this.show(i, this.service === 'tow'));
    }

    valueTargetConnected(input) {
        this.show(input, this.tiered);
    }

    tierTargetConnected(input) {
        this.show(input, this.tiered);
    }

    kmTargetConnected(input) {
        this.show(input, this.service === 'tow');
    }

    get service() {
        return this.hasServiceTarget ? this.serviceTarget.value : 'storage';
    }

    get tiered() {
        return this.service === 'storage' || this.service === 'oversize';
    }

    show(input, on) {
        input.closest('.field').hidden = !on;
    }
}
