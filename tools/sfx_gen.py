"""
Sound effect bawaan library SFX BisaClip (disintesis prosedural, bebas lisensi).

Hasil di folder tujuan: <key>.wav (48 kHz mono 16-bit) + manifest.json [{file, name, dur}].
Dipanggil sekali oleh sfx_library() di editor_lib.php:
  python sfx_gen.py --out previews/sfx
"""
import argparse
import json
import os
import wave

import numpy as np

SR = 48000
rng = np.random.default_rng(11)


def band(n, lo, hi):
    """Noise putih yang disaring ke rentang frekuensi lo..hi Hz (lewat FFT)."""
    spec = np.fft.rfft(rng.normal(0, 1, n))
    f = np.fft.rfftfreq(n, 1 / SR)
    spec[(f < lo) | (f > hi)] = 0
    out = np.fft.irfft(spec, n)
    return out / (np.abs(out).max() + 1e-9)


def grains(n, density, lo, hi, dec=(0.002, 0.008), amp=(0.2, 1.0), env=None):
    """Kresek kertas: butiran noise pendek yang muncul acak (density = butir per detik, boleh array per sampel)."""
    out = np.zeros(n)
    dens = np.broadcast_to(np.asarray(density, float), (n,))
    src = band(n + SR, lo, hi)
    pos = 0
    while pos < n:
        d = max(dens[pos], 1.0)
        pos += int(rng.exponential(SR / d)) + 1
        if pos >= n:
            break
        ln = int(SR * rng.uniform(*dec) * 5)
        t = np.arange(min(ln, n - pos)) / SR
        g = src[pos:pos + len(t)] * np.exp(-t / rng.uniform(*dec)) * rng.uniform(*amp)
        out[pos:pos + len(t)] += g * (env[pos] if env is not None else 1)
    return out


def fade(x, a=0.004, b=0.03):
    n = len(x)
    e = np.ones(n)
    na, nb = int(a * SR), int(b * SR)
    if na:
        e[:na] = np.linspace(0, 1, na)
    if nb:
        e[-nb:] *= np.linspace(1, 0, nb)
    return x * e


def sweep_noise(dur, f0, f1, f2, width=0.6):
    """Whoosh: noise yang pita frekuensinya bergeser f0 → f1 → f2 (overlap-add per frame), amplop naik-turun."""
    n = int(dur * SR)
    hop, win = 512, 2048
    out = np.zeros(n + win)
    w = np.hanning(win)
    f = np.fft.rfftfreq(win, 1 / SR)
    for i, s in enumerate(range(0, n, hop)):
        p = s / n
        fc = f0 + (f1 - f0) * (p / 0.5) if p < 0.5 else f1 + (f2 - f1) * ((p - 0.5) / 0.5)
        spec = np.fft.rfft(rng.normal(0, 1, win)) * np.exp(-0.5 * (np.log(np.maximum(f, 20) / fc) / width) ** 2)
        out[s:s + win] += np.fft.irfft(spec, win) * w
    out = out[:n]
    t = np.linspace(0, 1, n)
    return out * np.sin(np.pi * t) ** 1.6


def paper_rip():
    # 0–0,3 dtk: kertas diremas jadi gumpalan; 0,3–0,8 dtk: kertas robek (kresek rapat + desis "srrrek")
    n = int(0.85 * SR)
    t = np.arange(n) / SR
    crumple = grains(int(0.32 * SR), 70, 1800, 9000, amp=(0.15, 0.7))
    rip_n = n - int(0.3 * SR)
    tr = np.arange(rip_n) / SR
    env = np.clip(tr / 0.06, 0, 1) * np.exp(-np.clip(tr - 0.22, 0, None) / 0.09)
    dens = 250 + 900 * env
    rip = grains(rip_n, dens, 1500, 11000, dec=(0.0008, 0.004), amp=(0.3, 1.0), env=env)
    # Desis robekan dimodulasi getaran acak 40–90 Hz supaya terdengar "srrrek", bukan desis halus
    am = 0.55 + 0.45 * np.sin(2 * np.pi * np.cumsum(rng.uniform(40, 90, rip_n)) / SR)
    rip += band(rip_n, 2500, 10000) * env * am * 0.35
    out = np.zeros(n)
    out[:len(crumple)] += crumple * 0.6
    out[int(0.3 * SR):] += rip
    out += band(n, 300, 2500) * 0.03 * np.exp(-t / 0.5)
    return fade(out, 0.002, 0.05)


