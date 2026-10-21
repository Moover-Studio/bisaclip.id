<?php
require __DIR__ . '/config.php';
require_local();
ensure_dirs();
$id = (string) ($_GET['id'] ?? '');
if (!valid_job_id($id)) {
    header('Location: index.php');
    exit;
}
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="dark">
<meta name="theme-color" content="#0a0b0d">
<link rel="icon" type="image/png" href="assets/favicon-32.png">
<title>Clip Studio · BisaClip</title>
<link rel="stylesheet" href="assets/app.css?v=<?= filemtime(__DIR__ . '/assets/app.css') ?>">
<style>
  /* Header room: thumbnail | label + akun, judul, ringkasan | tombol */
  .head { display: grid; grid-template-columns: 208px minmax(0, 1fr) auto; grid-template-areas: "thumb main actions";
    gap: 22px; align-items: center; padding: 18px 22px 18px 18px; background: linear-gradient(120deg, rgba(0, 88, 211, .14), transparent 55%), var(--surface); }
  .h-thumb { grid-area: thumb; position: relative; aspect-ratio: 16/9; border-radius: 10px; overflow: hidden; background: var(--surface-2);
    box-shadow: inset 0 0 0 1px var(--border); }
  .h-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
  .h-main { grid-area: main; min-width: 0; display: flex; flex-direction: column; gap: 8px; }
  .h-meta { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; min-height: 24px; }
  .eyebrow { font-size: 11.5px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; }
  .head h1 { font-size: 18px; line-height: 1.38; margin: 0; overflow-wrap: anywhere;
    display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
  .head-actions { grid-area: actions; display: flex; flex-direction: column; gap: 6px; min-width: 150px; }
  .head-actions .btn { justify-content: center; padding: 7px 14px; font-size: 13px; }
  .head-actions .btn .i { width: 14px; height: 14px; }
  /* Selesaikan room: hijau bergaris supaya terlihat tapi tidak menyaingi tombol utama */
  .btn.finish { background: rgba(31, 157, 92, .1); color: #4fd28a; box-shadow: inset 0 0 0 1px rgba(79, 210, 138, .38); font-weight: 600; }
  .btn.finish:hover { background: rgba(31, 157, 92, .2); color: #6be3a0; }
  .btn.reopen { background: var(--surface-2); color: var(--text-2); box-shadow: inset 0 0 0 1px var(--border-strong); }
  .btn.reopen:hover { background: var(--surface-3); color: var(--text); }
  .stats { display: flex; gap: 6px; flex-wrap: wrap; }
  /* Bahasa judul & caption yang tampil + disalin: Indonesia / English */
  .lang-bar { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-top: 10px; font-size: 12.5px; color: var(--muted); }
  .lang-seg { display: inline-flex; gap: 2px; padding: 3px; border: 1px solid var(--border); border-radius: 10px; background: var(--surface-2); }
  .lang-seg button { height: 26px; padding: 0 11px; border: 0; border-radius: 7px; background: none; color: var(--muted); font: inherit; font-size: 12px; font-weight: 700; cursor: pointer; }
  .lang-seg button:hover { color: var(--text); }
  .lang-seg button.on { background: var(--accent); color: #fff; }
  .lang-bar .lnk { border: 0; background: none; padding: 0; color: var(--accent-fg); font: inherit; font-weight: 600; cursor: pointer; }
  .lang-bar .lnk:disabled { color: var(--muted); cursor: default; }
  .c-title .en { display: inline-block; margin-right: 5px; padding: 0 5px; border-radius: 4px; background: rgba(30, 112, 240, .2); color: var(--accent-fg);
    font-size: 10px; font-weight: 800; vertical-align: 2px; }
  .cpt { align-self: flex-start; display: inline-block; padding: 2px 8px; margin-bottom: -2px; border-radius: 999px; font-size: 10.5px; font-weight: 700;
    background: rgba(91, 79, 214, .2); color: #c9c3ff; }
  .cp-row { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; }
  .dl-two { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; }
  .dl-two .btn { min-width: 0; padding-left: 6px; padding-right: 6px; }
  @media (max-width: 1024px) {
    .head { grid-template-columns: 168px minmax(0, 1fr); grid-template-areas: "thumb main" "actions actions"; }
    .head-actions { flex-direction: row; min-width: 0; }
  }
  /* Akun pemilik room (dipilih saat membuat clip, hanya ditampilkan di sini) */
  .acc-line { display: inline-flex; align-items: center; gap: 6px; max-width: 100%; padding: 2px 10px 2px 3px; border-radius: 999px;
    border: 1px solid var(--border); background: var(--surface-2); font-size: 12.5px; font-weight: 600; color: var(--text-2); text-decoration: none; }
  .acc-line:hover { color: var(--text); border-color: var(--border-strong); }
  .acc-line .av { flex: none; width: 20px; height: 20px; border-radius: 50%; display: grid; place-items: center; color: #fff; font-weight: 700; font-size: 10px; }
  .acc-line .nm { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .acc-line[hidden] { display: none; }

  /* Grid clip vertikal */
  .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr)); gap: 18px; margin-top: 22px; }
  .clip { overflow: hidden; display: flex; flex-direction: column; }
  .screen { position: relative; aspect-ratio: 9/16; background: #000; overflow: hidden; }
  .screen video { width: 100%; height: 100%; object-fit: cover; display: block; background: #000; }
  .screen .bg { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; filter: blur(14px) brightness(.45); transform: scale(1.15); }
  .screen .state { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center;
    gap: 10px; padding: 20px; text-align: center; color: #fff; font-size: 13px; }
  .screen .state .progress { width: 70%; background: rgba(255, 255, 255, .2); }
  .screen .state .big { font-size: 22px; font-weight: 700; }
  .screen .state .btn { margin-top: 4px; }
  .screen .ed { position: absolute; right: 10px; top: 10px; font-size: 10px; font-weight: 700; padding: 2px 6px; border-radius: 5px; line-height: 1.4;
    pointer-events: none; background: rgba(0, 0, 0, .6); color: rgba(255, 255, 255, .85); }
  .screen .ed.yes { background: var(--ok); color: #fff; }
  .screen .badge { position: absolute; left: 10px; top: 10px; font-size: 11.5px; font-weight: 700; color: #fff;
    background: rgba(0, 0, 0, .6); padding: 3px 8px; border-radius: 6px; pointer-events: none; }
  /* Label hasil render yang sudah di-AI upscale ke 2K */
  .screen .up { position: absolute; right: 10px; top: 33px; display: inline-flex; align-items: center; gap: 3px; font-size: 10px; font-weight: 700;
    line-height: 1.4; color: #fff; padding: 2px 6px; border-radius: 5px; background: var(--grad); box-shadow: 0 2px 8px rgba(0, 0, 0, .35); pointer-events: none; }
  .screen .up .i { width: 10px; height: 10px; stroke-width: 3; }
  .c-body { padding: 12px 14px 14px; display: flex; flex-direction: column; gap: 8px; flex: 1; }
  .c-title { font-weight: 600; font-size: 14px; line-height: 1.4; display: -webkit-box; -webkit-line-clamp: 2;
    -webkit-box-orient: vertical; overflow: hidden; }
  .c-meta { font-size: 12px; color: var(--muted); }
  .c-actions { display: grid; grid-template-columns: 1fr; gap: 6px; margin-top: auto; padding-top: 4px; }
  /* Status upload: tombol Done upload, badge & baris tanggal di bagian "Sudah diupload" */
  .btn.done-up { background: var(--ok-soft); color: var(--ok); font-weight: 600; }
  .btn.done-up:hover { background: rgba(31, 157, 92, .28); }
  .screen .upl { position: absolute; left: 10px; top: 36px; display: inline-flex; align-items: center; gap: 3px; font-size: 10.5px; font-weight: 700;
    line-height: 1.4; color: #fff; padding: 2px 7px; border-radius: 5px; background: var(--ok); pointer-events: none; }
  .screen .upl .i { width: 10px; height: 10px; stroke-width: 3; }
  .up-row { display: flex; gap: 6px; align-items: center; }
  .up-row input[type=date] { flex: 1; min-width: 0; font: inherit; font-size: 12px; padding: 4px 8px; border-radius: 8px;
    border: 1px solid var(--border); background: var(--surface-2); color: var(--text); color-scheme: dark; }
  .up-row input[type=date]:focus { outline: none; border-color: var(--accent); box-shadow: var(--ring); }
  .up-date { font-size: 12px; color: var(--ok); font-weight: 600; }
  .up-date .i { width: 12px; height: 12px; stroke-width: 2.5; vertical-align: -2px; }
  /* Dua bagian clip: belum & sudah diupload */
  .sec-h { display: flex; align-items: baseline; gap: 10px; margin: 0 0 12px; }
  .sec-h h2 { font-size: 15px; margin: 0; }
  .sec-h .n { font-size: 12.5px; color: var(--muted); font-variant-numeric: tabular-nums; }
  .sec-h a { margin-left: auto; font-size: 12.5px; color: var(--accent-fg); display: inline-flex; align-items: center; gap: 5px; }
  .sec-h a .i { width: 13px; height: 13px; }
  .sec-done { margin-top: 30px; padding-top: 22px; border-top: 1px solid var(--border); }
  .sec-done .clip { border-color: rgba(31, 157, 92, .35); }
  .none { grid-column: 1 / -1; padding: 22px 16px; text-align: center; font-size: 13px; color: var(--muted);
    border: 1px dashed var(--border-strong); border-radius: var(--radius); }

  /* Timeline proses vertikal: analisa -> unduh sumber -> buat clip -> siap */
  /* Proses di kiri (menempel saat scroll), daftar clip di kanan */
  .body { display: grid; grid-template-columns: 320px minmax(0, 1fr); gap: 18px; align-items: start; margin-top: 18px; }
  .flow { position: sticky; top: 16px; padding: 8px 20px 10px; }
  .body .grid { margin-top: 0; grid-template-columns: repeat(auto-fill, minmax(210px, 1fr)); }
  .grid-empty { grid-column: 1 / -1; display: flex; flex-direction: column; align-items: center; gap: 10px; }
  @media (max-width: 860px) {
    .body { grid-template-columns: minmax(0, 1fr); }
    .flow { position: static; }
  }
  .flow-head { display: flex; align-items: center; gap: 10px; padding: 10px 0 4px; }
  .flow-head b { font-size: 14px; }
  .flow-head .grow { flex: 1; }
  .fs { position: relative; display: grid; grid-template-columns: 30px minmax(0, 1fr) auto; gap: 12px; align-items: start; padding: 14px 0; }
  .fs:not(:last-child)::before { content: ""; position: absolute; left: 14px; top: 46px; bottom: -14px; width: 2px; border-radius: 1px; background: var(--surface-3); }
  .fs.done:not(:last-child)::before { background: linear-gradient(var(--accent), rgba(30, 112, 240, .44)); }
  .fs .dot { width: 30px; height: 30px; border-radius: 50%; display: grid; place-items: center; border: 2px solid var(--surface-3);
    background: var(--surface); color: var(--muted); font-size: 12px; font-weight: 700; position: relative; }
  .fs .dot .i { width: 14px; height: 14px; }
  .fs.done .dot { background: var(--grad); border-color: transparent; color: #fff; }
  .fs.active .dot { border-color: rgba(30, 112, 240, .31); color: var(--accent-fg); }
  .fs.active .dot::after { content: ""; position: absolute; inset: -2px; border-radius: 50%; border: 2px solid transparent;
    border-top-color: var(--accent); border-right-color: var(--accent); animation: spin .9s linear infinite; }
  .fs.error .dot { border-color: rgba(255, 107, 107, .5); background: rgba(255, 107, 107, .12); color: #ff8a80; }
  .fs .txt { min-width: 0; padding-top: 4px; }
  .fs .txt b { display: block; font-size: 14px; font-weight: 600; }
  .fs.pending .txt b { color: var(--muted); font-weight: 500; }
  .fs .txt span { display: block; margin-top: 3px; font-size: 12.5px; color: var(--muted); line-height: 1.45; }
  .fs.error .txt span { color: #ff8a80; }
  .fs .progress { margin-top: 10px; height: 5px; max-width: 420px; }
  .fs .act { display: flex; gap: 6px; padding-top: 2px; }
  .fs .pct { font-size: 12.5px; font-weight: 600; color: var(--accent-fg); font-variant-numeric: tabular-nums; padding-top: 6px; }

  /* Modal tambah clip */
  .modal { position: fixed; inset: 0; z-index: 50; display: grid; place-items: center; padding: 16px;
    background: rgba(5, 6, 8, .72); backdrop-filter: blur(6px); animation: mfade .15s; }
  .modal[hidden] { display: none; }
  @keyframes mfade { from { opacity: 0; } }
  .m-card { width: 100%; max-width: 440px; max-height: calc(100vh - 32px); overflow-y: auto; padding: 22px 22px 18px; }
  .m-head { display: flex; align-items: flex-start; gap: 12px; margin-bottom: 18px; }
  .m-head h3 { margin: 0 0 3px; font-size: 17px; }
  .m-head p { margin: 0; font-size: 12.5px; color: var(--muted); line-height: 1.45; }
  .m-head .grow { flex: 1; min-width: 0; }
  .m-label { font-size: 12px; font-weight: 500; color: var(--muted); margin: 0 0 8px; }
  .m-sec { margin-top: 18px; }
  .counter { display: flex; align-items: center; gap: 12px; }
  .counter .btn { width: 32px; height: 32px; padding: 0; font-size: 16px; }
  .counter output { min-width: 34px; text-align: center; font-size: 18px; font-weight: 700; font-variant-numeric: tabular-nums; }
  .durs { display: grid; grid-template-columns: repeat(2, 1fr); gap: 6px; }
  .dur { font: inherit; text-align: left; padding: 9px 11px; border-radius: 10px; border: 1px solid var(--border); background: var(--surface-2);
    color: var(--text); cursor: pointer; transition: border-color .15s, background .15s; }
  .dur b { display: block; font-size: 13.5px; font-variant-numeric: tabular-nums; }
  .dur small { display: block; margin-top: 2px; font-size: 11px; color: var(--muted); }
  .dur:hover { border-color: var(--border-strong); }
  .dur.on { border-color: rgba(30, 112, 240, .75); background: rgba(30, 112, 240, .15); }
  .dur.on b { color: #86B6FF; }
  .m-note { margin-top: 18px; padding: 12px 14px; border-radius: 12px; font-size: 12px; line-height: 1.5; color: var(--muted);
    background: rgba(30, 112, 240, .1); border: 1px solid rgba(30, 112, 240, .28); }
  .m-foot { display: flex; justify-content: flex-end; gap: 8px; margin-top: 18px; }
  /* Bersihkan room */
  .cleanc { display: flex; align-items: center; gap: 14px; padding: 14px 16px; margin-bottom: 16px; border-radius: 18px;
    border: 1px solid rgba(31, 157, 92, .35); background: linear-gradient(120deg, rgba(31, 157, 92, .12), transparent 60%), var(--surface); }
  .cleanc.done { border-color: var(--border); background: var(--surface); }
  .cleanc .cl-ic { flex: none; width: 40px; height: 40px; display: grid; place-items: center; border-radius: 12px; background: rgba(31, 157, 92, .18); font-size: 19px; color: #4fd28a; }
  .cleanc .cl-ic .i { width: 19px; height: 19px; }
  .cleanc .cl-tx { flex: 1; min-width: 0; }
  .cleanc .cl-tx b { display: block; font-size: 14px; }
  .cleanc .cl-tx span { display: block; margin-top: 3px; font-size: 12.5px; color: var(--muted); line-height: 1.5; }
  .cleanc .cl-tx .sz { display: inline; color: #4fd28a; font-size: inherit; }
  .clean-card { max-width: 500px; }
  .cl-list { display: flex; flex-direction: column; gap: 6px; }
  .cl-row { display: grid; grid-template-columns: 22px minmax(0, 1fr) auto; gap: 12px; align-items: center; padding: 10px 12px; border-radius: 12px; background: var(--surface-2); }
  .cl-row b { display: block; font-size: 13px; }
  .cl-row small { display: block; font-size: 11.5px; color: var(--muted); margin-top: 1px; }
  .cl-row .v { font-size: 13px; font-weight: 700; font-variant-numeric: tabular-nums; }
  .cl-row .ck { width: 20px; height: 20px; display: grid; place-items: center; border-radius: 6px; background: #1f9d5c; color: #fff; }
  .cl-row .ck .i { width: 12px; height: 12px; }
  .cl-row.zero { opacity: .5; }
  .cl-row.del .ck { background: var(--err); }
  .cl-row.keep .ck { background: var(--surface-3); color: var(--text); }
  .cleanc .cl-btns { display: flex; gap: 8px; flex-wrap: wrap; justify-content: flex-end; }
  .cleanc.slim .cl-ic { background: rgba(80, 140, 255, .16); color: #7fb0ff; }
  @media (max-width: 640px) { .cleanc { flex-wrap: wrap; } .cleanc .cl-btns { width: 100%; } .cleanc .cl-btns .btn { flex: 1; } }
  .cl-row.opt { cursor: pointer; border: 1px dashed var(--border-strong); background: none; }
  .cl-row.opt input { position: absolute; opacity: 0; pointer-events: none; }
  .cl-row.opt .ck { background: var(--surface-3); color: transparent; }
  .cl-row.opt:has(input:checked) { border-style: solid; border-color: rgba(229, 72, 77, .5); background: rgba(229, 72, 77, .07); }
  .cl-row.opt:has(input:checked) .ck { background: var(--err); color: #fff; }
  .cl-total { display: flex; justify-content: space-between; align-items: center; margin-top: 14px; padding: 12px 14px; border-radius: 12px;
    background: rgba(31, 157, 92, .1); font-size: 13px; }
  .cl-total b { font-size: 18px; color: #4fd28a; font-variant-numeric: tabular-nums; }
  .state.gone span { font-size: 12px; line-height: 1.45; }
  /* Riwayat render semua clip (tombol di pojok kanan topbar) */
  .hist-open { margin-left: auto; }
  .hist-open .cnt { min-width: 20px; height: 20px; padding: 0 6px; border-radius: 10px; background: var(--surface-3); font-size: 11px; font-weight: 700;
    display: inline-grid; place-items: center; font-variant-numeric: tabular-nums; }
  .h-card { max-width: 680px; }
  /* Filter: semua clip / hanya yang dirender lebih dari sekali */
  .h-tabs { display: flex; gap: 4px; padding: 4px; margin: -6px 0 14px; border: 1px solid var(--border); border-radius: 14px; background: var(--surface-2); }
  .h-tabs button { flex: 1; height: 34px; border: 0; border-radius: 10px; background: none; color: var(--muted); font: inherit; font-size: 13px; font-weight: 600; cursor: pointer;
    display: inline-flex; align-items: center; justify-content: center; gap: 6px; }
  .h-tabs button:hover { color: var(--text); }
  .h-tabs button.on { background: var(--surface-3); color: #fff; box-shadow: inset 0 0 0 1px var(--border-strong); }
  .h-tabs .i { width: 14px; height: 14px; }
  .h-act .h-sp { width: 35px; flex: none; }
  .h-tabs .n { min-width: 20px; height: 18px; padding: 0 6px; border-radius: 9px; background: rgba(255, 255, 255, .08); font-size: 11px; display: inline-grid; place-items: center; }
  .h-player { margin: -4px 0 14px; border-radius: 16px; overflow: hidden; background: #000; }
  .h-player video { display: block; width: 100%; max-height: 50vh; background: #000; }
  .h-list { display: flex; flex-direction: column; gap: 10px; }
  /* Satu kartu per clip */
  .h-clip { border: 1px solid var(--border); border-radius: 18px; background: var(--surface-2); overflow: hidden; }
  .h-clip.multi { border-color: rgba(91, 155, 255, .28); background: linear-gradient(180deg, rgba(30, 112, 240, .07), transparent 60px), var(--surface-2); }
  .h-head { display: flex; align-items: center; gap: 12px; padding: 12px 14px; }
  .h-head img { flex: none; width: 34px; aspect-ratio: 9 / 16; object-fit: cover; border-radius: 7px; background: var(--surface-3); }
  .h-head .tx { flex: 1; min-width: 0; }
  .h-head .tt { font-size: 13.5px; font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .h-head .tt .no { color: var(--muted); margin-right: 6px; }
  .h-head .sub { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; margin-top: 4px; font-size: 11.5px; color: var(--muted); font-variant-numeric: tabular-nums; }
  .h-ver { display: inline-flex; align-items: center; gap: 4px; padding: 2px 9px; border-radius: 999px; font-size: 11px; font-weight: 700;
    background: rgba(30, 112, 240, .18); color: var(--accent-fg); }
  .h-ver .i { width: 11px; height: 11px; }
  .tag { display: inline-flex; align-items: center; gap: 4px; padding: 2px 8px; border-radius: 999px; font-size: 11px; font-weight: 600;
    background: var(--surface-3); color: var(--text-2); }
  .tag .i { width: 11px; height: 11px; }
  .tag.mu { background: rgba(91, 79, 214, .22); color: #c9c3ff; }
  .tag.new { background: var(--accent); color: #fff; }
  .tag.diff { background: rgba(245, 166, 35, .14); color: #f5c06b; }
  .h-act { flex: none; display: flex; gap: 4px; align-items: center; }
  /* Versi render: garis waktu vertikal, terbaru di atas */
  .h-vers { padding: 2px 14px 12px 14px; }
  .h-v { position: relative; display: flex; align-items: center; gap: 12px; padding: 9px 10px 9px 30px; border-radius: 12px; }
  .h-v::before { content: ""; position: absolute; left: 13px; top: 0; bottom: 0; width: 2px; background: var(--border-strong); }
  .h-v:first-child::before { top: 50%; }
  .h-v:last-child::before { bottom: 50%; }
  .h-v::after { content: ""; position: absolute; left: 8px; top: 50%; width: 12px; height: 12px; margin-top: -6px; border-radius: 50%;
    background: var(--surface-2); border: 2px solid var(--border-strong); }
  .h-v.cur::after { background: var(--accent); border-color: var(--accent); box-shadow: 0 0 0 4px rgba(30, 112, 240, .2); }
  .h-v:hover { background: rgba(255, 255, 255, .03); }
  .h-v.on { background: var(--accent-soft); }
  .h-v .tx { flex: 1; min-width: 0; }
  .h-v .t1 { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; font-size: 12.5px; font-weight: 700; }
  .h-v .t2 { margin-top: 2px; font-size: 11.5px; color: var(--muted); font-variant-numeric: tabular-nums; }
  .h-v.old .t1 { color: var(--text-2); }
  .h-empty { padding: 22px; text-align: center; font-size: 13px; color: var(--muted); }

  @media (max-width: 560px) {
    .head { grid-template-columns: minmax(0, 1fr); grid-template-areas: "thumb" "main" "actions"; padding: 14px; gap: 14px; }
    .head-actions { flex-direction: row; min-width: 0; }
    .head-actions .btn { flex: 1; }
    .grid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
  }
</style>
</head>
<body>
<div class="wrap" style="max-width:1280px">
  <header class="topbar">
    <a class="back" href="index.php" title="Kembali ke Beranda" aria-label="Kembali ke Beranda"><?= icon('arrow-left') ?></a>
    <a class="brand" href="index.php"><img src="assets/logo-96.png" alt=""><span><b>Bisa</b>Clip</span></a>
    <button class="btn sm ghost hist-open" id="hist-open" hidden title="Semua hasil render di proyek ini"><?= icon('film') ?> Riwayat render <span class="cnt" id="hist-cnt"></span></button>
  </header>
  <div id="app"><div class="empty"><span class="spin"></span></div></div>
</div>

<div class="modal" id="more-modal" hidden>
  <div class="card m-card" role="dialog" aria-modal="true" aria-labelledby="more-title">
    <div class="m-head">
      <div class="grow"><h3 id="more-title">Tambah clip</h3><p id="more-sub"></p></div>
      <button class="btn sm ghost" id="more-close" title="Tutup (Esc)"><?= icon('x') ?></button>
    </div>
    <div class="m-label">Jumlah clip tambahan</div>
    <div class="counter">
      <button class="btn" id="more-dec" aria-label="Kurangi">−</button>
      <output id="more-cnt">3</output>
      <button class="btn" id="more-inc" aria-label="Tambah">+</button>
      <span class="muted small">maks. 10 per proses</span>
    </div>
    <div class="m-sec">
      <div class="m-label">Durasi tiap clip</div>
      <div class="durs" id="more-durs"></div>
    </div>
    <div class="m-note">AI menganalisa ulang video yang sama dan hanya mengambil momen di luar clip yang sudah ada.
      Clip lama tidak berubah. Kalau momen yang layak tinggal sedikit, hasilnya bisa lebih sedikit dari jumlah yang diminta.</div>
    <div class="m-foot">
      <button class="btn ghost" id="more-cancel">Batal</button>
      <button class="btn primary" id="more-start"><?= icon('sparkles') ?> Cari clip tambahan</button>
    </div>
  </div>
</div>

<div class="modal" id="clean-modal" hidden>
  <div class="card m-card clean-card" role="dialog" aria-modal="true" aria-labelledby="clean-title">
    <div class="m-head"><div class="grow"><h3 id="clean-title">Bersihkan room</h3>
      <p>File berat dihapus dari disk. Riwayat room tetap: daftar clip, caption, hashtag, tanggal upload, & thumbnail.</p></div></div>
    <div class="cl-list" id="clean-list"></div>
    <div class="cl-total"><span>Ruang disk yang kembali</span><b id="clean-total"></b></div>
    <div class="m-note">Clip masih bisa diedit & dirender ulang nanti. Video sumbernya akan diunduh ulang otomatis saat dibutuhkan.</div>
    <div class="m-foot"><button class="btn ghost" id="clean-cancel">Batal</button><button class="btn primary" id="clean-go">Bersihkan sekarang</button></div>
  </div>
</div>

<div class="modal" id="slim-modal" hidden>
  <div class="card m-card clean-card" role="dialog" aria-modal="true" aria-labelledby="slim-title">
    <div class="m-head"><div class="grow"><h3 id="slim-title">Hapus video sumber</h3>
      <p>Hanya video lengkap hasil unduhan yang dihapus. Bagian di sekitar tiap clip disimpan dulu dengan kualitas yang sama.</p></div></div>
    <div class="cl-list" id="slim-list"></div>
    <div class="cl-total"><span>Ruang disk yang kembali (perkiraan)</span><b id="slim-total"></b></div>
    <div class="m-note">Semua clip room ini tetap bisa diedit & dirender ulang seperti biasa, tanpa unduh ulang. Clip baru dari momen lain (Cari clip lagi) akan dipotong langsung dari YouTube.</div>
    <div class="m-foot"><button class="btn ghost" id="slim-cancel">Batal</button><button class="btn primary" id="slim-go">Hapus video sumber</button></div>
  </div>
</div>

<div class="modal" id="hist-modal" hidden>
  <div class="card m-card h-card" role="dialog" aria-modal="true" aria-labelledby="hist-title">
    <div class="m-head">
      <div class="grow"><h3 id="hist-title">Riwayat render</h3><p id="hist-sub"></p></div>
      <button class="btn sm ghost" id="hist-close" title="Tutup (Esc)"><?= icon('x') ?></button>
    </div>
    <div class="h-tabs" id="hist-tabs"></div>
    <div class="h-player" id="hist-player" hidden></div>
    <div class="h-list" id="hist-list"></div>
    <div class="m-note">Setiap render tersimpan sebagai file sendiri di folder downloads, jadi versi dengan & tanpa musik bisa diunduh terpisah.
      Riwayat menyimpan hingga 10 render lama per clip; yang lebih lama otomatis dihapus.</div>
  </div>
</div>

<script src="assets/common.js?v=<?= filemtime(__DIR__ . '/assets/common.js') ?>"></script>
<script>
let ID = <?= json_encode($id) ?>;
const FRAMINGS = [
  ['speaker', 'Ikuti pembicara', 'Zoom otomatis ke orang yang bicara'],
  ['crop_center', 'Crop tengah', 'Potong bagian tengah video'],
  ['blur', 'Background blur', 'Video utuh, latar diblur'],
  ['gaming', 'Streaming game', 'Game 70% di atas, webcam 30% di bawah'],
];
const framingName = l => (FRAMINGS.find(f => f[0] === l) || [, l])[1];

let capLang = 'id', capBusy = false, autoEnTried = false;
let D = null, project = null, jobs = {}, edited = {}, renders = {}, missing = {}, cleanInfo = null, histIid = null, polling = null, moreSeen = null;

async function load() {
  clearTimeout(polling);
  try { D = await api('studio', null, '&id=' + ID); }
  catch (err) {
    $('app').innerHTML = `<div class="card empty"><b>Proyek tidak ditemukan</b>${esc(err.message)}
      <br><a href="index.php" style="color:var(--accent-fg)">Kembali ke beranda</a></div>`;
    return;
  }
  project = D.project;
  jobs = D.jobs || {};
  edited = D.edited || {};
  renders = D.renders || {};
  // Bahasa salin diingat per proyek; belum pernah dipilih = ikuti bahasa video (podcast English -> English)
  if (project) {
    const saved = store.get('cap-lang-' + project.id);
    capLang = saved === 'en' || saved === 'id' ? saved : (D.video_lang === 'en' ? 'en' : 'id');
    // English dipilih tapi versi English belum ada (mis. room dianalisa sebelum fitur bahasa): buat otomatis sekali
    if (capLang === 'en' && !roomEnglish() && project.items.some(it => !it.en) && !capBusy && !autoEnTried) {
      autoEnTried = true;
      setTimeout(makeEnglish, 0);
    }
  }
  missing = D.missing || {};
  paintHistBtn();
  if (!$('hist-modal').hidden) renderHistory();
  // Proyek sudah dibuat dari analisa: pakai id proyek di URL supaya tautan editor & beranda konsisten
  if (project && ID !== project.id) { ID = project.id; history.replaceState(null, '', 'studio.php?id=' + ID); }
  // Job tambah clip baru selesai sejak dilihat terakhir: beri tahu hasilnya
  const M = D.more;
  if (M && moreSeen && moreSeen.id === M.id && ACTIVE.includes(moreSeen.status) && M.status === 'done') {
    toast(M.clip_count ? `${M.clip_count} clip baru ditambahkan` : 'AI tidak menemukan momen baru yang layak');
  }
  moreSeen = M ? { id: M.id, status: M.status } : null;
  render();
  const st = steps();
  if (st.some(s => s.state === 'active') || (D.analysis?.status === 'done' && !project && D.analysis.clip_count)) polling = setTimeout(load, 2000);
}

function itemState(it) {
  return jobs[it.job_id] || { status: 'error', error: 'Data render hilang.' };
}

/* ---------- Langkah proses ---------- */

function steps() {
  const A = D.analysis, S = D.source;
  const out = [];
  // 1. Analisa AI
  if (A?.campaign_id) {
    // Room campaign: footage diunggah sendiri lalu disusun AI di halaman campaign
    out.push({ key: 'analyze', title: 'Footage disusun AI', state: 'done', sub: 'Adegan dipilih & diurutkan sesuai angle campaign' });
  } else if (!A || A.status === 'done') {
    const none = A && !A.clip_count;
    out.push({ key: 'analyze', title: 'Menganalisa video dengan AI', state: none ? 'error' : 'done',
      sub: none ? 'AI tidak menemukan momen yang cocok untuk dijadikan clip.' : `${project?.items.length || A?.clip_count || 0} momen terbaik ditemukan` });
  } else if (ACTIVE.includes(A.status)) {
    const cfg = A.clip_min ? ` · mencari hingga ${A.max_clips || '?'} clip ${A.clip_min}–${A.clip_max} dtk` : '';
    out.push({ key: 'analyze', title: 'Menganalisa video dengan AI', state: 'active', sub: (A.stage || 'Menunggu…') + cfg, progress: A.progress || 0,
      act: `<button class="btn sm ghost" data-a="cancel">${ic('ban')} Batalkan</button>` });
  } else {
    out.push({ key: 'analyze', title: 'Menganalisa video dengan AI', state: 'error',
      sub: A.status === 'cancelled' ? 'Analisa dibatalkan.' : (A.error || 'Analisa gagal.'),
      act: `<button class="btn sm" data-a="retry-analyze">${ic('refresh')} Coba lagi</button>` });
  }
  // 1b. Tambah clip (job analyze_more terakhir)
  const M = D.more;
  if (M && ACTIVE.includes(M.status)) {
    out.push({ key: 'more', title: 'Mencari clip tambahan', state: 'active', progress: M.progress || 0,
      sub: `${M.stage || 'Menunggu…'} · hingga ${M.max_clips} clip ${M.clip_min}–${M.clip_max} dtk`,
      act: `<button class="btn sm ghost" data-a="more-cancel">${ic('ban')} Batalkan</button>` });
  } else if (M && M.status === 'done') {
    out.push({ key: 'more', title: 'Mencari clip tambahan', state: 'done',
      sub: M.clip_count ? `${M.clip_count} clip baru ditambahkan` : 'AI tidak menemukan momen baru yang layak. Coba durasi lain.' });
  } else if (M) {
    out.push({ key: 'more', title: 'Mencari clip tambahan', state: 'error',
      sub: M.status === 'cancelled' ? 'Dibatalkan.' : (M.error || 'Gagal mencari clip tambahan.'),
      act: `<button class="btn sm" data-a="more-open">${ic('refresh')} Coba lagi</button>` });
  }
  // 2. Unduh video sumber (berjalan bersamaan dengan analisa)
  const renderDone = project && project.items.every(it => itemState(it).status === 'done');
  if (S?.status === 'done' || (!S && renderDone)) {
    out.push({ key: 'source', title: A?.campaign_id ? 'Merakit footage' : 'Mengunduh video sumber', state: 'done', sub: A?.campaign_id && !project?.cleaned && !project?.slim
      ? 'Potongan footage dirakit jadi satu video' + (S?.size ? ' · ' + fmtSize(S.size) : '') : project?.cleaned
      ? 'Sudah dihapus saat room dibersihkan · diunduh ulang otomatis kalau clip diedit lagi'
      : project?.slim ? `Video lengkap sudah dihapus · potongan di sekitar clip (${fmtSize(project.slim.kept)}) tetap dipakai untuk edit & render`
      : 'Video kualitas terbaik siap dipotong' + (S?.size ? ' · ' + fmtSize(S.size) : '') });
  } else if (S && ACTIVE.includes(S.status)) {
    out.push({ key: 'source', title: 'Mengunduh video sumber', state: 'active', sub: [S.stage, S.speed, S.eta ? 'sisa ' + S.eta : ''].filter(Boolean).join(' · '), progress: S.progress || 0 });
  } else if (S) {
    out.push({ key: 'source', title: 'Mengunduh video sumber', state: 'error', sub: S.error || 'Unduhan gagal.',
      act: `<button class="btn sm" data-a="retry-source">${ic('refresh')} Coba lagi</button>` });
  } else {
    out.push({ key: 'source', title: 'Mengunduh video sumber', state: 'pending', sub: 'Menunggu…' });
  }
  // 3. Membuat clip
  if (!project) {
    out.push({ key: 'clip', title: 'Memotong & merender clip', state: 'pending', sub: 'Dimulai otomatis setelah analisa selesai.' });
  } else {
    const st = project.items.map(itemState), n = st.length;
    const done = st.filter(s => s.status === 'done').length;
    const failed = st.filter(s => s.status === 'error' || s.status === 'cancelled').length;
    const run = st.find(s => ACTIVE.includes(s.status) && s.stage !== 'Menunggu giliran render…' && s.status !== 'queued');
    const pct = st.reduce((a, s) => a + (s.status === 'done' ? 100 : ACTIVE.includes(s.status) ? (s.progress || 0) : 0), 0) / n;
    if (done + failed < n) {
      out.push({ key: 'clip', title: 'Memotong & merender clip', state: 'active', progress: pct,
        sub: `${done} dari ${n} clip selesai${failed ? ` · ${failed} gagal` : ''}${run ? ' · ' + run.stage : ''}` });
    } else if (!done) {
      out.push({ key: 'clip', title: 'Memotong & merender clip', state: 'error', sub: 'Semua clip gagal dirender. Coba render ulang dari kartu clip di bawah.' });
    } else {
      out.push({ key: 'clip', title: 'Memotong & merender clip', state: 'done', sub: `${done} clip selesai dirender${failed ? ` · ${failed} gagal` : ''}` });
    }
  }
  // 4. Siap
  const ready = out.find(s => s.key === 'clip').state === 'done';
  out.push({ key: 'ready', title: 'Siap diedit & diunduh', state: ready ? 'done' : 'pending',
    sub: ready ? 'Edit subtitle, hook, dan framing di editor, atau langsung download clip-nya.' : 'Clip bisa dibuka di editor begitu selesai dibuat.' });
  return out;
}

function flowHtml() {
  const st = steps();
  const doneN = st.filter(s => s.state === 'done').length;
  return `<div class="flow-head"><b>Proses</b><span class="grow"></span><span class="muted small">${doneN} dari ${st.length} langkah</span></div>
    ${st.map((s, i) => `<div class="fs ${s.state}">
      <span class="dot">${s.state === 'done' ? ic('check') : s.state === 'error' ? '!' : i + 1}</span>
      <div class="txt"><b>${esc(s.title)}</b><span>${esc(s.sub || '')}</span>
        ${s.state === 'active' && s.progress != null ? `<div class="progress"><i style="width:${s.progress}%"></i></div>` : ''}</div>
      ${s.act ? `<div class="act">${s.act}</div>` : s.state === 'active' && s.progress != null ? `<span class="pct">${Math.floor(s.progress)}%</span>` : '<span></span>'}
    </div>`).join('')}`;
}

/* ---------- Render halaman ---------- */

function render() {
  const v = project?.video || D.analysis || {};
  const title = v.title || D.analysis?.url || 'Video';
  document.title = title + ' · Clip Studio · BisaClip';

  // Kerangka dibuat sekali; selanjutnya hanya bagian yang berubah yang diperbarui,
  // supaya video yang sedang diputar tidak berhenti saat polling.
  if (!$('flow')) {
    $('app').innerHTML = `
      <div class="card head">
        <div class="h-thumb">${v.thumbnail ? `<img src="${esc(v.thumbnail)}" alt="" onerror="this.remove()">` : ''}</div>
        <div class="h-main">
          <div class="h-meta"><span class="eyebrow grad-text">Clip Studio</span><a class="acc-line" id="acc-line" hidden></a></div>
          <h1 title="${esc(title)}">${esc(title)}</h1>
          <div class="stats" id="stats"></div>
          <div class="lang-bar" id="lang-bar"></div>
        </div>
        <div class="head-actions" id="head-actions"></div>
      </div>
      <div class="body">
        <aside class="card flow" id="flow"></aside>
        <div class="main">
          <div id="clean-box"></div>
          <div class="sec-h" id="h-pend" hidden><h2>Belum diupload</h2><span class="n" id="n-pend"></span></div>
          <div class="grid" id="grid"><div class="empty grid-empty"><span class="spin"></span>Clip akan muncul di sini setelah analisa selesai.</div></div>
          <section class="sec-done" id="sec-done" hidden>
            <div class="sec-h"><h2>Sudah diupload</h2><span class="n" id="n-done"></span>
              <a href="calendar.php">${ic('calendar')} Lihat kalender upload</a></div>
            <div class="grid" id="grid-done"></div>
          </section>
          <p class="muted small" id="foot" style="margin-top:18px" hidden>Clip dirender satu per satu supaya laptop tidak berat. File tersimpan di folder <b>downloads</b>.</p>
        </div>
      </div>`;
  }
  setHTML($('flow'), flowHtml());
  renderAccountLine();
  if (!project) { $('stats').innerHTML = ''; return; }

  const states = project.items.map(itemState);
  const done = states.filter(s => s.status === 'done').length;
  const running = states.filter(s => ACTIVE.includes(s.status)).length;
  const failed = states.filter(s => s.status === 'error' || s.status === 'cancelled').length;
  const moreBusy = D.more && ACTIVE.includes(D.more.status);
    // Room campaign (footage lokal) tidak punya link YouTube: tambah clip dibuat dari halaman campaign
  const camp = D.analysis?.campaign_id;
  setHTML($('head-actions'), `${camp ? `<a class="btn" href="campaign.php?id=${camp}" title="Kembali ke campaign untuk menyusun clip lain">${ic('film')} Campaign</a>` : `<button class="btn" data-a="more-open" ${moreBusy || D.analysis?.status !== 'done' ? 'disabled' : ''}
      title="AI mencari momen baru di luar clip yang sudah ada">${ic('plus')} Tambah clip</button>`}
    <a class="btn primary" href="editor.php?project=${project.id}&item=${project.items[0]?.iid || ''}">${ic('pencil')} Buka editor</a>
    ${project.finished
      ? `<button class="btn reopen" data-a="finish" data-done="0" title="Kembalikan room ke daftar aktif di beranda">${ic('refresh')} Aktifkan lagi</button>`
      : `<button class="btn finish" data-a="finish" data-done="1" title="Room dianggap selesai & dipindah ke bagian Room selesai di beranda">${ic('check')} Selesaikan room</button>`}`);
  $('foot').hidden = false;
  paintLangBar();
  setHTML($('stats'), `${project.finished ? `<span class="pill ok">${ic('check')} Room selesai · ${new Date(project.finished * 1000).toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' })}</span>` : ''}
    <span class="pill">${project.items.length} clip</span>
    ${done ? `<span class="pill ok">${done} selesai</span>` : ''}
    ${running ? `<span class="pill warn"><span class="spin" style="width:11px;height:11px"></span> ${running} diproses</span>` : ''}
    ${failed ? `<span class="pill" style="color:var(--err)">${failed} gagal</span>` : ''}
    <span class="pill">${project.items.filter(it => edited[it.iid]).length} sudah diedit</span>
    ${project.items.some(it => it.uploaded) ? `<span class="pill ok">${project.items.filter(it => it.uploaded).length} sudah diupload</span>` : ''}`);

  // Clip dibagi dua: belum & sudah diupload. Kartu yang tidak berubah dibiarkan (video yang diputar tidak berhenti);
  // kartu yang pindah bagian dibuat ulang di grid tujuannya.
  const grid = $('grid'), gridDone = $('grid-done');
  grid.querySelector('.grid-empty')?.remove();
  project.items.forEach((it, i) => {
    const key = cardKey(it), target = it.uploaded ? gridDone : grid;
    const old = $('app').querySelector(`.clip[data-iid="${it.iid}"]`);
    if (old && old.dataset.key === key && old.parentElement === target) return;
    // Kartu yang sedang diklik tidak diganti dulu (diperbarui di putaran polling berikutnya), supaya kliknya tidak hilang
    if (old && old.parentElement === target && pressedEl && old.contains(pressedEl)) return;
    const tpl = document.createElement('template');
    tpl.innerHTML = cardHtml(it, i).trim();
    if (old && old.parentElement === target) old.replaceWith(tpl.content.firstChild);
    else { old?.remove(); target.appendChild(tpl.content.firstChild); }
  });
  // Urutkan sesuai nomor clip; hanya kartu yang salah posisi yang dipindah
  for (const [g, list] of [[grid, project.items.filter(it => !it.uploaded)], [gridDone, project.items.filter(it => it.uploaded)]]) {
    list.forEach((it, k) => {
      const node = g.querySelector(`.clip[data-iid="${it.iid}"]`);
      const at = g.querySelectorAll('.clip')[k];
      if (node && at !== node) g.insertBefore(node, at);
    });
  }
  const nDone = project.items.filter(it => it.uploaded).length, nPend = project.items.length - nDone;
  $('h-pend').hidden = !nDone;
  $('n-pend').textContent = `${nPend} clip`;
  $('n-done').textContent = `${nDone} clip`;
  $('sec-done').hidden = !nDone;
  grid.querySelector('.none')?.remove();
  if (!nPend && nDone) grid.insertAdjacentHTML('beforeend', '<div class="none">Semua clip sudah diupload 🎉</div>');
  paintCleanBox(!nPend && nDone > 0);
}

/* ---------- Bahasa judul & caption (Indonesia / English) ---------- */
// Teks yang tampil & disalin untuk satu clip sesuai bahasa terpilih (English kalau versinya sudah dibuat)
const tx = it => capLang === 'en' && it.en ? it.en : it;
const roomEnglish = () => D?.analysis?.out_lang_used === 'en';
function paintLangBar() {
  const bar = $('lang-bar');
  if (!bar || !project) return;
  // Room yang dianalisa dalam English: judul & caption aslinya sudah English
  if (roomEnglish()) { setHTML(bar, '<span class="pill">🇬🇧 Judul & caption dalam English</span>'); return; }
  const missingEn = project.items.filter(it => !it.en).length;
  setHTML(bar, `<span>Judul & caption:</span>
    <span class="lang-seg"><button type="button" class="${capLang === 'id' ? 'on' : ''}" data-a="cap-lang" data-v="id">Indonesia</button>
      <button type="button" class="${capLang === 'en' ? 'on' : ''}" data-a="cap-lang" data-v="en">English</button></span>
    ${capBusy ? '<span><span class="spin" style="width:11px;height:11px"></span> AI sedang menulis versi English…</span>'
      : capLang === 'en' ? (missingEn ? `<button class="lnk" data-a="cap-gen">Buat versi English untuk ${missingEn} clip</button>`
        : '<button class="lnk" data-a="cap-gen" title="Tulis ulang semua judul & caption English">Buat ulang</button>') : ''}`);
}
function setCapLang(l) {
  capLang = l === 'en' ? 'en' : 'id';
  store.set('cap-lang-' + project.id, capLang);
  render();
  // Pertama kali pilih English: langsung buat versi English-nya
  if (capLang === 'en' && project.items.some(it => !it.en) && !capBusy) makeEnglish();
}
async function makeEnglish() {
  if (capBusy) return;
  capBusy = true;
  paintLangBar();
  try {
    const d = await api('captions_en', { id: project.id });
    const by = Object.fromEntries(d.items.map(x => [x.iid, x.en]));
    project.items.forEach(it => { if (by[it.iid]) it.en = by[it.iid]; });
    toast(`Versi English dibuat untuk ${d.count} clip`);
  } catch (err) { toast(err.message); }
  capBusy = false;
  render();
}

/* ---------- Bersihkan room (semua clip sudah diupload) ---------- */
const CLEAN_PARTS = [
  ['source', 'Video sumber', 'Video lengkap yang diunduh untuk dipotong'],
  ['proxy', 'Video preview editor', 'Salinan ringan untuk editor'],
  ['preview', 'Cuplikan preview analisa', 'Potongan preview saat memilih momen'],
  ['old', 'Versi render lama', 'Render sebelumnya di riwayat render'],
];
async function paintCleanBox(allUp) {
  const box = $('clean-box');
  const c = project.cleaned;
  if (c) {
    setHTML(box, `<div class="cleanc done"><span class="cl-ic">${ic('check')}</span>
      <div class="cl-tx"><b>Room sudah dibersihkan · hemat ${fmtSize(c.freed) || '0 MB'}</b>
        <span>${new Date(c.at * 1000).toLocaleDateString('id-ID', { day: 'numeric', month: 'long', year: 'numeric' })} · judul, caption, tanggal upload, & thumbnail tetap tersimpan.
          ${c.clips ? 'File clip final juga sudah dihapus.' : 'File clip final masih ada di folder downloads.'} Edit lagi tetap bisa: video sumber diunduh ulang otomatis.</span></div></div>`);
    return;
  }
  if (!cleanInfo) {
    if (allUp) setHTML(box, '<div class="cleanc"><span class="spin"></span><div class="cl-tx"><span>Menghitung ukuran file room…</span></div></div>');
    try { cleanInfo = await api('room_cleanup_info', null, '&id=' + project.id); } catch { setHTML(box, ''); return; }
  }
  const z = cleanInfo.sizes, heavy = CLEAN_PARTS.reduce((a, [k]) => a + z[k].bytes, 0);
  const so = cleanInfo.source_only || {}, slim = cleanInfo.slim, save = Math.max(0, (so.bytes || 0) - (so.keep || 0));
  const slimBtn = so.bytes ? '<button class="btn" data-a="slim-open">Hapus video sumber saja</button>' : '';
  if (allUp) {
    setHTML(box, `<div class="cleanc"><span class="cl-ic">🧹</span>
      <div class="cl-tx"><b>Semua clip sudah diupload. Bersihkan room ini?</b>
        <span>Hapus video sumber & file berat lainnya untuk menghemat <b class="sz">${fmtSize(heavy) || '0 MB'}</b>. Daftar clip, caption, tanggal upload, & thumbnail tetap tersimpan sebagai riwayat.
        ${so.bytes ? `Atau hapus video sumbernya saja (±${fmtSize(save)}) supaya clip tetap bisa diedit.` : ''}</span></div>
      <div class="cl-btns">${slimBtn}<button class="btn primary" data-a="clean-open" ${heavy || z.clips.bytes ? '' : 'disabled'}>Bersihkan room</button></div></div>`);
  } else if (so.bytes && save > 50 * 1048576) {
    setHTML(box, `<div class="cleanc slim"><span class="cl-ic">${ic('download')}</span>
      <div class="cl-tx"><b>Hemat <span class="sz">±${fmtSize(save)}</span>: hapus video sumber</b>
        <span>Video lengkap hasil unduhan (${fmtSize(so.bytes)}) tidak dibutuhkan lagi. Bagian di sekitar tiap clip tetap disimpan, jadi clip masih bisa diedit & dirender.</span></div>
      <div class="cl-btns">${slimBtn}</div></div>`);
  } else if (slim && !so.bytes) {
    setHTML(box, `<div class="cleanc done slim"><span class="cl-ic">${ic('check')}</span>
      <div class="cl-tx"><b>Video sumber sudah dihapus · hemat ${fmtSize(slim.freed - slim.kept)}</b>
        <span>Potongan sumber di sekitar ${slim.parts} bagian clip (${fmtSize(slim.kept)}) tetap disimpan, jadi edit & render tetap berjalan.</span></div></div>`);
  } else setHTML(box, '');
}
function openSlim() {
  const so = cleanInfo.source_only;
  $('slim-list').innerHTML = `<div class="cl-row del"><span class="ck">${ic('trash')}</span><div><b>Video sumber lengkap</b><small>Dihapus</small></div><span class="v">${fmtSize(so.bytes)}</span></div>
    <div class="cl-row"><span class="ck">${ic('check')}</span><div><b>Potongan di sekitar tiap clip</b><small>Disimpan · kualitas sama (1080p), tanpa encode ulang</small></div><span class="v">±${fmtSize(so.keep) || '—'}</span></div>
    <div class="cl-row keep"><span class="ck">${ic('check')}</span><div><b>Preview editor, hasil render, & file clip</b><small>Tidak disentuh</small></div><span class="v">—</span></div>`;
  $('slim-total').textContent = '±' + (fmtSize(Math.max(0, so.bytes - so.keep)) || '0 MB');
  $('slim-modal').hidden = false;
}
$('slim-modal').addEventListener('click', async e => {
  if (e.target === $('slim-modal') || e.target.closest('#slim-cancel')) return ($('slim-modal').hidden = true);
  if (!e.target.closest('#slim-go')) return;
  const b = $('slim-go');
  b.disabled = true;
  b.innerHTML = '<span class="spin"></span> Menyimpan potongan…';
  try {
    const d = await api('room_slim', { id: project.id });
    $('slim-modal').hidden = true;
    toast(`Video sumber dihapus · ${fmtSize(d.slim.freed - d.slim.kept) || '0 MB'} ruang disk kembali`);
    cleanInfo = null;
    load();
  } catch (err) { toast(err.message); }
  b.disabled = false;
  b.textContent = 'Hapus video sumber';
});
document.addEventListener('keydown', e => { if (e.key === 'Escape' && !$('slim-modal').hidden) $('slim-modal').hidden = true; });
function openClean() {
  const z = cleanInfo.sizes;
  $('clean-list').innerHTML = CLEAN_PARTS.map(([k, n, d]) => `<div class="cl-row${z[k].bytes ? '' : ' zero'}"><span class="ck">${ic('check')}</span>
      <div><b>${n}</b><small>${d}</small></div><span class="v">${z[k].bytes ? fmtSize(z[k].bytes) : '—'}</span></div>`).join('')
    + `<label class="cl-row opt"><input type="checkbox" id="clean-clips"><span class="ck">${ic('check')}</span>
      <div><b>File clip final (${z.clips.count})</b><small>Hapus juga kalau clip sudah aman tersimpan di tempat lain</small></div><span class="v">${fmtSize(z.clips.bytes) || '—'}</span></label>`;
  paintCleanTotal();
  $('clean-modal').hidden = false;
}
function paintCleanTotal() {
  const z = cleanInfo.sizes;
  const total = CLEAN_PARTS.reduce((a, [k]) => a + z[k].bytes, 0) + ($('clean-clips').checked ? z.clips.bytes : 0);
  $('clean-total').textContent = fmtSize(total) || '0 MB';
}
$('clean-modal').addEventListener('change', e => { if (e.target.id === 'clean-clips') paintCleanTotal(); });
$('clean-modal').addEventListener('click', async e => {
  if (e.target === $('clean-modal') || e.target.closest('#clean-cancel')) return ($('clean-modal').hidden = true);
  if (!e.target.closest('#clean-go')) return;
  const b = $('clean-go');
  b.disabled = true;
  b.innerHTML = '<span class="spin"></span> Membersihkan…';
  try {
    const d = await api('room_cleanup', { id: project.id, clips: $('clean-clips').checked });
    $('clean-modal').hidden = true;
    toast(`Room dibersihkan · ${fmtSize(d.freed) || '0 MB'} ruang disk kembali`);
    cleanInfo = null;
    load();
  } catch (err) { toast(err.message); }
  b.disabled = false;
  b.innerHTML = 'Bersihkan sekarang';
});
document.addEventListener('keydown', e => { if (e.key === 'Escape' && !$('clean-modal').hidden) $('clean-modal').hidden = true; });
const cardKey = it => {
  const j = itemState(it);
  return [it.job_id, capLang, it.en ? it.en.title : '', missing[it.iid] ? 1 : 0, edited[it.iid] ? 1 : 0, it.uploaded || '', j.status, ACTIVE.includes(j.status) ? Math.floor((j.progress || 0) / 5) + j.stage : ''].join('|');
};

// Tanggal lokal user (YYYY-MM-DD) & labelnya, mis. "Sen, 2 Nov 2026"
const todayStr = () => { const d = new Date(); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`; };
const dateLabel = s => { const [y, m, d] = s.split('-').map(Number);
  return new Date(y, m - 1, d).toLocaleDateString('id-ID', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' }); };

// Render selesai lewat jalur AI upscale 2K (dicatat di keterangan hasil render)
const upscaled = j => j.status === 'done' && /AI upscale 2K/.test(j.note || '');

function cardHtml(it, i) {
  const j = itemState(it);
  const thumb = `api.php?action=frame&id=${project.analysis_id}&t=${Math.round((it.start + it.end) / 2 * 10) / 10}`;
  let screen;
  if (j.status === 'done' && missing[it.iid]) {
    // File clip sudah dihapus (room dibersihkan): tampilkan thumbnail yang disimpan
    screen = `<img class="bg" src="api.php?action=clip_thumb&id=${project.id}&iid=${it.iid}" alt="" onerror="this.src='${thumb}'">
      <div class="state gone"><span>File clip sudah dihapus<br>saat room dibersihkan</span></div>`;
  } else if (j.status === 'done') {
    screen = `<video src="downloads/${encodeURIComponent(j.file_music || j.file)}?v=${j.id}" controls preload="metadata" playsinline></video>`;
  } else if (ACTIVE.includes(j.status)) {
    const waiting = j.status === 'queued' || j.stage === 'Menunggu giliran render…';
    screen = `<img class="bg" src="${thumb}" alt="">
      <div class="state">${waiting
        ? '<span class="big">⏳</span><span>Menunggu giliran render…</span>'
        : `<span class="big">${Math.floor(j.progress || 0)}%</span><div class="progress"><i style="width:${j.progress || 0}%"></i></div><span>${esc(j.stage)}</span>`}
      </div>`;
  } else {
    screen = `<img class="bg" src="${thumb}" alt="">
      <div class="state"><span class="big">⚠</span><span>${esc(j.status === 'cancelled' ? 'Render dibatalkan' : (j.error || 'Render gagal'))}</span>
        <button class="btn sm primary" data-a="retry" data-iid="${it.iid}">${ic('refresh')} Render ulang</button></div>`;
  }
  const dur = it.end - it.start;
  return `<div class="card clip" data-iid="${it.iid}" data-key="${esc(cardKey(it))}">
    <div class="screen">${screen}<span class="badge">#${i + 1}</span>
      <span class="ed ${edited[it.iid] ? 'yes' : ''}">${edited[it.iid] ? '✓ Sudah diedit' : 'Belum diedit'}</span></div>
    <div class="c-body">
      ${it.concept && it.concept !== 'Lainnya' ? `<span class="cpt">${esc(it.concept)}</span>` : ''}
      <div class="c-title">${capLang === 'en' && it.en ? '<span class="en">EN</span>' : ''}${esc(tx(it).title)}</div>
      <div class="c-meta">${fmtDur(dur)} · ${esc(framingName(it.layout === 'split' ? 'speaker' : it.layout))}${it.split || it.layout === 'split' ? ' + Split' : ''}${it.subtitles ? ' · Subtitle' : ''}${upscaled(j) ? ' · <span title="AI upscale 2K (1440×2560)">2K</span>' : ''}${j.status === 'done' && j.size ? ' · ' + fmtSize(j.size) : ''}</div>
      <div class="c-actions">
        ${it.uploaded ? `<div class="up-date">${ic('check')} Diupload ${esc(dateLabel(it.uploaded))}</div>
          <div class="up-row"><input type="date" data-up-date="${it.iid}" value="${esc(it.uploaded)}" max="${todayStr()}" aria-label="Tanggal upload">
            <button class="btn sm ghost" data-a="unupload" data-iid="${it.iid}" title="Pindahkan kembali ke Belum diupload">Batalkan</button></div>` : ''}
        ${j.status === 'done' && !missing[it.iid] ? (j.file_music
          // Render "keduanya": dua file, dengan & tanpa musik
          ? `<div class="dl-two"><a class="btn sm ${it.uploaded ? 'ghost' : 'primary'}" href="downloads/${encodeURIComponent(j.file_music)}" download title="Dengan musik · ${fmtSize(j.size_music)}">${ic('music')} Musik</a>
              <a class="btn sm ${it.uploaded ? 'ghost' : 'primary'}" href="downloads/${encodeURIComponent(j.file)}" download title="Tanpa musik · ${fmtSize(j.size)}">${ic('volume')} Tanpa musik</a></div>`
          : `<a class="btn sm ${it.uploaded ? 'ghost' : 'primary'}" href="downloads/${encodeURIComponent(j.file)}" download>${ic('download')} Download</a>`) : ''}
        ${j.status === 'done' && !it.uploaded ? `<button class="btn sm done-up" data-a="upload" data-iid="${it.iid}" title="Tandai clip ini sudah diupload hari ini">${ic('check')} Sudah upload</button>` : ''}
        <div class="cp-row"><button class="btn sm ghost" data-a="copy-title" data-iid="${it.iid}" title="Salin judul${capLang === 'en' ? ' (English)' : ''}">${ic('copy')} Judul</button>
          <button class="btn sm ghost" data-a="caption" data-iid="${it.iid}" title="Salin caption + hashtag${capLang === 'en' ? ' (English)' : ''}">${ic('caption')} Caption</button></div>
      </div>
    </div>
  </div>`;
}

/* ---------- Aksi kartu ---------- */

$('app').addEventListener('click', async e => {
  const b = e.target.closest('[data-a]');
  if (!b) return;
  const aid = D.analysis?.id;
  try {
    if (b.dataset.a === 'cancel') { b.disabled = true; await api('cancel', { id: aid }); return load(); }
    if (b.dataset.a === 'retry-analyze') { b.disabled = true; await api('retry', { id: aid }); return load(); }
    if (b.dataset.a === 'retry-source') { b.disabled = true; await api('source_retry', { id: aid }); return load(); }
    if (b.dataset.a === 'more-open') return openMore();
    if (b.dataset.a === 'clean-open') return openClean();
    if (b.dataset.a === 'finish') {
      const done = b.dataset.done === '1';
      const left = project.items.filter(it => !it.uploaded).length;
      if (done && left && !confirm(`Masih ada ${left} clip yang belum diupload. Tetap selesaikan room ini?`)) return;
      b.disabled = true;
      await api('room_finish', { id: project.id, done });
      toast(done ? 'Room diselesaikan · dipindah ke bagian Room selesai di beranda' : 'Room aktif lagi');
      return load();
    }
    if (b.dataset.a === 'slim-open') return openSlim();
    if (b.dataset.a === 'more-cancel') { b.disabled = true; await api('cancel', { id: D.more.id }); return load(); }
    const it = project.items.find(x => x.iid === b.dataset.iid);
    if (b.dataset.a === 'caption') { const c = tx(it); return copyText((c.caption + '\n\n' + (c.hashtags || []).join(' ')).trim(), capLang === 'en' && it.en ? 'English caption copied' : 'Caption disalin'); }
    if (b.dataset.a === 'copy-title') return copyText(tx(it).title, capLang === 'en' && it.en ? 'English title copied' : 'Judul disalin');
    if (b.dataset.a === 'cap-lang') return setCapLang(b.dataset.v);
    if (b.dataset.a === 'cap-gen') return makeEnglish();
    if (b.dataset.a === 'upload' || b.dataset.a === 'unupload') {
      b.disabled = true;
      const on = b.dataset.a === 'upload';
      await setUploaded(it, on, todayStr());
      return toast(on ? `Clip #${project.items.indexOf(it) + 1} ditandai sudah diupload hari ini` : 'Dipindah ke Belum diupload');
    }
    if (b.dataset.a === 'retry') {
      b.disabled = true;
      await api('project_item', { id: ID, iid: it.iid, start: it.start, end: it.end, layout: it.layout,
        subtitles: it.subtitles, hook_text: it.hook_text });
      return load();
    }
  } catch (err) { toast(err.message); }
});

// Akun pemilik room (read-only): avatar + nama, menuju profil akun
function renderAccountLine() {
  const A = D.account;
  $('acc-line').hidden = !A;
  if (!A) return;
  $('acc-line').href = `account.php?id=${A.id}`;
  $('acc-line').title = `Akun: ${A.name}`;
  $('acc-line').innerHTML = `${accAvatar(A)}<span class="nm">${esc(A.name)}</span>`;
}

// Simpan status upload satu clip, lalu tampilkan ulang (kartu pindah bagian)
async function setUploaded(it, on, date) {
  const r = await api('project_upload', { id: ID, iid: it.iid, uploaded: on, date });
  Object.keys(it).forEach(k => { if (k === 'uploaded' || k === 'uploaded_at') delete it[k]; });
  Object.assign(it, r.item);
  render();
}
// Ganti tanggal upload (mis. sebenarnya diupload kemarin)
$('app').addEventListener('change', async e => {
  const inp = e.target.closest('[data-up-date]');
  if (!inp || !inp.value) return;
  const it = project.items.find(x => x.iid === inp.dataset.upDate);
  try { await setUploaded(it, true, inp.value); toast(`Tanggal upload diubah ke ${dateLabel(inp.value)}`); }
  catch (err) { toast(err.message); render(); }
});

/* ---------- Tambah clip: AI mencari momen baru di luar clip yang sudah ada ---------- */

const more = { count: 3, dur: 0 };
const moreDurs = () => {
  const A = D.analysis || {}, min = A.clip_min || <?= CLIP_MIN_SEC ?>, max = A.clip_max || <?= CLIP_MAX_SEC ?>;
  return [{ min, max, label: 'Sama seperti sebelumnya' }, { min: 15, max: 30, label: 'Singkat & padat' },
    { min: 30, max: 60, label: 'Umum Reels/TikTok' }, { min: 60, max: 90, label: 'Lebih lengkap' }]
    .filter((d, i, all) => i === 0 || d.min !== all[0].min || d.max !== all[0].max);
};
function renderMore() {
  $('more-sub').textContent = `AI mencari momen baru di luar ${project.items.length} clip yang sudah ada.`;
  $('more-cnt').textContent = more.count;
  $('more-dec').disabled = more.count <= 1;
  $('more-inc').disabled = more.count >= 10;
  $('more-durs').innerHTML = moreDurs().map((d, i) => `<button class="dur ${i === more.dur ? 'on' : ''}" data-d="${i}">
    <b>${d.min}–${d.max} dtk</b><small>${d.label}</small></button>`).join('');
}
/* ---------- Riwayat render: satu tombol di topbar, berisi semua clip proyek ---------- */
const fmtWhen = t => new Date(t * 1000).toLocaleString('id-ID', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
let histFilter = '';
function paintHistBtn() {
  const n = Object.values(renders).reduce((a, l) => a + l.filter(r => r.status === 'done').length, 0);
  $('hist-open').hidden = !project;
  $('hist-cnt').textContent = n;
}
function openHistory() {
  histIid = true;
  $('hist-player').hidden = true;
  $('hist-player').innerHTML = '';
  delete $('hist-player').dataset.job;
  renderHistory();
  $('hist-modal').hidden = false;
}
function closeHistory() {
  $('hist-modal').hidden = true;
  $('hist-player').innerHTML = '';
  delete $('hist-player').dataset.job;
  histIid = null;
}
function renderHistory() {
  if (!project) return closeHistory();
  const playing = $('hist-player').dataset.job;
  const groups = project.items.map((it, i) => ({ it, i, list: renders[it.iid] || [] })).filter(g => g.list.length);
  const multi = groups.filter(g => g.list.length > 1);
  if (histFilter === 'multi' && !multi.length) histFilter = '';
  const total = groups.reduce((a, g) => a + g.list.filter(r => r.status === 'done').length, 0);
  $('hist-sub').textContent = `${total} hasil render dari ${groups.length} clip · terbaru di atas`;
  $('hist-tabs').innerHTML = `<button type="button" class="${histFilter ? '' : 'on'}" data-hf="">Semua clip <span class="n">${groups.length}</span></button>
    <button type="button" class="${histFilter === 'multi' ? 'on' : ''}" data-hf="multi" ${multi.length ? '' : 'disabled'}>${ic('refresh')} Dirender ulang <span class="n">${multi.length}</span></button>`;
  const musicTag = r => r.both_music ? `<span class="tag mu">${ic('music')} Dengan & tanpa musik</span>` : r.with_music ? `<span class="tag mu">${ic('music')} Dengan musik</span>` : r.no_music ? `<span class="tag">${ic('volume')} Tanpa musik</span>` : '';
  const info = r => ACTIVE.includes(r.status) ? 'Sedang dirender…' : r.status !== 'done' ? 'Render gagal' : !r.exists ? 'File sudah tidak ada di folder downloads'
    : [fmtWhen(r.done_at), r.duration ? fmtDur(r.duration) : '', r.resolution, r.both_music ? `${fmtSize(r.size_music)} + ${fmtSize(r.size)}` : fmtSize(r.size)].filter(Boolean).join(' · ');
  let multiRow = false;
  const acts = (r, iid) => `${r.status === 'done' && r.exists ? `<button class="btn sm ghost" data-h="play" data-iid="${iid}" data-job="${r.id}" title="Putar">${ic('play')}</button>
      ${r.both_music && r.file_music
        ? `<a class="btn sm" href="downloads/${encodeURIComponent(r.file_music)}?v=${r.id}" download="${esc(r.file_music)}" title="Dengan musik">${ic('music')} Musik</a>
           <a class="btn sm" href="downloads/${encodeURIComponent(r.file)}?v=${r.id}" download="${esc(r.file)}" title="Tanpa musik">${ic('volume')} Tanpa musik</a>`
        : `<a class="btn sm" href="downloads/${encodeURIComponent(r.file)}?v=${r.id}" download="${esc(r.file)}">${ic('download')} Download</a>`}` : ''}
    ${r.current ? (multiRow ? '<span class="h-sp"></span>' : '') : `<button class="btn sm ghost danger" data-h="del" data-iid="${iid}" data-job="${r.id}" title="Hapus versi ini">${ic('trash')}</button>`}`;
  const musicKind = r => r.both_music ? 'both' : r.with_music ? 'mu' : r.no_music ? 'nomu' : '';
  const shown = histFilter === 'multi' ? multi : groups;
  $('hist-list').innerHTML = shown.length ? shown.map(({ it, i, list }) => {
    const thumb = `api.php?action=frame&id=${project.analysis_id}&t=${Math.round((it.start + it.end) / 2 * 10) / 10}`;
    const head = `<img src="${thumb}" alt="" loading="lazy"><div class="tx"><div class="tt" title="${esc(it.title)}"><span class="no">#${i + 1}</span>${esc(it.title)}</div>`;
    // Clip yang baru dirender sekali: satu baris ringkas
    multiRow = list.length > 1;
    if (list.length === 1) {
      const r = list[0];
      return `<div class="h-clip${playing === r.id ? ' on' : ''}"><div class="h-head">${head}
        <div class="sub">${musicTag(r)}<span>${esc(info(r))}</span></div></div><div class="h-act">${acts(r, it.iid)}</div></div></div>`;
    }
    // Clip yang dirender lebih dari sekali: daftar versi dengan garis waktu, perbedaan musik antar versi ditandai
    return `<div class="h-clip multi"><div class="h-head">${head}
        <div class="sub"><span class="h-ver">${ic('refresh')} ${list.length} versi</span><span>dirender ulang ${list.length - 1}×</span></div></div></div>
      <div class="h-vers">${list.map((r, k) => {
        const n = list.length - k, prev = list[k + 1];
        const diff = prev && musicKind(prev) && musicKind(r) && musicKind(prev) !== musicKind(r) ? `<span class="tag diff">beda musik dari versi ${n - 1}</span>` : '';
        return `<div class="h-v${r.current ? ' cur' : ' old'}${playing === r.id ? ' on' : ''}">
          <div class="tx"><div class="t1">Versi ${n}${r.current ? '<span class="tag new">Terbaru</span>' : ''}${musicTag(r)}${diff}</div><div class="t2">${esc(info(r))}</div></div>
          <div class="h-act">${acts(r, it.iid)}</div></div>`;
      }).join('')}</div></div>`;
  }).join('') : '<div class="h-empty">Belum ada hasil render di proyek ini.</div>';
}
$('hist-open').onclick = openHistory;
$('hist-tabs').onclick = e => { const b = e.target.closest('[data-hf]'); if (b && !b.disabled) { histFilter = b.dataset.hf; renderHistory(); } };
$('hist-close').onclick = closeHistory;
$('hist-modal').addEventListener('click', async e => {
  if (e.target === $('hist-modal')) return closeHistory();
  const b = e.target.closest('[data-h]');
  if (!b) return;
  const iid = b.dataset.iid, r = (renders[iid] || []).find(x => x.id === b.dataset.job);
  if (!r) return;
  if (b.dataset.h === 'play') {
    const P = $('hist-player');
    P.dataset.job = r.id;
    P.innerHTML = `<video src="downloads/${encodeURIComponent(r.file_music || r.file)}?v=${r.id}" controls autoplay playsinline></video>`;
    P.hidden = false;
    return renderHistory();
  }
  if (b.dataset.h === 'del') {
    if (!confirm('Hapus render ini? File-nya ikut dihapus dari folder downloads.')) return;
    b.disabled = true;
    try {
      renders[iid] = (await api('render_delete', { id: project.id, iid, job: r.id })).renders;
      if ($('hist-player').dataset.job === r.id) { $('hist-player').hidden = true; $('hist-player').innerHTML = ''; delete $('hist-player').dataset.job; }
      renderHistory();
      paintHistBtn();
      toast('Render dihapus');
    } catch (err) { toast(err.message); b.disabled = false; }
  }
});
document.addEventListener('keydown', e => { if (e.key === 'Escape' && !$('hist-modal').hidden) closeHistory(); });

function openMore() {
  if (!project) return;
  more.dur = 0;
  renderMore();
  $('more-modal').hidden = false;
  $('more-start').disabled = false;
  $('more-start').focus();
}
const closeMore = () => { $('more-modal').hidden = true; };
$('more-close').onclick = $('more-cancel').onclick = closeMore;
$('more-modal').addEventListener('click', e => { if (e.target === $('more-modal')) closeMore(); });
document.addEventListener('keydown', e => { if (e.key === 'Escape' && !$('more-modal').hidden) closeMore(); });
$('more-dec').onclick = () => { more.count--; renderMore(); };
$('more-inc').onclick = () => { more.count++; renderMore(); };
$('more-durs').onclick = e => { const b = e.target.closest('[data-d]'); if (b) { more.dur = +b.dataset.d; renderMore(); } };
$('more-start').onclick = async () => {
  const d = moreDurs()[more.dur];
  $('more-start').disabled = true;
  try {
    await api('analyze_more', { id: project.id, count: more.count, clip_min: d.min, clip_max: d.max });
    closeMore();
    toast('AI mulai mencari clip tambahan');
    load();
  } catch (err) {
    toast(err.message);
    $('more-start').disabled = false;
  }
};

load();
</script>
</body>
</html>
