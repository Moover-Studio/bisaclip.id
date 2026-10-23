// Helper yang dipakai bersama oleh beranda (index.php), Clip Studio (studio.php) dan editor

const $ = id => document.getElementById(id);

/*
 * Ganti isi elemen tanpa merusak klik. Bagian yang diperbarui otomatis (polling) sering mengganti tombol tepat saat
 * diklik: tombol lama hilang di antara mouse ditekan & dilepas, jadi kliknya tidak jalan. setHTML():
 *  - tidak menyentuh DOM kalau isinya sama persis (tombol tetap elemen yang sama),
 *  - menunda pembaruan selama mouse/jari sedang menekan di dalam elemen itu, lalu menerapkannya setelah klik selesai.
 */
let pressedEl = null;
const deferredHTML = new Map();
document.addEventListener('pointerdown', e => { pressedEl = e.target; }, true);
const flushDeferred = () => {
  pressedEl = null;
  // Setelah event click selesai diproses, baru isi yang tertunda dipasang
  setTimeout(() => { for (const [el, html] of deferredHTML) setHTML(el, html); deferredHTML.clear(); }, 0);
};
document.addEventListener('pointerup', flushDeferred, true);
document.addEventListener('pointercancel', flushDeferred, true);
function setHTML(el, html) {
  if (!el) return;
  if (el._html === html && el.isConnected) return;
  if (pressedEl && el.contains(pressedEl)) { deferredHTML.set(el, html); return; }
  el.innerHTML = html;
  el._html = html;
}
const ACTIVE = ['queued', 'downloading', 'converting', 'analyzing'];

const store = {
  get(k) { try { return localStorage.getItem(k); } catch { return null; } },
  set(k, v) { try { localStorage.setItem(k, v); } catch {} },
};

// Ikon tombol dari sprite assets/icons.svg, mis. `${ic('download')} Simpan`
const ICON_V = '2026-10-19a';  // naikkan saat assets/icons.svg berubah supaya cache browser diperbarui
const ic = n => `<svg class="i" aria-hidden="true"><use href="assets/icons.svg?v=${ICON_V}#${n}"/></svg>`;
// Avatar akun: foto profil kalau ada, selain itu inisial berwarna (class .av diatur tiap halaman)
const accAvatar = (a, style = '') => a.avatar
  ? `<img class="av" src="${esc(a.avatar)}" alt="" style="${style}">`
  : `<span class="av" style="background:${esc(a.color)};${style}">${esc((String(a.name).trim()[0] || '?').toUpperCase())}</span>`;
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const fmtDur = s => { s = Math.round(s); const h = Math.floor(s / 3600), m = Math.floor(s % 3600 / 60), x = s % 60;
  return (h ? h + ':' + String(m).padStart(2, '0') : m) + ':' + String(x).padStart(2, '0'); };
const fmtTs = s => { s = Math.max(0, s); const h = Math.floor(s / 3600), m = Math.floor(s % 3600 / 60), x = (s % 60).toFixed(1).padStart(4, '0');
  return (h ? h + ':' + String(m).padStart(2, '0') : m) + ':' + x; };
const parseTs = v => { const p = String(v).trim().split(':').map(Number); if (!p.length || p.some(isNaN)) return NaN;
  return p.reduce((a, b) => a * 60 + b, 0); };
const fmtSize = b => !b ? '' : b >= 1073741824 ? (b / 1073741824).toFixed(2) + ' GB' : (b / 1048576).toFixed(0) + ' MB';
const fmtDate = t => new Date(t * 1000).toLocaleString('id-ID', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });

// Format download (dipakai di beranda & room)
const FORMATS = [
  { id: 'edit_h264', name: 'H.264 MP4', desc: 'Rekomendasi untuk After Effects & CapCut' },
  { id: 'edit_prores', name: 'ProRes 422', desc: 'Paling ringan di AE, file sangat besar' },
  { id: 'original', name: 'Original', desc: 'File asli VP9/AV1, tanpa konversi' },
  { id: 'audio_wav', name: 'Audio WAV', desc: 'Audio saja, otomatis masuk library musik editor' },
  { id: 'audio_mp3', name: 'Audio MP3', desc: 'Audio saja 320 kbps, otomatis masuk library musik editor' },
];
const qName = h => ({ 4320: '8K', 2160: '4K', 1440: '2K' }[h] || h + 'p');

