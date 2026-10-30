"""
Aset transisi "paper tear" untuk intro hook (dibuat prosedural, bebas lisensi).

Animasi 30 fps ukuran 1080x1920:
  1. gumpalan kertas muncul & membesar di tengah layar (cuplikan hook masih terlihat),
  2. kertas robek membentuk lubang bergerigi yang membesar cepat sampai keluar layar, memperlihatkan clip utama.

Hasil di folder tujuan:
  paper_NN.png : lapisan kertas RGBA (ditaruh paling atas)
  mask_NN.png  : matte abu-abu, putih = clip utama terlihat, hitam = cuplikan hook

Dipanggil sekali oleh hook_intro.php:
  python paper_tear.py --out storage/paper_v1
"""
import argparse
import os

import cv2
import numpy as np

W, H, FPS = 1080, 1920, 30
BALL_FRAMES = 8   # gumpalan muncul
TEAR_FRAMES = 11  # lubang robek membesar
CX, CY = W * 0.5, H * 0.46
rng = np.random.default_rng(7)


def periodic(n_points, scale):
    """Fungsi acak periodik pada sudut (0..2pi) dari tabel yang diinterpolasi."""
    tbl = rng.uniform(-1, 1, n_points).astype(np.float32)
    tbl = np.concatenate([tbl, tbl[:1]])

    def f(theta):
        x = (theta % (2 * np.pi)) / (2 * np.pi) * n_points
        i = np.floor(x).astype(np.int32)
        frac = x - i
        return (tbl[i] * (1 - frac) + tbl[i + 1] * frac) * scale
    return f


def harmonics(lo, hi, amp):
    ks = np.arange(lo, hi)
    a = rng.uniform(0.3, 1, len(ks)) / ks ** 0.6
    a = a / a.sum() * amp
    ph = rng.uniform(0, 2 * np.pi, len(ks))
    return lambda th: sum(a[j] * np.sin(ks[j] * th + ph[j]) for j in range(len(ks)))


