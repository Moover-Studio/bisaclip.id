"""
Pelacak pembicara untuk crop vertikal otomatis.

Membaca frame video mentah (BGR24) dari stdin, mendeteksi wajah (OpenCV YuNet), melacak tiap wajah,
mengukur gerakan area mulut, lalu memilih wajah yang sedang berbicara. Hasilnya berupa segmen kamera:
[{start, end, cx, cy, fh}] dengan koordinat ternormalisasi 0..1 (cx/cy = pusat wajah, fh = tinggi wajah).
Juga mencari webcam streamer (layout streaming game): wajah kecil yang posisinya tetap di sebagian besar frame.

Dipanggil oleh clip.php:
  ffmpeg ... -vf fps=10,scale=W:H -f rawvideo -pix_fmt bgr24 - | python speaker_track.py --width W --height H ...
"""
import argparse
import json
import sys

import cv2
import numpy as np


def parse_args():
    p = argparse.ArgumentParser()
    p.add_argument('--width', type=int, required=True)
    p.add_argument('--height', type=int, required=True)
    p.add_argument('--fps', type=float, default=10.0)
    p.add_argument('--model', required=True)
    p.add_argument('--words', default='', help='JSON [[start, end, word], ...] untuk tahu kapan ada suara')
    p.add_argument('--out', required=True)
    p.add_argument('--debug', action='store_true', help='Simpan data per frame untuk analisa')
    return p.parse_args()


def speech_mask(words_path, n, fps):
    """Array bool per frame: True kalau ada kata yang sedang diucapkan."""
    mask = np.ones(n, dtype=bool)
    if not words_path:
        return mask
    try:
        words = json.load(open(words_path))
    except Exception:
        return mask
    if not words:
        return mask
    mask[:] = False
    for start, end, _ in words:
        a = max(0, int((start - 0.15) * fps))
        b = min(n, int((end + 0.15) * fps) + 1)
        mask[a:b] = True
    return mask