// Batas waktu per aksi (detik), supaya tampilan tidak berputar tanpa akhir
const API_TIMEOUT = { info: 90, preview: 150, captions_en: 180, room_cleanup: 120, room_slim: 300, storage_slim_all: 1800, storage_purge_renders: 300, storage_info: 60, campaign_plan: 300, campaign_get: 60 };

async function api(action, body, query = '') {
  const ctrl = new AbortController();
  const limit = API_TIMEOUT[action] || 30;
  const timer = setTimeout(() => ctrl.abort(), limit * 1000);
  let res;
  try {
    res = await fetch('api.php?action=' + action + query, {
      signal: ctrl.signal,
      ...(body ? { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) } : {}),
    });
  } catch (err) {
    throw new Error(err.name === 'AbortError'
      ? `Server tidak merespons dalam ${limit} detik. Periksa koneksi internet lalu coba lagi.`
      : 'Tidak bisa terhubung ke server. Pastikan Apache XAMPP menyala.');
  } finally {
    clearTimeout(timer);
  }
  const data = await res.json().catch(() => ({ error: 'Respons server tidak valid.' }));
  if (!res.ok || data.error) throw new Error(data.error || 'Terjadi kesalahan.');
  return data;
}

let toastTimer;
function toast(msg) {
  let el = document.querySelector('.toast');
  if (!el) { el = document.createElement('div'); el.className = 'toast'; document.body.appendChild(el); }
  el.textContent = msg;
  el.classList.add('show');
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => el.classList.remove('show'), 2600);
}

async function copyText(text, msg = 'Tersalin') {
  try { await navigator.clipboard.writeText(text); toast(msg + ' ✓'); }
  catch { toast('Gagal menyalin'); }
}

/* ---------- Baris unduhan (download & potongan clip) ---------- */

function jobRow(j) {
  const active = ACTIVE.includes(j.status);
  const r = j.result || {};
  let meta;
  if (j.status === 'done') {
    meta = `<span class="ok">Selesai</span>` + [r.resolution, r.fps ? Math.round(r.fps) + 'fps' : '',
      j.mode === 'clip' && r.duration ? r.duration.toFixed(1) + 's' : '', fmtSize(j.size)]
      .filter(Boolean).map(x => `<span>· ${esc(x)}</span>`).join('')
      + (j.music_lib ? `<span class="ok">· ${ic('music')} Di library musik</span>` : '');
  } else if (j.status === 'error') {
    meta = `<span class="err">Gagal: ${esc(j.error || '')}</span>`;
  } else if (j.status === 'cancelled') {
    meta = '<span>Dibatalkan</span>';
  } else {
    meta = `<span class="spin"></span><span>${esc(j.stage)}${j.progress ? ' · ' + Math.floor(j.progress) + '%' : ''}</span>`
      + (j.speed ? `<span>· ${esc(j.speed)}</span>` : '') + (j.eta ? `<span>· sisa ${esc(j.eta)}</span>` : '');
  }
  const kind = j.mode === 'clip' ? `${esc(j.mode_label)} · ${fmtTs(j.start)}–${fmtTs(j.end)}` : esc(j.mode_label);
  const actions = active
    ? `<button class="btn sm" data-job="cancel" data-id="${j.id}">${ic('ban')} Batal</button>`
    : (j.status === 'done'
      ? `<a class="btn sm" href="downloads/${encodeURIComponent(j.file)}" download>${ic('download')} Simpan</a>
         <button class="btn sm ghost" data-job="copy" data-path="${esc(j.path)}">${ic('copy')} Salin path</button>`
      : ['error', 'cancelled'].includes(j.status) && j.mode !== 'clip'
        ? `<button class="btn sm" data-job="retry" data-id="${j.id}">${ic('refresh')} Coba lagi</button>` : '')
      + `<button class="btn sm ghost danger" data-job="delete" data-id="${j.id}" data-done="${j.status === 'done' ? 1 : ''}" title="Hapus">${ic('trash')} Hapus</button>`;
  return `<div class="card job">
    <img class="thumb" src="${esc(j.thumbnail)}" alt="" onerror="this.style.visibility='hidden'">
    <div style="min-width:0">
      <div class="job-title" title="${esc(j.file || j.title)}">${esc(j.file || j.title || j.url)}</div>
      <div class="job-meta"><span>${kind}</span></div>
      <div class="job-meta">${meta}</div>
      ${active ? `<div class="progress"><i style="width:${j.progress || 0}%"></i></div>` : ''}
    </div>
    <div class="job-actions">${actions}</div>
  </div>`;
}

