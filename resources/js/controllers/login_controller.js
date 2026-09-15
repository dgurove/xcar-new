import { Controller } from '@hotwired/stimulus';

// Форма приглашения: логин подсказывается транслитом из имени, пока человек его
// не трогал; выбранное фото сразу встаёт в кружок.
const MAP = {
    а: 'a', б: 'b', в: 'v', г: 'g', д: 'd', е: 'e', ё: 'e', ж: 'zh', з: 'z', и: 'i', й: 'y', к: 'k', л: 'l', м: 'm',
    н: 'n', о: 'o', п: 'p', р: 'r', с: 's', т: 't', у: 'u', ф: 'f', х: 'h', ц: 'ts', ч: 'ch', ш: 'sh', щ: 'sch',
    ъ: '', ы: 'y', ь: '', э: 'e', ю: 'yu', я: 'ya',
};

export default class extends Controller {
    static targets = ['name', 'login', 'avatar'];

    connect() {
        this.dirty = this.loginTarget.value !== '';
    }

    touched() {
        this.dirty = this.loginTarget.value !== '';
        this.loginTarget.value = this.loginTarget.value.toLowerCase();
    }

    suggest() {
        if (this.dirty) return;
        const parts = this.nameTarget.value.trim().toLowerCase().split(/\s+/).filter(Boolean).slice(0, 2);
        const latin = parts.map((p) => [...p].map((c) => MAP[c] ?? (/[a-z0-9]/.test(c) ? c : '')).join('')).filter(Boolean);
        this.loginTarget.value = latin.join('.').slice(0, 32);
    }

    preview(event) {
        const file = event.target.files?.[0];
        if (!file || !this.hasAvatarTarget) return;
        const url = URL.createObjectURL(file);
        this.avatarTarget.innerHTML = `<img src="${url}" alt="" width="56" height="56">`;
    }
}
