<?php
// Clip campaign dari footage lokal: unggah bahan → AI pecah adegan → isi angle → AI susun clip + hook → rakit → Clip Studio
require __DIR__ . '/config.php';
require_local();
ensure_dirs();
$cid = (string) ($_GET['id'] ?? '');
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="dark">
<meta name="theme-color" content="#0a0b0d">
<link rel="icon" type="image/png" href="assets/favicon-32.png">
<title>Campaign · BisaClip</title>
<link rel="stylesheet" href="assets/app.css?v=<?= filemtime(__DIR__ . '/assets/app.css') ?>">
<style>
  .eyebrow { font-size: 12px; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; margin-bottom: 4px; }
  .head { padding: 18px 20px; background: linear-gradient(120deg, rgba(0, 88, 211, .14), transparent 55%), var(--surface); }
  .head h1 { font-size: 20px; margin: 0 0 4px; }
  .head p { margin: 0; font-size: 13px; color: var(--muted); line-height: 1.5; }
  .head-row { display: flex; gap: 12px; align-items: flex-start; justify-content: space-between; flex-wrap: wrap; }
  .title-in { font: inherit; font-size: 20px; font-weight: 700; color: var(--text); background: none; border: 1px solid transparent; border-radius: 8px;
    padding: 2px 6px; margin: 0 0 4px -7px; width: min(560px, 100%); }
  .title-in:hover { border-color: var(--border); } .title-in:focus { outline: none; border-color: var(--accent); background: var(--surface-2); }
  .sec { margin-top: 16px; padding: 20px; }
  .sec h2 { display: flex; align-items: center; gap: 10px; font-size: 16px; margin: 0 0 4px; }
  .sec h2 .no { width: 24px; height: 24px; display: grid; place-items: center; border-radius: 50%; background: var(--accent-soft); color: var(--accent-fg); font-size: 12.5px; }
  .sec > p { margin: 0; font-size: 13px; color: var(--muted); line-height: 1.5; }
  .lbl { display: block; font-size: 12.5px; font-weight: 600; color: var(--text-2); margin: 16px 0 6px; }
  .chip:disabled { opacity: .45; cursor: default; }
  /* Daftar campaign */
  .clist { display: grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr)); gap: 14px; margin-top: 16px; }
  .ccard { overflow: hidden; display: block; color: inherit; text-decoration: none; transition: border-color .15s; }
  .ccard:hover { border-color: var(--surface-3); }
  .ccard .th { aspect-ratio: 16/9; background: var(--surface-2) center / cover no-repeat; display: grid; place-items: center; color: var(--muted); }
  .ccard .th .i { width: 28px; height: 28px; }
  .ccard .bd { padding: 12px 14px 14px; }
  .ccard b { display: block; font-size: 14px; }
  .ccard small { display: block; font-size: 12px; color: var(--muted); margin-top: 4px; }
  /* Bahan */
  .drop { display: block; margin-top: 14px; border: 1.5px dashed var(--border-strong); border-radius: 14px; padding: 22px; text-align: center; color: var(--muted); cursor: pointer;
    transition: border-color .15s, background .15s; }
  .drop:hover, .drop.over { border-color: var(--accent); background: var(--accent-soft); color: var(--text); }
  .drop .i { width: 26px; height: 26px; display: block; margin: 0 auto 6px; }
  .drop b { color: var(--text); }
  .files { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 10px; margin-top: 14px; }
  .file { position: relative; border: 1px solid var(--border); border-radius: 12px; overflow: hidden; background: var(--surface-2); }
  .file .th { position: relative; aspect-ratio: 16/9; background: #000 center / cover no-repeat; }
  .file .code { position: absolute; left: 6px; top: 6px; padding: 1px 7px; border-radius: 6px; background: rgba(0, 0, 0, .7); color: #fff; font-size: 11px; font-weight: 700; }
  .file .del { position: absolute; right: 6px; top: 6px; width: 26px; height: 26px; display: grid; place-items: center; border: 0; border-radius: 8px;
    background: rgba(0, 0, 0, .65); color: #fff; cursor: pointer; opacity: 0; transition: opacity .15s; }
  .file:hover .del, .file .del:focus-visible { opacity: 1; }
  .file .del .i { width: 14px; height: 14px; }
  .file .bd { padding: 9px 10px 10px; }
  .file .nm { font-size: 12.5px; font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .file .st { margin-top: 3px; font-size: 11.5px; color: var(--muted); display: flex; align-items: center; gap: 5px; min-height: 16px; }
  .file .st.ok { color: #4fd28a; } .file .st.err { color: #ff8a80; }
  .file .st .i { width: 12px; height: 12px; flex: none; }
  .file .bar { height: 3px; margin-top: 6px; border-radius: 3px; background: var(--surface-3); overflow: hidden; }
  .file .bar i { display: block; height: 100%; background: var(--accent); transition: width .3s; }
  .file .retry { margin-top: 6px; }
  /* Konsep */
  textarea.input { resize: vertical; min-height: 64px; line-height: 1.5; font: inherit; }
  .go-row { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; margin-top: 18px; }
  .go-row .muted { font-size: 12.5px; }
  /* Status penyusunan AI (berjalan / gagal): tetap terlihat, bukan notifikasi sesaat */
  .plan-st { display: flex; align-items: center; gap: 12px; margin-top: 14px; padding: 12px 14px; border-radius: 12px; background: var(--accent-soft);
    border: 1px solid rgba(30, 112, 240, .35); }
  .plan-st > div { flex: 1; min-width: 0; }
  .plan-st b { display: block; font-size: 13px; }
  .plan-st small { display: block; font-size: 12px; color: var(--muted); margin-top: 2px; line-height: 1.45; }
  .plan-st.err { background: rgba(229, 72, 77, .1); border-color: rgba(229, 72, 77, .45); }
  .plan-st.err > .i { width: 18px; height: 18px; color: #ff8a80; flex: none; }
  /* Susunan */
  .plan-grid { display: grid; grid-template-columns: minmax(0, 1.45fr) minmax(0, 1fr); gap: 18px; margin-top: 16px; }
  @media (max-width: 860px) { .plan-grid { grid-template-columns: 1fr; } }
  .tot { display: flex; align-items: center; gap: 10px; justify-content: space-between; flex-wrap: wrap; margin-bottom: 10px; font-size: 13px; color: var(--muted); }
  .tot b { color: var(--text); font-variant-numeric: tabular-nums; }
  .tot .btns { display: flex; gap: 6px; }
  .segs { display: grid; gap: 8px; }
  .seg-row { display: grid; grid-template-columns: 22px 92px minmax(0, 1fr) auto; gap: 10px; align-items: center; padding: 8px; border: 1px solid var(--border);
    border-radius: 12px; background: var(--surface-2); }
  .seg-row .n { font-size: 12px; font-weight: 700; color: var(--muted); text-align: center; }
  .seg-row .n.hook { color: #FFC400; }
  .seg-row .th { position: relative; width: 92px; aspect-ratio: 16/9; border-radius: 7px; background: #000 center / cover no-repeat; border: 0; padding: 0; cursor: pointer; }
  .seg-row .th .i { position: absolute; inset: 0; margin: auto; width: 20px; height: 20px; color: #fff; opacity: 0; transition: opacity .15s; filter: drop-shadow(0 1px 3px rgba(0, 0, 0, .8)); }
  .seg-row .th:hover .i { opacity: 1; }
  .seg-row .tx { min-width: 0; }
  .seg-row .t1 { display: flex; align-items: center; gap: 6px; font-size: 12.5px; font-weight: 600; flex-wrap: wrap; }
  .seg-row .t1 .fc { padding: 0 6px; border-radius: 5px; background: var(--surface-3); font-size: 11px; }
  .seg-row .tm { display: inline-flex; align-items: center; gap: 4px; font-size: 11.5px; color: var(--muted); font-weight: 500; }
  .seg-row .tm input { width: 58px; padding: 2px 5px; border: 1px solid var(--border); border-radius: 6px; background: var(--surface); color: var(--text); font: inherit; font-variant-numeric: tabular-nums; }
  .seg-row .rs { font-size: 11.5px; color: var(--muted); margin-top: 3px; line-height: 1.4; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
  .seg-row .ac { display: flex; flex-direction: column; gap: 2px; }
  .seg-row .ac button { width: 26px; height: 22px; display: grid; place-items: center; border: 0; border-radius: 6px; background: none; color: var(--muted); cursor: pointer; }
  .seg-row .ac button:hover { background: var(--surface-3); color: var(--text); }
  .seg-row .ac button:disabled { opacity: .3; cursor: default; background: none; }
  .seg-row .ac .i { width: 13px; height: 13px; }
  .seg-row .ac .rm:hover { color: #ff8a80; }
  .seg-row .ac [data-up] .i { transform: rotate(180deg); }
  .unused { margin-top: 14px; font-size: 12.5px; color: var(--muted); }
  .seg-row .dlg { font-size: 12px; color: var(--text-2); margin-top: 4px; line-height: 1.4; font-style: italic; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
  .story { display: flex; gap: 10px; align-items: flex-start; margin-top: 12px; padding: 10px 12px; border-radius: 12px; background: var(--surface-2); border: 1px solid var(--border); }
  .story > .i { width: 16px; height: 16px; color: var(--accent-fg); flex: none; margin-top: 2px; }
  .story b { display: block; font-size: 12.5px; } .story span { display: block; font-size: 12.5px; color: var(--text-2); line-height: 1.5; margin-top: 2px; }
  .unused li { margin-top: 4px; }
  .side .input { font-size: 13.5px; padding: 9px 11px; }
  .hook-in { font-weight: 700; }
  .builds { display: grid; gap: 8px; margin-top: 14px; }
  .build { display: flex; align-items: center; gap: 12px; padding: 10px 12px; border: 1px solid var(--border); border-radius: 12px; background: var(--surface-2); flex-wrap: wrap; }
  .build .tx { flex: 1; min-width: 180px; }
  .build b { font-size: 13px; display: block; }
  .build small { font-size: 11.5px; color: var(--muted); }
  .build .bar { height: 3px; margin-top: 6px; border-radius: 3px; background: var(--surface-3); overflow: hidden; }
  .build .bar i { display: block; height: 100%; background: var(--accent); }
  .build .err { color: #ff8a80; }
  /* Modal pilih adegan & pemutar */
  .modal { position: fixed; inset: 0; z-index: 50; display: grid; place-items: center; padding: 16px; background: rgba(0, 0, 0, .6); backdrop-filter: blur(4px); }
  .modal[hidden] { display: none; }
  .m-card { width: 100%; max-width: 760px; max-height: calc(100vh - 32px); display: flex; flex-direction: column; padding: 20px; }
  .m-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; margin-bottom: 12px; }
  .m-head h3 { margin: 0; font-size: 16px; } .m-head p { margin: 3px 0 0; font-size: 12.5px; color: var(--muted); }
  .pick-list { overflow-y: auto; display: grid; gap: 14px; }
  .pick-f h4 { margin: 0 0 6px; font-size: 13px; }
  .pick-s { display: grid; grid-template-columns: repeat(auto-fill, minmax(165px, 1fr)); gap: 8px; }
  .scn { display: flex; flex-direction: column; gap: 4px; padding: 6px; border: 1px solid var(--border); border-radius: 10px; background: var(--surface-2); text-align: left; color: var(--text); font: inherit; cursor: pointer; }
  .scn:hover { border-color: var(--accent); }
  .scn.na { opacity: .5; }
  .scn .th { aspect-ratio: 16/9; border-radius: 6px; background: #000 center / cover no-repeat; }
  .scn b { font-size: 11.5px; } .scn small { font-size: 11px; color: var(--muted); line-height: 1.35; display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden; }
  .player video { width: 100%; max-height: 62vh; border-radius: 10px; background: #000; }
  .player .cap { margin-top: 8px; font-size: 12.5px; color: var(--muted); }
</style>
</head>
<body>
<div class="wrap">
  <header class="topbar">
    <a class="back" href="<?= $cid ? 'campaign.php' : 'index.php' ?>" title="Kembali" aria-label="Kembali"><?= icon('arrow-left') ?></a>
    <a class="brand" href="index.php"><img src="assets/logo-96.png" alt=""><span><b>Bisa</b>Clip</span></a>
  </header>
  <div id="app"><div class="empty"><span class="spin"></span></div></div>
</div>

<div class="modal" id="pick" hidden>
  <div class="card m-card" role="dialog" aria-modal="true" aria-labelledby="pick-title">
    <div class="m-head"><div><h3 id="pick-title">Tambah adegan</h3><p>Klik adegan untuk menambahkannya di akhir susunan.</p></div>
      <button class="btn sm ghost" data-close>Tutup</button></div>
    <div class="pick-list" id="pick-list"></div>
  </div>
</div>
<div class="modal" id="play" hidden>
  <div class="card m-card player" role="dialog" aria-modal="true" aria-label="Pratinjau">
    <div class="m-head"><div><h3 id="play-title">Pratinjau</h3><p id="play-sub"></p></div><button class="btn sm ghost" data-close>Tutup</button></div>
    <video id="pv" playsinline controls></video>
    <div class="cap" id="play-cap"></div>
  </div>
</div>

<script src="assets/common.js?v=<?= filemtime(__DIR__ . '/assets/common.js') ?>"></script>
<script>
const CID = <?= json_encode($cid) ?>;
const CHUNK = 8 * 1024 * 1024;
const fmtT = s => { s = Math.max(0, +s || 0); return `${Math.floor(s / 60)}:${(s % 60).toFixed(1).padStart(4, '0')}`; };
const segLen = s => Math.max(0, s.end - s.start);
const fmtAgo = ts => { const s = Math.max(0, Math.round(Date.now() / 1000 - ts)); return s < 60 ? `${s} dtk` : `${Math.floor(s / 60)} mnt ${s % 60} dtk`; };
let D = null, plan = null, accounts = [], uploads = {}, poll = null, saveT = null, wasPlanning = false;
// Penyusunan AI berjalan di latar belakang (worker); status dibaca dari campaign_get
const planning = () => ['queued', 'converting'].includes(D?.plan_job?.status);

/* ---------- Daftar campaign ---------- */
async function listView() {
  const d = await api('campaign_list');
  $('app').innerHTML = `<div class="card head"><div class="head-row"><div>
      <div class="eyebrow"><span class="grad-text">Clip campaign</span></div>
      <h1>Clip dari footage sendiri</h1>
      <p>Unggah bahan footage dari komputer, tulis angle konten, lalu AI memilih & menyusun adegan yang cocok lengkap dengan hook.</p></div>
      <button class="btn primary" id="new">${ic('plus')} Campaign baru</button></div></div>
    ${d.campaigns.length ? `<div class="clist">${d.campaigns.map(c => `<a class="card ccard" href="campaign.php?id=${c.id}">
        <div class="th" style="${c.thumb ? `background-image:url('${esc(c.thumb)}')` : ''}">${c.thumb ? '' : ic('film')}</div>
        <div class="bd"><b>${esc(c.title)}</b><small>${c.files} footage${c.angle ? ' · ' + esc(c.angle) : ''}${c.builds ? ` · ${c.builds} clip dibuat` : ''}</small></div></a>`).join('')}</div>`
      : '<div class="card sec"><div class="empty"><b>Belum ada campaign</b>Klik "Campaign baru" untuk mulai dari footage di komputer.</div></div>'}`;
  $('new').onclick = async () => {
    const title = prompt('Nama campaign (mis. nama film / brand campaign):', '');
    if (title === null) return;
    const r = await api('campaign_create', { title });
    location.href = 'campaign.php?id=' + r.campaign.id;
  };
}

/* ---------- Satu campaign ---------- */
async function load() {
  try { D = await api('campaign_get', null, '&id=' + CID); }
  catch (err) { $('app').innerHTML = `<div class="card sec"><div class="empty"><b>Campaign tidak ditemukan</b>${esc(err.message)}</div></div>`; return; }
  if (!plan || D.campaign.plan?.made_at !== plan.made_at) plan = D.campaign.plan ? JSON.parse(JSON.stringify(D.campaign.plan)) : null;
  // Baru selesai menyusun: beri tahu & arahkan ke hasilnya
  if (wasPlanning && !planning()) {
    if (D.plan_job?.status === 'done') { toast('Clip tersusun'); setTimeout(() => $('sec-plan')?.scrollIntoView({ behavior: 'smooth', block: 'start' }), 100); }
    else if (D.campaign.plan_error) toast('Gagal menyusun clip');
  }
  wasPlanning = planning();
  render();
  clearTimeout(poll);
  const busy = planning() || D.files.some(f => ['uploading', 'queued', 'analyzing'].includes(f.status)) || D.builds.some(b => ['queued', 'converting'].includes(b.status) || (b.status === 'done' && !b.project_id));
  if (busy || Object.keys(uploads).length) poll = setTimeout(load, 2000);
}
const fileOf = fid => D.files.find(f => f.fid === fid);
const sceneOf = (f, sid) => f?.scenes?.find(s => s.sid === sid);
// Thumbnail adegan terdekat dengan titik tengah segmen
function segThumb(s) {
  const f = fileOf(s.fid);
  if (!f?.scenes) return f?.thumb || '';
  const mid = (s.start + s.end) / 2;
  return (f.scenes.find(x => mid >= x.start && mid <= x.end) || f.scenes[0]).thumb || f.thumb || '';
}

function render() {
  const c = D.campaign, ready = D.files.filter(f => f.status === 'done');
  const totalDur = plan ? plan.segments.reduce((a, s) => a + segLen(s), 0) : 0;
  // Field yang sedang diketik tetap utuh walau halaman diperbarui otomatis (polling)
  const act = document.activeElement, keep = act?.id && $('app').contains(act) && /^(INPUT|TEXTAREA|SELECT)$/.test(act.tagName)
    ? { id: act.id, value: act.value, a: act.selectionStart, b: act.selectionEnd } : null;
  $('app').innerHTML = `
    <div class="card head">
      <div class="eyebrow"><span class="grad-text">Clip campaign</span></div>
      <input class="title-in" id="c-title" value="${esc(c.title)}" maxlength="100" aria-label="Nama campaign">
      <p>Niche: ${Object.entries(D.niches).map(([k, n]) => `<button class="chip${c.niche === k ? ' on' : ''}" data-niche="${k}" ${n.on ? '' : 'disabled title="Segera hadir"'}>${esc(n.label)}${n.on ? '' : ' <small>segera</small>'}</button>`).join(' ')}</p>
    </div>

    <section class="card sec">
      <h2><span class="no">1</span> Bahan footage</h2>
      <p>Unggah footage dari komputer (MP4, MOV, MKV, WEBM). AI menonton tiap footage dan memecahnya jadi adegan.</p>
      <label class="drop" id="drop">${ic('upload')}<b>Klik atau seret file video ke sini</b><br><small>Bisa beberapa file sekaligus</small>
        <input type="file" id="file-in" accept="video/*,.mkv" multiple hidden></label>
      ${D.files.length || Object.keys(uploads).length ? `<div class="files">${D.files.map(fileCard).join('')}${Object.values(uploads).filter(u => !u.fid || !fileOf(u.fid)).map(u => fileCard({ name: u.name, status: 'uploading', local: u })).join('')}</div>` : ''}
    </section>

    <section class="card sec">
      <h2><span class="no">2</span> Konsep clip</h2>
      <p>Tulis angle konten yang ingin diangkat. AI hanya memakai adegan yang mendukung angle ini.</p>
      <label class="lbl" for="angle">Angle / konsep</label>
      <textarea class="input" id="angle" rows="2" maxlength="300" placeholder="mis. persahabatan yang diuji pengkhianatan, atau pengorbanan seorang ayah">${esc(c.angle)}</textarea>
      <label class="lbl">Durasi clip</label>
      <div class="chips">${D.targets.map(t => `<button class="chip${c.target === t ? ' on' : ''}" data-target="${t}">±${t} dtk</button>`).join('')}</div>
      <label class="lbl">Bahasa hook &amp; caption</label>
      <div class="chips"><button class="chip${c.lang === 'id' ? ' on' : ''}" data-lang="id">Indonesia</button><button class="chip${c.lang === 'en' ? ' on' : ''}" data-lang="en">English</button></div>
      <div class="go-row"><button class="btn primary" id="plan-go" ${ready.length && c.angle.trim() && !planning() ? '' : 'disabled'}>
          ${planning() ? '<span class="spin"></span> AI sedang menyusun…' : `${ic('sparkles')} ${plan ? 'Susun ulang dengan AI' : 'Susun clip dengan AI'}`}</button>
        <span class="muted">${!ready.length ? 'Tunggu minimal 1 footage selesai dianalisa.' : !c.angle.trim() ? 'Isi angle dulu.' : `${ready.length} footage siap · ${ready.reduce((a, f) => a + (f.scenes?.length || 0), 0)} adegan`}</span></div>
      ${planning() ? `<div class="plan-st"><span class="spin"></span><div><b>${esc(D.plan_job.stage || 'Menunggu giliran…')}</b>
          <small>Berjalan ${fmtAgo(D.plan_job.started)} · biasanya 30–90 detik, bisa lebih lama kalau server AI sedang sibuk. Halaman ini boleh ditutup, prosesnya tetap jalan.</small></div></div>`
        : c.plan_error ? `<div class="plan-st err">${ic('ban')}<div><b>Gagal menyusun clip</b><small>${esc(c.plan_error)}</small></div>
          <button class="btn sm" id="plan-retry">${ic('refresh')} Coba lagi</button></div>` : ''}
    </section>

    ${plan ? `<section class="card sec" id="sec-plan">
      <h2><span class="no">3</span> Susunan clip</h2>
      <p>Urutan tayang hasil AI. Titik potong sudah dirapikan ke awal/akhir kalimat & pergantian shot. Ubah urutan, potong, hapus, atau tambah adegan, lalu buat clip.</p>
      ${plan.story ? `<div class="story">${ic('sparkles')}<div><b>Alur cerita</b><span>${esc(plan.story)}</span></div></div>` : ''}
      <div class="plan-grid">
        <div>
          <div class="tot"><span>${plan.segments.length} potongan · <b>${fmtT(totalDur)}</b> ${Math.abs(totalDur - c.target) > c.target * 0.25 ? `<span style="color:#f5a524">(target ±${c.target} dtk)</span>` : ''}</span>
            <span class="btns"><button class="btn sm" id="play-all" ${plan.segments.length ? '' : 'disabled'}>${ic('play')} Putar semua</button>
              <button class="btn sm" id="add-seg">${ic('plus')} Tambah adegan</button></span></div>
          <div class="segs">${plan.segments.map(segRow).join('') || '<div class="empty">Susunan kosong. Tambah adegan.</div>'}</div>
          ${plan.unused?.length ? `<div class="unused"><b>Tidak dipakai AI:</b><ul>${plan.unused.map(u => `<li>${esc(fileOf(u.fid)?.code || '')} ${esc(fileOf(u.fid)?.name || '')} — ${esc(u.reason)}</li>`).join('')}</ul></div>` : ''}
        </div>
        <div class="side">
          <label class="lbl" for="p-hook" style="margin-top:0">Hook (teks di layar awal video)</label>
          <input class="input hook-in" id="p-hook" maxlength="120" value="${esc(plan.hook_text)}">
          <label class="lbl" for="p-title">Judul</label>
          <input class="input" id="p-title" maxlength="120" value="${esc(plan.title)}">
          <label class="lbl" for="p-cap">Caption</label>
          <textarea class="input" id="p-cap" rows="4" maxlength="600">${esc(plan.caption)}</textarea>
          <label class="lbl" for="p-tags">Hashtag</label>
          <input class="input" id="p-tags" value="${esc((plan.hashtags || []).join(' '))}">
          <div class="go-row"><button class="btn primary" id="build" ${plan.segments.length ? '' : 'disabled'}>${ic('film')} Buat clip</button>
            <span class="muted">Dirakit jadi room di Clip Studio, lalu bisa diedit di editor.</span></div>
        </div>
      </div>
      ${D.builds.length ? `<div class="builds">${D.builds.map(buildRow).join('')}</div>` : ''}
    </section>` : ''}`;
  if (keep && $(keep.id)) {
    const el = $(keep.id);
    el.value = keep.value;
    el.focus();
    try { if (keep.a != null) el.setSelectionRange(keep.a, keep.b); } catch {}
  }
}

function fileCard(f) {
  const u = f.local || Object.values(uploads).find(x => x.fid && x.fid === f.fid);
  let st;
  if (u && f.status === 'uploading') st = `<div class="st"><span class="spin" style="width:11px;height:11px"></span> Mengunggah ${Math.round(u.pct)}%</div><div class="bar"><i style="width:${u.pct}%"></i></div>`;
  else if (f.status === 'done') st = `<div class="st ok">${ic('check')} ${f.scenes.length} adegan · ${fmtT(f.duration)}</div>`;
  else if (f.status === 'error') st = `<div class="st err">${esc(f.error || 'Gagal')}</div><button class="btn sm retry" data-retry="${f.fid}">${ic('refresh')} Coba lagi</button>`;
  else st = `<div class="st"><span class="spin" style="width:11px;height:11px"></span> ${esc(f.stage || 'Menunggu…')}</div><div class="bar"><i style="width:${f.progress || 3}%"></i></div>`;
  return `<div class="file"><div class="th" style="${f.thumb ? `background-image:url('${esc(f.thumb)}')` : ''}">${f.code ? `<span class="code">${f.code}</span>` : ''}
      ${f.fid ? `<button class="del" data-del="${f.fid}" title="Hapus bahan" aria-label="Hapus bahan">${ic('trash')}</button>` : ''}</div>
    <div class="bd"><div class="nm" title="${esc(f.name)}">${esc(f.name)}</div>${st}</div></div>`;
}

function segRow(s, i) {
  const f = fileOf(s.fid), n = plan.segments.length;
  return `<div class="seg-row" data-i="${i}">
    <span class="n${i === 0 ? ' hook' : ''}" title="${i === 0 ? 'Pembuka (hook)' : ''}">${i + 1}</span>
    <button class="th" data-play="${i}" style="background-image:url('${esc(segThumb(s))}')" title="Putar potongan">${ic('play')}</button>
    <div class="tx"><div class="t1"><span class="fc">${esc(f?.code || '?')}</span>
        <span class="tm"><input type="number" step="0.1" min="0" data-st="${i}" value="${s.start.toFixed(1)}" aria-label="Mulai">–<input type="number" step="0.1" min="0" data-en="${i}" value="${s.end.toFixed(1)}" aria-label="Selesai"> · ${segLen(s).toFixed(1)} dtk</span></div>
      ${segSpeech(s) ? `<div class="dlg" title="${esc(segSpeech(s))}">“${esc(segSpeech(s))}”</div>` : ''}
      <div class="rs" title="${esc(s.reason)}">${esc(s.reason || f?.name || '')}</div></div>
    <div class="ac"><button data-up="${i}" ${i ? '' : 'disabled'} title="Naik">${ic('chevron-down')}</button>
      <button data-down="${i}" ${i < n - 1 ? '' : 'disabled'} title="Turun">${ic('chevron-down')}</button>
      <button class="rm" data-rm="${i}" title="Hapus potongan">${ic('trash')}</button></div></div>`;
}

// Ucapan (transkrip) di dalam potongan: untuk menilai apakah kalimatnya utuh
function segSpeech(s) {
  const ls = (fileOf(s.fid)?.lines || []).filter(l => l.e > s.start + 0.1 && l.s < s.end - 0.1);
  return ls.map(l => l.t).join(' ');
}

function buildRow(b) {
  const active = ['queued', 'converting'].includes(b.status) || (b.status === 'done' && !b.project_id);
  const when = new Date(b.at * 1000).toLocaleString('id-ID', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
  return `<div class="build"><div class="tx"><b>${esc(b.title)}</b><small>${when} · ${b.segments} potongan · ${fmtT(b.duration)} · angle: ${esc(b.angle)}</small>
      ${active ? `<small style="display:block;margin-top:4px">${esc(b.stage || 'Menyiapkan…')}</small><div class="bar"><i style="width:${b.progress || 2}%"></i></div>` : ''}
      ${b.status === 'error' ? `<small class="err" style="display:block">${esc(b.error || 'Gagal')}</small>` : ''}</div>
    ${b.project_id && b.exists ? `<a class="btn sm" href="studio.php?id=${b.project_id}">${ic('layers')} Clip Studio</a>
      <a class="btn sm primary" href="editor.php?project=${b.project_id}&item=${b.iid}">${ic('pencil')} Buka editor</a>` : b.project_id ? '<small class="muted">Room sudah dihapus</small>' : ''}</div>`;
}

/* ---------- Simpan perubahan ---------- */
const save = patch => api('campaign_update', { id: CID, ...patch }).then(d => { D.campaign = d.campaign; }).catch(err => toast(err.message));
function savePlanSoon() { clearTimeout(saveT); saveT = setTimeout(() => save({ plan }), 600); }
function readSide() {
  if (!plan || !$('p-hook')) return;
  plan.hook_text = $('p-hook').value.trim();
  plan.title = $('p-title').value.trim();
  plan.caption = $('p-cap').value.trim();
  plan.hashtags = $('p-tags').value.split(/[\s,]+/).filter(Boolean).map(h => '#' + h.replace(/^#+/, ''));
}

/* ---------- Unggah (per potongan 8 MB) ---------- */
async function uploadFiles(list) {
  for (const file of list) {
    const key = Math.random().toString(36).slice(2);
    uploads[key] = { name: file.name, pct: 0, fid: '' };
    render();
    const total = Math.max(1, Math.ceil(file.size / CHUNK));
    try {
      for (let i = 0; i < total; i++) {
        const fd = new FormData();
        fd.append('id', CID); fd.append('idx', i); fd.append('total', total); fd.append('name', file.name); fd.append('size', file.size);
        if (uploads[key].fid) fd.append('fid', uploads[key].fid);
        fd.append('chunk', file.slice(i * CHUNK, (i + 1) * CHUNK), 'chunk');
        const res = await fetch('api.php?action=campaign_upload', { method: 'POST', body: fd });
        const d = await res.json().catch(() => ({ error: 'Respons server tidak valid.' }));
        if (!res.ok || d.error) throw new Error(d.error || 'Unggah gagal.');
        uploads[key].fid = d.fid;
        uploads[key].pct = (i + 1) / total * 100;
        if (i === 0 || i % 3 === 2) await load(); else render();
      }
    } catch (err) { toast(`${file.name}: ${err.message}`); }
    delete uploads[key];
    await load();
  }
}

/* ---------- Pemutar potongan (pakai video ringan 360p) ---------- */
let playQ = [], playK = 0;
function playSegs(list, title) {
  playQ = list.filter(s => fileOf(s.fid)?.preview);
  if (!playQ.length) return toast('Pratinjau belum tersedia untuk footage ini.');
  playK = 0;
  $('play-title').textContent = title;
  $('play').hidden = false;
  playCur();
}
function playCur() {
  const s = playQ[playK], f = fileOf(s.fid), v = $('pv');
  $('play-sub').textContent = `${playK + 1}/${playQ.length} · ${f.code} ${f.name} · ${fmtT(s.start)}–${fmtT(s.end)}`;
  $('play-cap').textContent = s.reason || '';
  const go = () => { v.currentTime = s.start; v.play().catch(() => {}); };
  if (!v.src.endsWith(f.preview)) { v.src = f.preview; v.addEventListener('loadedmetadata', go, { once: true }); } else go();
}
$('pv').addEventListener('timeupdate', () => {
  const s = playQ[playK];
  if (!s || $('play').hidden || $('pv').currentTime < s.end) return;
  if (playK < playQ.length - 1) { playK++; playCur(); } else $('pv').pause();
});
function closeModals() { $('play').hidden = $('pick').hidden = true; $('pv').pause(); }
document.querySelectorAll('.modal').forEach(m => m.addEventListener('click', e => { if (e.target === m || e.target.closest('[data-close]')) closeModals(); }));
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModals(); });

function openPicker() {
  $('pick-list').innerHTML = D.files.filter(f => f.status === 'done').map(f => `<div class="pick-f"><h4>${f.code} · ${esc(f.name)}</h4>
    <div class="pick-s">${f.scenes.map(s => `<button class="scn${s.usable ? '' : ' na'}" data-add="${f.fid}:${s.sid}" title="${esc(s.description)}">
      <span class="th" style="background-image:url('${esc(s.thumb || '')}')"></span><b>${s.sid} · ${fmtT(s.start)}–${fmtT(s.end)} · ${esc(s.emotion)}</b>
      <small>${esc(s.description)}</small></button>`).join('')}</div></div>`).join('') || '<div class="empty">Belum ada footage yang selesai dianalisa.</div>';
  $('pick').hidden = false;
}
$('pick-list').addEventListener('click', e => {
  const b = e.target.closest('[data-add]');
  if (!b) return;
  const [fid, sid] = b.dataset.add.split(':'), s = sceneOf(fileOf(fid), sid);
  plan.segments.push({ id: Math.random().toString(16).slice(2, 10).padEnd(8, '0'), fid, scene: sid, start: s.start, end: s.end, reason: 'Ditambahkan manual · ' + s.description });
  savePlanSoon(); render();
  toast(`Adegan ${fileOf(fid).code}-${sid} ditambahkan`);
});

/* ---------- Event halaman ---------- */
document.addEventListener('click', async e => {
  const t = e.target;
  const b = t.closest('button');
  if (!b || !$('app').contains(b)) return;
  if (b.dataset.niche) { await save({ niche: b.dataset.niche }); return render(); }
  if (b.dataset.target) { await save({ target: +b.dataset.target }); return render(); }
  if (b.dataset.lang) { await save({ lang: b.dataset.lang }); return render(); }
  if (b.dataset.del) {
    const f = fileOf(b.dataset.del);
    if (!confirm(`Hapus bahan "${f.name}"? Potongan dari footage ini di susunan ikut dihapus.`)) return;
    D.campaign = (await api('campaign_file_delete', { id: CID, fid: b.dataset.del })).campaign;
    if (plan) plan.segments = plan.segments.filter(s => s.fid !== b.dataset.del);
    return load();
  }
  if (b.dataset.retry) { await api('campaign_file_retry', { id: CID, fid: b.dataset.retry }); return load(); }
  if (b.id === 'plan-go' || b.id === 'plan-retry') {
    if (b.id === 'plan-go' && plan && !confirm('Susun ulang dengan AI? Susunan sekarang akan diganti.')) return;
    b.disabled = true;
    try { await api('campaign_plan', { id: CID }); }
    catch (err) { D.campaign.plan_error = err.message; render(); return; }
    return load();
  }
  if (b.id === 'add-seg') return openPicker();
  if (b.id === 'play-all') return playSegs(plan.segments, 'Putar semua potongan');
  if (b.dataset.play !== undefined) return playSegs([plan.segments[+b.dataset.play]], `Potongan ${+b.dataset.play + 1}`);
  const mv = (i, d) => { const a = plan.segments; [a[i], a[i + d]] = [a[i + d], a[i]]; savePlanSoon(); render(); };
  if (b.dataset.up !== undefined) return mv(+b.dataset.up, -1);
  if (b.dataset.down !== undefined) return mv(+b.dataset.down, 1);
  if (b.dataset.rm !== undefined) { plan.segments.splice(+b.dataset.rm, 1); savePlanSoon(); return render(); }
  if (b.id === 'build') {
    readSide();
    b.disabled = true;
    try { await api('campaign_build', { id: CID, plan }); toast('Clip sedang dirakit…'); }
    catch (err) { toast(err.message); }
    return load();
  }
});
document.addEventListener('change', async e => {
  const t = e.target;
  if (t.id === 'file-in') { uploadFiles([...t.files]); t.value = ''; return; }
  if (t.id === 'c-title') return save({ title: t.value });
  if (t.id === 'angle') { await save({ angle: t.value }); return render(); }
  if (t.id === 'acc') return save({ account_id: t.value });
  if (t.dataset.st !== undefined || t.dataset.en !== undefined) {
    const i = +(t.dataset.st ?? t.dataset.en), s = plan.segments[i], f = fileOf(s.fid), v = Math.max(0, Math.min(f?.duration || 1e9, parseFloat(t.value) || 0));
    if (t.dataset.st !== undefined) s.start = Math.min(v, s.end - 0.3); else s.end = Math.max(v, s.start + 0.3);
    savePlanSoon(); return render();
  }
  if (['p-hook', 'p-title', 'p-cap', 'p-tags'].includes(t.id)) { readSide(); savePlanSoon(); }
});
document.addEventListener('input', e => {
  if (e.target.id === 'angle') { const go = $('plan-go'); if (go) go.disabled = !e.target.value.trim() || !D.files.some(f => f.status === 'done') || planning(); }
});
['dragover', 'dragenter'].forEach(ev => document.addEventListener(ev, e => { const d = e.target.closest?.('#drop'); if (d) { e.preventDefault(); d.classList.add('over'); } }));
document.addEventListener('dragleave', e => e.target.closest?.('#drop')?.classList.remove('over'));
document.addEventListener('drop', e => {
  const d = e.target.closest?.('#drop');
  if (!d) return;
  e.preventDefault();
  d.classList.remove('over');
  uploadFiles([...e.dataTransfer.files].filter(f => f.type.startsWith('video/') || /\.(mkv|mov|m4v|webm|avi|mp4)$/i.test(f.name)));
});

if (CID) {
  api('accounts').then(d => { accounts = d.accounts || []; }).catch(() => {}).finally(load);
} else listView();
</script>
</body>
</html>