// Tombol pada baris unduhan; onChange dipanggil setelah aksi berhasil
function bindJobActions(container, onChange) {
  container.addEventListener('click', async e => {
    const btn = e.target.closest('[data-job]');
    if (!btn) return;
    const { job, id, path, done } = btn.dataset;
    try {
      if (job === 'copy') return copyText(path, 'Path disalin');
      if (job === 'cancel') await api('cancel', { id });
      if (job === 'retry') await api('retry', { id });
      if (job === 'delete') {
        if (done && !confirm('Hapus file ini dari disk?')) return;
        await api('delete', { id, delete_file: !!done });
      }
      onChange();
    } catch (err) { toast(err.message); }
  });
}

/* ---------- Jam upload rekomendasi (WIB / jam lokal browser) ---------- */
// [jam, nama slot, alasan, kekuatan 1-3]. Dipilih per jenis akun (niche) & jenis hari (kerja / libur).
const UPLOAD_SLOTS = {
  general: {
    kerja: [['07:00', 'Pagi', 'Bangun tidur & perjalanan berangkat', 2], ['12:00', 'Istirahat siang', 'Scroll singkat saat makan siang', 1], ['19:30', 'Prime time', 'Waktu santai utama, penonton paling banyak', 3]],
    libur: [['09:00', 'Pagi santai', 'Hari libur orang bangun lebih siang', 2], ['19:30', 'Prime time', 'Malam santai, penonton paling banyak', 3]],
  },
  game: {
    kerja: [['16:00', 'Pulang sekolah', 'Pelajar & mahasiswa mulai online', 2], ['20:00', 'Prime time', 'Puncak penonton konten game', 3], ['22:00', 'Malam', 'Penonton game masih aktif sampai larut', 2]],
    libur: [['11:00', 'Siang', 'Waktu main di hari libur', 2], ['14:00', 'Sore santai', 'Waktu nonton panjang', 2], ['20:30', 'Prime time', 'Malam libur, paling ramai', 3]],
  },
};
const SLOT_KIND_NAME = { general: 'Umum / self development', game: 'Gaming' };
const nicheKind = a => /game|gaming|esport|mabar|gamer|streamer/i.test(`${a?.niche || ''} ${a?.name || ''}`) ? 'game' : 'general';
const ymdLocal = (d = new Date()) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;

// Jenis hari: tanda manual > libur nasional / cuti bersama > akhir pekan > hari kerja
function dayInfo(date, holidays = {}, overrides = {}) {
  const [y, m, d] = date.split('-').map(Number), dow = new Date(y, m - 1, d).getDay();
  const h = holidays[date], o = overrides[date], weekend = dow === 0 || dow === 6;
  if (o === 'libur') return { libur: true, kind: 'custom', label: 'Libur (ditandai)', holiday: h, dow };
  if (o === 'kerja') return { libur: false, kind: 'custom', label: 'Hari kerja (ditandai)', holiday: h, dow };
  if (h) return { libur: true, kind: h.cuti ? 'cuti' : 'holiday', label: h.cuti ? 'Cuti bersama' : 'Libur nasional', holiday: h, dow };
  if (weekend) return { libur: true, kind: 'weekend', label: 'Akhir pekan', dow };
  return { libur: false, kind: 'kerja', label: 'Hari kerja', dow };
}