def crumple_texture():
    """Tekstur kertas kusut: noise multi-skala + garis lipatan."""
    tex = np.zeros((H, W), np.float32)
    for s, amp in ((3, 5), (12, 9), (40, 12)):
        n = rng.normal(0, 1, (H // s + 2, W // s + 2)).astype(np.float32)
        tex += cv2.resize(n, (W, H), interpolation=cv2.INTER_CUBIC) * amp
    creases = np.zeros((H, W), np.float32)
    for _ in range(140):
        x0, y0 = rng.uniform(0, W), rng.uniform(0, H)
        ang, ln = rng.uniform(0, np.pi), rng.uniform(60, 320)
        x1, y1 = x0 + np.cos(ang) * ln, y0 + np.sin(ang) * ln
        cv2.line(creases, (int(x0), int(y0)), (int(x1), int(y1)), float(rng.uniform(0.5, 1)), 2, cv2.LINE_AA)
    creases = cv2.GaussianBlur(creases, (0, 0), 1.6)
    # Lipatan: sisi gelap & sisi terang berdampingan
    shifted = np.roll(creases, 3, axis=1)
    return tex - creases * 38 + shifted * 18


def ease_out(x):
    return 1 - (1 - x) ** 3


def ease_in(x):
    return x ** 1.8


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--out', required=True)
    out = ap.parse_args().out
    os.makedirs(out, exist_ok=True)

    yy, xx = np.mgrid[0:H, 0:W].astype(np.float32)
    dx, dy = xx - CX, yy - CY
    r = np.sqrt(dx * dx + dy * dy)
    th = np.arctan2(dy, dx)
    tex = crumple_texture()
    base = np.array([238, 240, 243], np.float32)  # putih kertas (BGR), sedikit dingin

    ball_shape = harmonics(2, 9, 0.16)
    ball_jag = periodic(90, 0.05)
    hole_shape = harmonics(2, 8, 0.12)
    hole_jag = periodic(260, 0.045)
    ring_shape = harmonics(2, 7, 0.25)
    ring_jag = periodic(200, 0.08)
    # Retak radial di cincin kertas: kertas terpecah jadi beberapa sobekan
    splits = rng.uniform(0, 2 * np.pi, 7)

    def paper_rgba(alpha, light):
        rgb = base[None, None, :] + tex[..., None] + light[..., None]
        img = np.dstack([np.clip(rgb, 0, 255), np.clip(alpha * 255, 0, 255)])
        return img.astype(np.uint8)

    total = BALL_FRAMES + TEAR_FRAMES
    for f in range(total):
        rot = f * 0.035
        if f < BALL_FRAMES:
            p = ease_out((f + 1) / BALL_FRAMES)
            rb = 40 + 150 * p
            edge = rb * (1 + ball_shape(th + rot) + ball_jag(th + rot))
            alpha = np.clip(edge - r + 0.5, 0, 1)
            # Bola: terang dari kiri atas, gelap di tepi
            lx, ly = (dx + rb * 0.35) / max(rb, 1), (dy + rb * 0.45) / max(rb, 1)
            light = -42 * np.clip(lx * lx + ly * ly, 0, 2.5) + 14
            shadow = np.clip(edge * 1.08 + 10 - r, 0, 1) * (1 - alpha) * 0.35
            img = paper_rgba(alpha, light)
            img[..., 3] = np.maximum(img[..., 3], (shadow * 255).astype(np.uint8))
            img[..., :3][shadow > alpha] = (img[..., :3][shadow > alpha] * 0.25).astype(np.uint8)
            mask = np.zeros((H, W), np.uint8)
        else:
            p = ease_in((f - BALL_FRAMES + 1) / TEAR_FRAMES)
            R = 120 + 1500 * p
            r_in = R * (1 + hole_shape(th + rot) + hole_jag(th * 1.0 + rot))
            width = (90 + 0.42 * R) * (1 + ring_shape(th - rot))
            r_out = r_in + width * (1 + ring_jag(th - rot))
            # Celah sobekan: cincin terbelah di beberapa sudut
            gap = np.zeros_like(r)
            for s in splits:
                d = np.abs(((th - s - rot * 2 + np.pi) % (2 * np.pi)) - np.pi)
                gap = np.maximum(gap, np.clip((0.05 + 0.05 * p - d) * 400, 0, 1) * np.clip((r - r_in) / np.maximum(width, 1) * 2, 0, 1))
            inside = np.clip(r_in - r + 0.5, 0, 1)
            ring = np.clip(r - r_in + 0.5, 0, 1) * np.clip(r_out - r + 0.5, 0, 1) * (1 - gap)
            # Tepi sobekan di sisi dalam lebih terang (serat kertas), kertas makin jauh makin gelap (melengkung ke luar)
            t_ring = np.clip((r - r_in) / np.maximum(width, 1), 0, 1)
            light = 22 * np.clip(1 - (r - r_in) / 7, 0, 1) - 38 * t_ring ** 1.5
            img = paper_rgba(ring, light)
            # Bayangan kertas di atas clip utama (sisi dalam lubang)
            shadow = np.clip(1 - (r_in - r) / 26, 0, 1) * inside * 0.55
            sh = shadow > ring
            img[..., :3][sh] = 0
            img[..., 3][sh] = (shadow[sh] * 255).astype(np.uint8)
            mask = (inside * 255).astype(np.uint8)
        cv2.imwrite(os.path.join(out, f'paper_{f:02d}.png'), img, [cv2.IMWRITE_PNG_COMPRESSION, 6])
        cv2.imwrite(os.path.join(out, f'mask_{f:02d}.png'), mask, [cv2.IMWRITE_PNG_COMPRESSION, 6])
    with open(os.path.join(out, 'meta.txt'), 'w') as fh:
        fh.write(f'{total} {FPS} {BALL_FRAMES}\n')


if __name__ == '__main__':
    main()