def paper_crumple():
    n = int(0.7 * SR)
    t = np.arange(n) / SR
    env = 0.4 + 0.6 * np.abs(np.sin(np.pi * t / 0.7 * 2.5))
    out = grains(n, 140 * env, 1500, 10000, amp=(0.1, 0.8))
    return fade(out, 0.002, 0.08)


def pop():
    n = int(0.14 * SR)
    t = np.arange(n) / SR
    f = 950 * np.exp(-t / 0.03) + 260
    out = np.sin(2 * np.pi * np.cumsum(f) / SR) * np.exp(-t / 0.035)
    out[:int(0.002 * SR)] += rng.normal(0, 0.4, int(0.002 * SR))
    return fade(out, 0.0005, 0.01)


def ding():
    n = int(1.6 * SR)
    t = np.arange(n) / SR
    out = sum(a * np.sin(2 * np.pi * 1320 * k * t) * np.exp(-t / d) for k, a, d in ((1, 1, 0.6), (2.76, 0.45, 0.25), (5.4, 0.25, 0.12), (8.9, 0.1, 0.06)))
    return fade(out, 0.001, 0.1)


def boom():
    n = int(1.3 * SR)
    t = np.arange(n) / SR
    f = 62 * np.exp(-t / 0.4) + 38
    out = np.sin(2 * np.pi * np.cumsum(f) / SR) * np.exp(-t / 0.45)
    out += band(n, 30, 400) * np.exp(-t / 0.12) * 0.5
    return fade(out, 0.002, 0.15)


def click():
    n = int(0.05 * SR)
    t = np.arange(n) / SR
    out = band(n, 2000, 9000) * np.exp(-t / 0.004) + np.sin(2 * np.pi * 1800 * t) * np.exp(-t / 0.006) * 0.5
    return fade(out, 0.0002, 0.01)


SOUNDS = [
    ('paper_rip', 'Kertas robek', paper_rip),
    ('paper_crumple', 'Kertas diremas', paper_crumple),
    ('whoosh', 'Whoosh', lambda: fade(sweep_noise(0.75, 350, 1800, 500))),
    ('swoosh', 'Swoosh cepat', lambda: fade(sweep_noise(0.35, 900, 4500, 1500, 0.5))),
    ('pop', 'Pop', pop),
    ('ding', 'Ding', ding),
    ('boom', 'Boom', boom),
    ('click', 'Klik', click),
]


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--out', required=True)
    out = ap.parse_args().out
    os.makedirs(out, exist_ok=True)
    manifest = []
    for key, name, fn in SOUNDS:
        x = fn()
        x = x / (np.abs(x).max() + 1e-9) * 0.89  # puncak ±-1 dBFS
        path = os.path.join(out, f'{key}.wav')
        with wave.open(path, 'wb') as w:
            w.setnchannels(1)
            w.setsampwidth(2)
            w.setframerate(SR)
            w.writeframes((x * 32767).astype('<i2').tobytes())
        os.chmod(path, 0o666)
        manifest.append({'file': f'{key}.wav', 'name': name, 'dur': round(len(x) / SR, 3)})
    with open(os.path.join(out, 'manifest.json'), 'w') as fh:
        json.dump(manifest, fh, ensure_ascii=False)
    os.chmod(os.path.join(out, 'manifest.json'), 0o666)


if __name__ == '__main__':
    main()