def find_webcam(pts, n):
    """Webcam streamer: posisi wajah yang muncul di tempat sama pada frame terbanyak (minimal 25% frame).
    Wajah di dalam game bergerak / berganti, jadi kalah dari wajah webcam yang diam di pojok layar."""
    if not pts or n == 0:
        return None
    arr = np.array(pts, dtype=np.float32)
    best, best_hits = None, 0
    for cx, cy in arr[::max(1, len(arr) // 300), 1:3]:
        near = (np.abs(arr[:, 1] - cx) < 0.04) & (np.abs(arr[:, 2] - cy) < 0.05)
        hits = len(np.unique(arr[near, 0]))
        if hits > best_hits:
            best, best_hits = near, hits
    if best_hits < 0.25 * n:
        return None
    sel = arr[best]
    return {'cx': float(np.median(sel[:, 1])), 'cy': float(np.median(sel[:, 2])), 'fh': float(np.median(sel[:, 3])),
            'presence': round(best_hits / n, 3)}


def main():
    args = parse_args()
    W, H, fps = args.width, args.height, args.fps
    frame_bytes = W * H * 3

    det = cv2.FaceDetectorYN.create(args.model, '', (W, H), score_threshold=0.6, nms_threshold=0.3, top_k=10)

    tracks = []          # {id, cx, cy, fh, last}
    next_id = 0
    frames = []          # per frame: list (track_id, cx, cy, fh, motion)
    cam_pts = []         # semua wajah (termasuk yang kecil) untuk deteksi webcam: (frame, cx, cy, fh)
    shot_cuts = [0]      # index frame tempat terjadi pergantian kamera
    prev_gray = None
    prev_small = None
    cut_diffs = []
    i = 0

    while True:
        buf = sys.stdin.buffer.read(frame_bytes)
        if len(buf) < frame_bytes:
            break
        frame = np.frombuffer(buf, dtype=np.uint8).reshape(H, W, 3)
        gray = cv2.cvtColor(frame, cv2.COLOR_BGR2GRAY)

        # Deteksi pergantian kamera (cut) dari perbedaan gambar kecil
        small = cv2.resize(gray, (64, 36)).astype(np.float32)
        diff = float(np.mean(np.abs(small - prev_small))) if prev_small is not None else 0.0
        cut_diffs.append(round(diff, 1))
        if prev_small is not None and diff > 12:
            shot_cuts.append(i)
            tracks = []
        prev_small = small

        _, faces = det.detect(frame)
        dets = []
        if faces is not None:
            for f in faces:
                x, y, w, h = f[:4]
                if h >= H * 0.03:
                    cam_pts.append((i, float((x + w / 2) / W), float((y + h / 2) / H), float(h / H)))
                if h < H * 0.06:
                    continue  # wajah terlalu kecil (latar belakang)
                rmx, rmy, lmx, lmy = f[10:14]
                dets.append((x + w / 2, y + h / 2, w, h, rmx, rmy, lmx, lmy))

        # Cocokkan deteksi ke track berdasarkan jarak pusat wajah
        used = set()
        row = []
        for d in sorted(dets, key=lambda d: -d[3]):
            cx, cy, w, h = d[:4]
            best, best_dist = None, 1e9
            for t in tracks:
                if t['id'] in used:
                    continue
                dist = np.hypot(t['cx'] - cx, t['cy'] - cy)
                if dist < max(w, t['fh'] * 0.8) * 0.9 and dist < best_dist:
                    best, best_dist = t, dist
            if best is None:
                best = {'id': next_id, 'cx': cx, 'cy': cy, 'fh': h, 'last': i}
                next_id += 1
                tracks.append(best)
            best.update(cx=cx, cy=cy, fh=h, last=i)
            used.add(best['id'])

            # Gerakan mulut: selisih piksel area mulut terhadap frame sebelumnya, dinormalisasi ukuran wajah
            motion = 0.0
            rmx, rmy, lmx, lmy = d[4:8]
            mcx, mcy = (rmx + lmx) / 2, (rmy + lmy) / 2
            mw = max(abs(lmx - rmx) * 1.6, w * 0.35)
            mh = h * 0.28
            x0, x1 = int(max(0, mcx - mw / 2)), int(min(W, mcx + mw / 2))
            y0, y1 = int(max(0, mcy - mh * 0.45)), int(min(H, mcy + mh * 0.55))
            if prev_gray is not None and x1 - x0 > 3 and y1 - y0 > 3:
                a = gray[y0:y1, x0:x1].astype(np.float32)
                b = prev_gray[y0:y1, x0:x1].astype(np.float32)
                # kurangi gerakan kepala secara keseluruhan: bandingkan dengan gerakan area dahi/mata
                ey0, ey1 = int(max(0, cy - h * 0.35)), int(max(1, cy - h * 0.05))
                head = 0.0
                if ey1 - ey0 > 3:
                    head = np.mean(np.abs(gray[ey0:ey1, x0:x1].astype(np.float32) - prev_gray[ey0:ey1, x0:x1].astype(np.float32)))
                motion = max(0.0, float(np.mean(np.abs(a - b))) - head * 0.7)
            row.append((best['id'], float(cx / W), float(cy / H), float(h / H), round(float(motion), 3)))

        tracks = [t for t in tracks if i - t['last'] <= int(fps * 1.0)]
        frames.append(row)
        prev_gray = gray
        i += 1

    n = len(frames)
    if n == 0:
        json.dump({'segments': [], 'frames': 0, 'faces': 0}, open(args.out, 'w'))
        return

    speaking = speech_mask(args.words, n, fps)
    shot_cuts.append(n)

    # Pilih wajah aktif per frame: skor = rata-rata gerakan mulut (diperhalus) selama ada suara
    target = [None] * n           # (track_id, cx, cy, fh)
    score = {}                    # skor halus per track
    current = None
    challenger, challenge_frames = None, 0
    hold = int(fps * 0.8)          # penantang harus unggul selama ini sebelum kamera pindah
    for k in range(n):
        row = frames[k]
        if k in shot_cuts:
            score, current, challenger, challenge_frames = {}, None, None, 0
        ids = {r[0]: r for r in row}
        for tid, _, _, _, m in row:
            if speaking[k]:
                score[tid] = score.get(tid, m) * 0.8 + m * 0.2
        if not row:
            target[k] = target[k - 1] if k > 0 and current is not None else None
            continue
        if current not in ids:
            # Pembicara sebelumnya tidak terlihat: ambil wajah dengan skor tertinggi, atau wajah terbesar
            current = max(ids, key=lambda t: (score.get(t, 0), ids[t][3]))
            challenger, challenge_frames = None, 0
        elif len(ids) > 1 and speaking[k]:
            best = max(ids, key=lambda t: score.get(t, 0))
            if best != current and score.get(best, 0) > score.get(current, 0) * 1.35 + 0.15:
                if best == challenger:
                    challenge_frames += 1
                else:
                    challenger, challenge_frames = best, 1
                if challenge_frames >= hold:
                    current, challenger, challenge_frames = best, None, 0
            else:
                challenger, challenge_frames = None, 0
        r = ids[current]
        target[k] = (current, r[1], r[2], r[3])

    # Isi frame tanpa wajah di awal dengan target pertama yang ditemukan
    first = next((t for t in target if t is not None), None)
    target = [t if t is not None else first for t in target]

    # Susun segmen: segmen baru saat wajah target berganti atau pindah posisi jauh (cut kamera)
    segments = []
    seg_start = 0
    for k in range(1, n + 1):
        end = k == n
        if not end:
            a, b = target[k], target[seg_start]
            moved = a is not None and b is not None and (a[0] != b[0] or abs(a[1] - b[1]) > 0.08)
            changed = (a is None) != (b is None) or moved or k in shot_cuts
        if end or changed:
            chunk = [t for t in target[seg_start:k] if t is not None]
            if chunk:
                seg = {'start': seg_start / fps, 'end': k / fps,
                       'cx': float(np.median([t[1] for t in chunk])), 'cy': float(np.median([t[2] for t in chunk])),
                       'fh': float(np.median([t[3] for t in chunk])), 'track': int(chunk[0][0])}
            else:
                seg = {'start': seg_start / fps, 'end': k / fps, 'cx': 0.5, 'cy': 0.42, 'fh': 0.0, 'track': -1}
            segments.append(seg)
            seg_start = k

    # Gabungkan segmen sangat pendek ke segmen sebelumnya supaya kamera tidak berkedip
    merged = []
    for s in segments:
        if merged and s['end'] - s['start'] < 0.5:
            merged[-1]['end'] = s['end']
        else:
            merged.append(s)
    if len(merged) > 1 and merged[0]['end'] - merged[0]['start'] < 0.5:
        merged[1]['start'] = merged[0]['start']
        merged.pop(0)

    # Daftar orang per shot kamera (untuk split screen): wajah yang muncul di >=40% frame shot itu,
    # posisinya median, diurutkan dari kiri ke kanan
    shots = []
    for a, b in zip(shot_cuts[:-1], shot_cuts[1:]):
        if b <= a:
            continue
        seen = {}
        for k in range(a, b):
            for tid, cx, cy, fh, _ in frames[k]:
                seen.setdefault(tid, []).append((cx, cy, fh))
        people = []
        for tid, pts in seen.items():
            if len(pts) >= 0.4 * (b - a):
                people.append({'cx': float(np.median([p[0] for p in pts])), 'cy': float(np.median([p[1] for p in pts])),
                               'fh': float(np.median([p[2] for p in pts]))})
        people.sort(key=lambda q: q['cx'])
        shots.append({'start': a / fps, 'end': b / fps, 'people': people})

    faces_frames = sum(1 for r in frames if r)
    result = {'segments': merged, 'shots': shots, 'frames': n, 'faces': faces_frames, 'cuts': len(shot_cuts) - 2,
              'cam': find_webcam(cam_pts, n)}
    if args.debug:
        result['debug_frames'] = frames
        result['debug_cuts'] = shot_cuts
        result['debug_cutdiff'] = cut_diffs
    json.dump(result, open(args.out, 'w'))


if __name__ == '__main__':
    main()