// Slot jam upload untuk satu akun di satu tanggal: [{ time, min, label, why, strength }]
function uploadSlots(info, acc) {
  const kind = nicheKind(acc);
  let list = UPLOAD_SLOTS[kind][info.libur ? 'libur' : 'kerja'].map(([time, label, why, strength]) => ({ time, label, why, strength }));
  // Jumat siang banyak penonton pria sedang Jumatan: slot siang digeser ke 13.30
  if (!info.libur && info.dow === 5) list = list.map(s => s.time === '12:00' ? { ...s, time: '13:30', label: 'Setelah Jumatan', why: 'Jumat 11.30–13.00 penonton pria banyak sedang Jumatan' } : s);
  // Minggu malam: penonton bersiap untuk Senin, konten motivasi/refleksi paling cocok
  if (info.dow === 0 && kind === 'general') list = list.map(s => s.time === '19:30' ? { ...s, why: 'Minggu malam, cocok untuk konten motivasi & persiapan Senin' } : s);
  return list.map(s => ({ ...s, min: +s.time.slice(0, 2) * 60 + +s.time.slice(3), kind }));
}
const fmtSlot = t => t.replace(':', '.');

/* ---------- Pengingat jam upload: kartu kecil di pojok saat masuk jam rekomendasi ---------- */
const REMIND_BEFORE = 30, REMIND_AFTER = 20; // menit sebelum & sesudah jam slot
let remindData = null, remindAt = 0;
async function checkUploadReminder() {
  if (document.hidden) return;
  const now = new Date(), date = ymdLocal(now), mins = now.getHours() * 60 + now.getMinutes();
  if (!remindData || remindData.date !== date || Date.now() - remindAt > 10 * 60e3) {
    try { remindData = await api('upload_today', null, '&date=' + date); remindAt = Date.now(); } catch { return; }
  }
  const D = remindData, info = dayInfo(date, D.holiday ? { [date]: D.holiday } : {}, D.override ? { [date]: D.override } : {});
  const accs = D.accounts.length ? D.accounts : [null];
  const hits = [];
  for (const a of accs) {
    for (const s of uploadSlots(info, a)) {
      const key = `remind-${date}-${a?.id || 'all'}-${s.time}`;
      if (mins >= s.min - REMIND_BEFORE && mins <= s.min + REMIND_AFTER && !store.get(key)) hits.push({ a, s, key, ready: D.ready[a?.id ?? ''] || 0 });
    }
  }
  let box = document.querySelector('.reminder');
  if (!hits.length) { box?.remove(); return; }
  if (!box) { box = document.createElement('div'); box.className = 'reminder'; document.body.appendChild(box); }
  const h = hits[0], diff = h.s.min - mins;
  box._hits = hits;
  if (!box.onclick) box.onclick = e => {
    if (!e.target.closest('[data-rm-x]')) return;
    (box._hits || []).forEach(x => store.set(x.key, '1'));
    box.remove();
  };
  setHTML(box, `<div class="rm-top"><span class="rm-bell">${ic('calendar')}</span>
      <div class="rm-tx"><b>${diff > 0 ? `${diff} menit lagi jam upload` : 'Sekarang jam upload'} · ${fmtSlot(h.s.time)}</b>
        <span>${esc(h.s.label)} · ${esc(info.label)}${info.holiday ? ' (' + esc(info.holiday.name) + ')' : ''}</span></div>
      <button class="rm-x" data-rm-x title="Tutup pengingat ini">${ic('x')}</button></div>
    <div class="rm-list">${hits.filter((x, k) => hits.findIndex(y => (y.a?.id || '') === (x.a?.id || '')) === k).map(x => `<div class="rm-acc">${x.a ? accAvatar(x.a, 'width:20px;height:20px;font-size:10px') : ''}
      <span>${x.a ? esc(x.a.name) : 'Semua akun'}</span><small>${x.ready ? `${x.ready} clip siap upload` : 'belum ada clip siap'}</small></div>`).join('')}</div>
    <a class="btn sm primary" href="calendar.php">${ic('calendar')} Lihat jadwal upload</a>`);
  // Notifikasi sistem (kalau diizinkan di halaman kalender), sekali per slot
  if ('Notification' in window && Notification.permission === 'granted') {
    hits.filter(x => !store.get(x.key + '-n')).forEach(x => {
      store.set(x.key + '-n', '1');
      try { new Notification(`BisaClip · jam upload ${fmtSlot(x.s.time)}`, { body: `${x.a ? x.a.name + ' · ' : ''}${x.s.label} · ${x.ready} clip siap upload`, icon: 'assets/favicon-32.png' }); } catch {}
    });
  }
}
// Aktif di semua halaman kecuali editor (supaya tidak mengganggu saat mengedit)
if (!/editor\.php/.test(location.pathname)) {
  document.addEventListener('DOMContentLoaded', () => { checkUploadReminder(); setInterval(checkUploadReminder, 30e3); });
  document.addEventListener('visibilitychange', () => { if (!document.hidden) checkUploadReminder(); });
}


