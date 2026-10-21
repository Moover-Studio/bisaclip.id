<?php
require __DIR__ . '/config.php';
require_local();
ensure_dirs();
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="dark">
<meta name="theme-color" content="#0a0b0d">
<link rel="icon" type="image/png" href="assets/favicon-32.png">
<title>Akun · BisaClip</title>
<link rel="stylesheet" href="assets/app.css?v=<?= filemtime(__DIR__ . '/assets/app.css') ?>">
<style>
  .eyebrow { font-size: 12px; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; margin-bottom: 4px; }
  .head { display: flex; gap: 16px; align-items: center; flex-wrap: wrap; padding: 18px 20px;
    background: linear-gradient(120deg, rgba(0, 88, 211, .14), transparent 55%), var(--surface); }
  .head h1 { font-size: 20px; margin: 0 0 4px; }
  .head p { margin: 0; font-size: 13px; color: var(--muted); line-height: 1.5; }
  .head .grow { flex: 1; min-width: 240px; }

  .av { flex: none; border-radius: 50%; display: grid; place-items: center; color: #fff; font-weight: 700; }
  .accs { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 14px; margin-top: 18px; }
  .acc { display: flex; flex-direction: column; gap: 14px; padding: 18px; color: inherit; text-decoration: none; transition: transform .15s, border-color .15s; }
  .acc:hover { border-color: var(--surface-3); box-shadow: 0 8px 24px rgba(0, 0, 0, .35); }
  .acc .top { display: flex; gap: 12px; align-items: center; min-width: 0; }
  .acc .av { width: 52px; height: 52px; font-size: 21px; }
  .acc .name { font-size: 15.5px; font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .acc .handle { font-size: 12.5px; color: var(--muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .acc .niche { align-self: flex-start; }
  .acc .nums { display: grid; grid-template-columns: repeat(3, 1fr); gap: 6px; text-align: center; padding-top: 12px; border-top: 1px solid var(--border); }
  .acc .nums b { display: block; font-size: 17px; font-variant-numeric: tabular-nums; }
  .acc .nums small { font-size: 11px; color: var(--muted); }
  .acc .last { font-size: 12px; color: var(--muted); margin-top: -4px; }

  /* Modal akun baru */
  .modal { position: fixed; inset: 0; z-index: 50; display: grid; place-items: center; padding: 16px; background: rgba(5, 6, 8, .72); backdrop-filter: blur(6px); }
  .modal[hidden] { display: none; }
  .m-card { width: 100%; max-width: 420px; padding: 22px; }
  .m-card h3 { margin: 0 0 16px; font-size: 17px; }
  .m-card label { display: block; font-size: 12px; font-weight: 500; color: var(--muted); margin: 12px 0 6px; }
  .m-card .input { padding: 9px 12px; font-size: 13.5px; }
  .colors { display: flex; gap: 8px; flex-wrap: wrap; }
  .colors button { width: 28px; height: 28px; border-radius: 50%; border: 2px solid transparent; cursor: pointer; padding: 0; }
  .colors button.on { border-color: #fff; box-shadow: 0 0 0 2px rgba(255, 255, 255, .25); }
  .m-foot { display: flex; justify-content: flex-end; gap: 8px; margin-top: 20px; }
</style>
</head>
<body>
<div class="wrap">
  <header class="topbar">
    <a class="back" href="index.php" title="Kembali ke Beranda" aria-label="Kembali ke Beranda"><?= icon('arrow-left') ?></a>
    <a class="brand" href="index.php"><img src="assets/logo-96.png" alt=""><span><b>Bisa</b>Clip</span></a>
  </header>

  <div class="card head">
    <div class="grow">
      <div class="eyebrow"><span class="grad-text">Akun</span></div>
      <h1>Akun sosmed kamu</h1>
      <p>Pisahkan clip per akun, misalnya akun self development dan akun clip game. Setiap video baru dihubungkan ke salah satu akun.</p>
    </div>
    <button class="btn primary" id="new"><?= icon('plus') ?> Akun baru</button>
  </div>

  <div class="accs" id="list"><div class="empty" style="grid-column:1/-1"><span class="spin"></span></div></div>
</div>

<div class="modal" id="modal" hidden>
  <form class="card m-card" id="form" role="dialog" aria-modal="true" aria-labelledby="m-title">
    <h3 id="m-title">Akun baru</h3>
    <label for="f-name">Nama akun</label>
    <input class="input" id="f-name" maxlength="40" placeholder="mis. Mindset Harian" autocomplete="off" required>
    <label for="f-handle">Username (opsional)</label>
    <input class="input" id="f-handle" maxlength="41" placeholder="@mindsetharian" autocomplete="off">
    <label for="f-niche">Niche (opsional)</label>
    <input class="input" id="f-niche" maxlength="60" placeholder="mis. Self development" autocomplete="off">
    <label>Warna</label>
    <div class="colors" id="colors"></div>
    <div class="m-foot">
      <button class="btn ghost" type="button" id="m-cancel">Batal</button>
      <button class="btn primary" type="submit" id="m-save">Simpan</button>
    </div>
  </form>
</div>

<script src="assets/common.js?v=<?= filemtime(__DIR__ . '/assets/common.js') ?>"></script>
<script>
let D = null, color = '';
const dayLabel = s => { const [y, m, d] = s.split('-').map(Number);
  return new Date(y, m - 1, d).toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' }); };

async function load() {
  try { D = await api('accounts'); }
  catch (err) { $('list').innerHTML = `<div class="empty" style="grid-column:1/-1">${esc(err.message)}</div>`; return; }
  $('list').innerHTML = D.accounts.length ? D.accounts.map(a => `<a class="card acc" href="account.php?id=${a.id}">
      <div class="top">${accAvatar(a)}
        <div style="min-width:0"><div class="name">${esc(a.name)}</div><div class="handle">${a.handle ? '@' + esc(a.handle) : 'Belum ada username'}</div></div></div>
      ${a.niche ? `<span class="pill accent niche">${esc(a.niche)}</span>` : ''}
      <div class="nums"><div><b>${a.uploaded}</b><small>Diupload</small></div><div><b>${a.clips}</b><small>Clip</small></div><div><b>${a.rooms}</b><small>Room</small></div></div>
      <div class="last">${a.last_upload ? `Upload terakhir ${dayLabel(a.last_upload)}` : 'Belum ada upload'}</div>
    </a>`).join('')
    : '<div class="card empty" style="grid-column:1/-1"><b>Belum ada akun</b>Buat akun pertama, lalu pilih akun itu saat membuat clip dari video baru.</div>';
}

function openNew() {
  $('form').reset();
  color = D.colors[D.accounts.length % D.colors.length];
  paintColors();
  $('modal').hidden = false;
  $('f-name').focus();
}
function paintColors() {
  $('colors').innerHTML = D.colors.map(c => `<button type="button" class="${c === color ? 'on' : ''}" data-c="${c}" style="background:${c}" aria-label="Warna ${c}"></button>`).join('');
}
$('colors').onclick = e => { const b = e.target.closest('[data-c]'); if (b) { color = b.dataset.c; paintColors(); } };
$('new').onclick = () => D && openNew();
$('m-cancel').onclick = () => { $('modal').hidden = true; };
$('modal').addEventListener('click', e => { if (e.target === $('modal')) $('modal').hidden = true; });
document.addEventListener('keydown', e => { if (e.key === 'Escape') $('modal').hidden = true; });
$('form').onsubmit = async e => {
  e.preventDefault();
  $('m-save').disabled = true;
  try {
    const { account } = await api('account_save', { name: $('f-name').value, handle: $('f-handle').value, niche: $('f-niche').value, color });
    location.href = 'account.php?id=' + account.id;
  } catch (err) { toast(err.message); $('m-save').disabled = false; }
};
if (location.hash === '#baru') load().then(openNew); else load();
</script>
</body>
</html>
