// Человек просил меньше движения (системная настройка) — анимации и плавная прокрутка мгновенные. Одна проверка вместо
// шестнадцати своих matchMedia.
const query = matchMedia('(prefers-reduced-motion: reduce)');
export const reducedMotion = () => query.matches;
export const scrollBehavior = () => (query.matches ? 'auto' : 'smooth');
