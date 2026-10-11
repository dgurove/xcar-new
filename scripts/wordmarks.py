"""Надписи PARK и LOGISTICS для шапки телефона — в геометрии надписи CRM: высота 334, штрих ~90, трафаретный разрез
как у R (P и R взяты из crm.svg). Пересобрать: python3 scripts/wordmarks.py public/images"""
import sys

H = 334
GAP = 34
LIME = '#97BF0D'


def f(v):
    return ('%.2f' % v).rstrip('0').rstrip('.')


def P(x):
    # R из crm.svg без ноги (R начинается в x=501.93, верх y=583).
    d = ('M782.595 583C847.832 583 900.719 635.886 900.719 701.124C900.719 766.362 847.832 819.248 782.595 819.248'
         'H596.132V916.896H501.929V787.57C501.928 756.354 527.234 731.049 558.45 731.049H779.208'
         'C795.444 731.048 808.607 717.886 808.607 701.649C808.607 685.413 795.445 672.249 779.208 672.249H501.926V583Z')
    return d, 501.93, 900.72


def R(x):
    d = ('M782.595 583C847.832 583 900.719 635.886 900.719 701.124C900.719 760.735 856.561 810.033 799.172 818.093'
         'L903.865 916.896H777.214L673.745 819.248H596.132V916.896H501.929V819.248H501.926V802.447H501.928V787.57'
         'C501.928 756.354 527.234 731.049 558.45 731.049H779.208C795.444 731.048 808.607 717.886 808.607 701.649'
         'C808.607 685.413 795.445 672.249 779.208 672.249H501.926V583H782.595Z')
    return d, 501.93, 903.87


def shift(d, dx, dy):
    """Сдвиг пути из абсолютных команд M L H V C A Z."""
    import re
    out, cmd, nums = [], None, []
    tokens = re.findall(r'[MLHVCAZ]|-?\d*\.?\d+(?:e-?\d+)?', d)
    i = 0
    res = ''
    while i < len(tokens):
        t = tokens[i]
        if t.isalpha():
            cmd = t
            res += t
            i += 1
            continue
        if cmd in 'ML':
            res += f(float(tokens[i]) + dx) + ' ' + f(float(tokens[i + 1]) + dy) + ' '
            i += 2
        elif cmd == 'H':
            res += f(float(t) + dx) + ' '
            i += 1
        elif cmd == 'V':
            res += f(float(t) + dy) + ' '
            i += 1
        elif cmd == 'C':
            for k in range(3):
                res += f(float(tokens[i]) + dx) + ' ' + f(float(tokens[i + 1]) + dy) + ' '
                i += 2
        elif cmd == 'A':
            res += ' '.join(tokens[i:i + 5]) + ' ' + f(float(tokens[i + 5]) + dx) + ' ' + f(float(tokens[i + 6]) + dy) + ' '
            i += 7
    return res.strip()


# Буквы в своих координатах: x от 0, y от 0 (верх) до 334.
def A(w=330):
    r = w / 2
    ri = r - 92
    return (f'M0 334V{f(r)}A{f(r)} {f(r)} 0 0 1 {f(w)} {f(r)}V334H{f(w-92)}V254H92V334Z'
            f'M92 {f(r)}V194H{f(w-92)}V{f(r)}A{f(ri)} {f(ri)} 0 0 0 92 {f(r)}Z'), w


def K(w=310):
    return (f'M0 0H92V334H0Z'
            f'M112 167L{f(w-118)} 0H{f(w)}L{f(w-104)} 167L{f(w)} 334H{f(w-118)}Z'), w


def L(w=250):
    return f'M0 0H92V242H{f(w)}V334H0Z', w


def O(w=334):
    r, ri = 167, 75
    return (f'M{f(r)} 0H{f(w-r)}A{r} {r} 0 0 1 {f(w-r)} 334H{f(r)}A{r} {r} 0 0 1 {f(r)} 0Z'
            f'M{f(r)} 92A{ri} {ri} 0 0 0 {f(r)} 242H{f(w-r)}A{ri} {ri} 0 0 0 {f(w-r)} 92Z'), w


def C(w=330):
    return (f'M{f(w)} 0H167A167 167 0 0 0 167 334H{f(w)}V242H167A75 75 0 0 1 167 92H{f(w)}Z'), w


def G(w=340):
    return (f'M{f(w)} 0H167A167 167 0 0 0 167 334H{f(w)}V150H{f(w-150)}V242H167A75 75 0 0 1 167 92H{f(w)}Z'), w


def I():
    return 'M0 0H92V334H0Z', 92


def S(w=300):
    b = 86
    m0, m1 = (334 - b) / 2, (334 + b) / 2
    R1 = m1 / 2
    ri = R1 - b
    up = (f'M{f(w)} 0H{f(R1)}A{f(R1)} {f(R1)} 0 0 0 {f(R1)} {f(m1)}H{f(w-R1)}V{f(m0)}H{f(R1)}'
          f'A{f(ri)} {f(ri)} 0 0 1 {f(R1)} {f(b)}H{f(w)}Z')
    lo = (f'M0 334H{f(w-R1)}A{f(R1)} {f(R1)} 0 0 0 {f(w-R1)} {f(m0)}H{f(R1)}V{f(m1)}H{f(w-R1)}'
          f'A{f(ri)} {f(ri)} 0 0 1 {f(w-R1)} {f(334-b)}H0Z')
    return up + lo, w


def T(w=300):
    s = (w - 92) / 2
    return f'M0 0H{f(w)}V92H{f(w-s)}V334H{f(s)}V92H0Z', w


def build(word, lime_index, fill):
    x = 0
    parts = []
    for i, ch in enumerate(word):
        color = LIME if i == lime_index else fill
        if ch in 'PR':
            d, x0, x1 = (P if ch == 'P' else R)(0)
            parts.append(f'<path d="{shift(d, x - x0, -583)}" fill="{color}"/>')
            x += x1 - x0
        else:
            d, w = {'A': A, 'K': K, 'L': L, 'O': O, 'C': C, 'G': G, 'I': I, 'S': S, 'T': T}[ch]()
            parts.append(f'<g transform="translate({f(x)} 0)"><path d="{d}" fill="{color}"/></g>')
            x += w
        x += GAP
    x -= GAP
    w = round(x)
    return (f'<svg width="{w}" height="{H}" viewBox="0 0 {w} {H}" fill="none" xmlns="http://www.w3.org/2000/svg">\n'
            + '\n'.join(parts) + '\n</svg>\n')


if __name__ == '__main__':
    out = sys.argv[1]
    for name, word, lime in [('park', 'PARK', 2), ('logistics', 'LOGISTICS', 3)]:
        for suffix, fill in [('', 'black'), ('-white', 'white')]:
            with open(f'{out}/{name}-wide{suffix}.svg', 'w') as fh:
                fh.write(build(word, lime, fill))