/* ---------- Modal "Project baru" (beranda & daftar project): nama + rasio layar ---------- */
const NP_RATIOS = [
  ['9:16', 'Portrait', 'TikTok, Reels, Shorts', 9, 16],
  ['16:9', 'Landscape', 'YouTube, presentasi', 16, 9],
  ['1:1', 'Square', 'Feed Instagram', 1, 1],
  ['4:5', 'Portrait 4:5', 'Feed Instagram tinggi', 4, 5],
];
function openNewProject() {
  let m = document.getElementById('np-modal');
  if (!m) {
    m = document.createElement('div');
    m.id = 'np-modal';
    m.className = 'np-modal';
    m.innerHTML = `<form class="np-card" role="dialog" aria-modal="true" aria-labelledby="np-title" autocomplete="off">
      <div class="np-head"><div><h3 id="np-title">Project baru</h3><p>Pilih rasio layar sesuai platform tujuan. Bisa diganti nanti di editor.</p></div>
        <button type="button" class="np-x" data-np-close aria-label="Tutup">${ic('x')}</button></div>
      <label class="np-lbl" for="np-name">Nama project</label>
      <input class="input np-name" id="np-name" maxlength="100" placeholder="mis. Recap liburan Bali">
      <div class="np-lbl">Rasio layar</div>
      <div class="np-ratios">${NP_RATIOS.map(([k, n, d, w, h], i) => `<label class="np-r${i ? '' : ' on'}"><input type="radio" name="np-ratio" value="${k}" ${i ? '' : 'checked'}>
        <span class="np-frame"><i style="aspect-ratio:${w}/${h};${w >= h ? 'width:100%' : 'height:100%'}"></i></span>
        <b>${n}</b><small>${k} · ${d}</small></label>`).join('')}</div>
      <div class="np-foot"><button type="button" class="btn ghost" data-np-close>Batal</button>
        <button type="submit" class="btn primary" id="np-go">${ic('plus')} Buat project</button></div>
    </form>`;
    document.body.appendChild(m);
    const close = () => { m.classList.remove('show'); setTimeout(() => { m.hidden = true; }, 200); };
    m.addEventListener('click', e => { if (e.target === m || e.target.closest('[data-np-close]')) close(); });
    m.addEventListener('change', e => { if (e.target.name === 'np-ratio') m.querySelectorAll('.np-r').forEach(r => r.classList.toggle('on', r.querySelector('input').checked)); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && !m.hidden) close(); });
    m.querySelector('form').addEventListener('submit', async e => {
      e.preventDefault();
      const go = m.querySelector('#np-go');
      go.disabled = true;
      go.innerHTML = '<span class="spin"></span> Membuat…';
      try {
        const r = await api('mproj_create', { name: m.querySelector('#np-name').value.trim(), ratio: m.querySelector('[name=np-ratio]:checked').value });
        location.href = 'project.php?id=' + r.project.id;
      } catch (err) {
        toast(err.message);
        go.disabled = false;
        go.innerHTML = `${ic('plus')} Buat project`;
      }
    });
  }
  m.hidden = false;
  requestAnimationFrame(() => m.classList.add('show'));
  setTimeout(() => m.querySelector('#np-name').focus(), 60);
}
