// Картинка аватара или логотипа вендора не открылась (файла нет, сеть) — вместо значка битой картинки заглушка:
// у аватара — инициалы под картинкой (`x-ui.avatar` рисует их всегда), у логотипа — первая буква имени (`data-letter`).
const drop = (img) => {
    const box = img.parentElement;
    if (box?.classList.contains('avatar')) {
        img.remove();
    } else if (box?.classList.contains('vendor-logo')) {
        const letter = document.createElement('span');
        letter.textContent = img.dataset.letter || '·';
        box.classList.add('is-letter');
        img.replaceWith(letter);
    }
};

const SELECTOR = '.avatar > img, .vendor-logo > img';

export default function avatarFallback() {
    addEventListener('error', (e) => {
        if (e.target instanceof HTMLImageElement && e.target.matches(SELECTOR)) drop(e.target);
    }, true);
    const sweep = () => document.querySelectorAll(SELECTOR).forEach((img) => {
        if (img.complete && img.naturalWidth === 0) drop(img);
    });
    sweep();
    document.addEventListener('turbo:load', sweep);
    document.addEventListener('turbo:frame-load', sweep);
}
