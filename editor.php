<?php
require __DIR__ . '/config.php';
require_local();
ensure_dirs();
require_once __DIR__ . '/subtitles.php';
require_once __DIR__ . '/editor_lib.php'; // EFFECT_PRESETS
$fonts = [];
foreach (SUB_FONT_LIST as $k => [$label, $cat]) {
    $fonts[] = ['k' => $k, 'label' => $label, 'cat' => $cat, 'family' => sub_font_family($k), 'w' => sub_font_weights($k), 'wf' => 'ClipW ' . $label];
}
$pid = (string) ($_GET['project'] ?? '');
$iid = (string) ($_GET['item'] ?? '');
if (!valid_job_id($pid) || !preg_match('/^[a-f0-9]{8}$/', $iid)) {
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
<title>Editor Clip · BisaClip</title>
<link rel="stylesheet" href="assets/app.css?v=<?= filemtime(__DIR__ . '/assets/app.css') ?>">
<style>
<?php foreach (SUB_FONT_LIST as $k => $_): if ($k !== 'arial-black'): ?>
  @font-face { font-family: "<?= sub_font_family($k) ?>"; src: url("assets/fonts/<?= $k ?>.ttf") format("truetype"); font-display: swap; }
<?php endif; endforeach ?>
<?php foreach (SUB_FONT_LIST as $k => [$label]): foreach (sub_font_weights($k) as $w): ?>
  @font-face { font-family: "ClipW <?= $label ?>"; src: url("assets/fonts/<?= $k ?>-w<?= $w ?>.ttf") format("truetype"); font-weight: <?= $w ?>; font-display: swap; }
<?php endforeach; endforeach ?>
  /* Editor memenuhi satu layar: bar, preview + properti, lalu daftar clip proyek selalu terlihat tanpa scroll halaman */
  html, body { height: 100%; }
  body { overflow: hidden; }
  /* Selebar layar di monitor sebesar apa pun (tanpa batas lebar) */
  .wrap { max-width: none; height: 100vh; display: flex; flex-direction: column; padding: 0 16px 12px; }
  .bar { display: flex; align-items: center; gap: 12px; padding: 10px 0; flex: none; }
  /* Grid: panel clip kiri + editor (preview & properti) di atas, timeline selebar layar di bawah.
     #main memakai display: contents supaya isinya (pending, .ed, .tlbar, .prep) langsung jadi item grid. */
  #app { flex: 1; min-height: 0; display: grid; grid-template-columns: 300px minmax(0, 1fr);
    grid-template-rows: auto minmax(0, 1fr) auto auto; grid-template-areas: "clips pend" "clips ed" "tl tl" "rv rv"; column-gap: 12px; }
  #main { display: contents; }
  #main > .pending { grid-area: pend; }
  #main > .ed, #main > .prep { grid-area: ed; }
  .clips { grid-area: clips; }
  .bar .title { flex: 1; min-width: 0; padding-left: 14px; margin-left: 2px; border-left: 1px solid var(--border); }
  .bar .title b { display: block; font-size: 15px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .bar .title span { font-size: 12.5px; color: var(--muted); }
  .bar .title span.dirty { color: var(--warn); }

  /* Area atas: preview + properti */
  .ed { flex: 1; min-height: 0; display: grid; grid-template-columns: minmax(0, 1fr) 380px; gap: 12px; }
  .stage { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 8px; padding: 12px; min-height: 0; }
  /* Tinggi preview mengikuti ruang yang tersedia; lebarnya dihitung dari rasio 9:16 */
  .phone { position: relative; flex: 1 1 0; min-height: 280px; width: auto; max-width: 100%; aspect-ratio: 9/16; background: #000; border-radius: 14px; overflow: hidden;
    box-shadow: none; }
  .phone canvas { position: absolute; inset: 0; width: 100%; height: 100%; display: block; }
  .kbox { position: absolute; z-index: 18; pointer-events: auto; cursor: move; border: 1.5px dashed transparent; border-radius: 6px; transition: border-color .12s; }
  .kbox:hover, .kbox.drag { border-color: rgba(110, 168, 255, .8); }
  .phone.l-sub .kbox { pointer-events: none; }
  .kcv { position: absolute; inset: 0; width: 100%; height: 100%; pointer-events: none; }
  .ovl { position: absolute; inset: 0; pointer-events: none; font-family: "Arial Black", "Arial", sans-serif; }
  .sub { position: absolute; left: 0; right: 0; text-align: center; line-height: 1.12; padding: 0 calc(70px * var(--k));
    paint-order: stroke fill; -webkit-text-stroke: calc(14px * var(--k)) #000; letter-spacing: calc(1px * var(--k));
    text-shadow: 0 calc(5px * var(--k)) calc(2px * var(--k)) rgba(0, 0, 0, .55); word-wrap: break-word; }
  /* Kata aktif 110% seperti \fscx110 di ASS: ikut memakan lebar, jadi spasi antarkata tetap */
  .sub i { font-style: inherit; font-size: 1.1em; line-height: 0; }
  .hookbox { position: absolute; left: calc(70px * var(--k)); right: calc(70px * var(--k)); text-align: center; line-height: 1.5; }
  .hookbox span { background: var(--hb, #fff); color: var(--hc, #111); font-size: calc(var(--hs, 68) * 1px * var(--k));
    padding: calc(var(--hs, 68) * .147px * var(--k)) calc(var(--hs, 68) * .382px * var(--k));
    -webkit-box-decoration-break: clone; box-decoration-break: clone; }
  /* Bar bawah selebar editor: kontrol putar di atas, timeline seek & trim di bawahnya */
  .tlbar { grid-area: tl; margin-top: 12px; padding: 0 18px 36px; display: flex; flex-direction: column; gap: 6px; }
  /* Kontrol putar di bawah preview: durasi di atas, tombol ke awal / putar / ke akhir di bawahnya */
  /* Area preview bisa di-zoom & di-scroll */
  .pv-view { flex: 1 1 0; min-height: 0; width: 100%; overflow: auto; display: flex; scrollbar-width: thin; }
  .pv-view .phone { margin: auto; flex: none; height: 100%; } /* preview mengikuti tinggi area yang tersedia */
  .pv-view .phone.zoomed { height: var(--ph); max-height: none; min-height: 0; }
  /* Baris kontrol di bawah preview: durasi (kiri) · putar (tengah) · zoom (kanan) */
  .pv-zoom { grid-column: 3; grid-row: 1; justify-self: end; display: flex; align-items: center; gap: 1px; padding: 2px;
    border: 1px solid var(--border); border-radius: 9px; background: var(--surface-2); }
  .pv-zoom .btn { min-width: 24px; height: 24px; padding: 0 6px; border-radius: 6px; font-size: 13px; font-variant-numeric: tabular-nums; }
  #pz-val { min-width: 44px; font-size: 11.5px; font-weight: 600; }
  #pz-full .i { width: 14px; height: 14px; }
  /* Preview layar penuh: video sebesar layar, kontrol putar tetap di bawah; zoom tidak dipakai */
  .stage:fullscreen { width: 100vw; height: 100vh; padding: 16px 16px 20px; border: 0; border-radius: 0; background: #000; }
  .stage:fullscreen .pv-view .phone, .stage:fullscreen .pv-view .phone.zoomed { height: 100%; max-height: none; }
  .stage:fullscreen #pz-out, .stage:fullscreen #pz-val, .stage:fullscreen #pz-in { display: none; }
  .transport { display: grid; grid-template-columns: 1fr auto 1fr; align-items: center; gap: 10px; width: 100%; max-width: 460px; flex: none; }
  .transport .time { grid-column: 1; grid-row: 1; justify-self: start; font-variant-numeric: tabular-nums; font-size: 12.5px; color: var(--muted); white-space: nowrap; }
  .tp-btns { grid-column: 2; grid-row: 1; display: flex; align-items: center; justify-content: center; gap: 10px; }
  .tp-skip { width: 34px; height: 34px; padding: 0; border-radius: 50%; }
  .tp-skip .i { width: 16px; height: 16px; }
  .loading-stage { position: absolute; inset: 0; display: grid; place-items: center; color: #fff; font-size: 13px; }
  /* Overlay proses di atas preview (deteksi split, menerapkan toleransi) */
  .busy { position: absolute; inset: 0; z-index: 4; display: flex; flex-direction: column; align-items: center; justify-content: center;
    gap: 10px; padding: 24px; text-align: center; color: #fff; background: rgba(10, 10, 10, .62); backdrop-filter: blur(6px);
    opacity: 0; pointer-events: none; transition: opacity .2s; }
  .busy.show { opacity: 1; pointer-events: auto; }
  .busy .ring { width: 38px; height: 38px; border-radius: 50%; border: 3px solid rgba(255, 255, 255, .2); border-top-color: #fff;
    animation: spin .8s linear infinite; }
  .busy b { font-size: 14.5px; }
  .busy small { font-size: 12px; color: rgba(255, 255, 255, .72); line-height: 1.45; max-width: 220px; }
  .busy .bar2 { width: 160px; height: 4px; border-radius: 2px; background: rgba(255, 255, 255, .18); overflow: hidden; }
  .busy .bar2 i { display: block; height: 100%; width: 40%; background: #fff; border-radius: 2px; animation: slide 1.1s ease-in-out infinite; }
  @keyframes slide { 0% { transform: translateX(-100%); } 100% { transform: translateX(260%); } }
  /* Ikon bantuan "?" kecil; penjelasan muncul sebagai tooltip saat hover */
  .tip { display: inline-grid; place-items: center; width: 13px; height: 13px; flex: none; border-radius: 50%;
    border: 1px solid var(--border-strong); color: var(--muted); font-size: 8.5px; font-weight: 700; line-height: 1; cursor: help;
    margin-left: 5px; vertical-align: 1px; opacity: .65; transition: opacity .15s, color .15s, border-color .15s; }
  .tip:hover { opacity: 1; color: var(--text); border-color: var(--muted); }
  /* Tooltip mengambang (di luar panel supaya tidak terpotong); posisinya diatur JS agar selalu muat di layar */
  .tipbox { position: fixed; z-index: 60; width: max-content; max-width: 240px; padding: 8px 10px; background: var(--surface-2); color: var(--text-2);
    border: 1px solid var(--border-strong); font-size: 12px; font-weight: 500; line-height: 1.45; border-radius: 10px; pointer-events: none;
    box-shadow: none;
    opacity: 0; transform: translateY(4px) scale(.97); transition: opacity .16s ease, transform .2s cubic-bezier(.2, .8, .2, 1); }
  .tipbox.up { transform: translateY(-4px) scale(.97); }
  .tipbox.show { opacity: 1; transform: none; transition-delay: 90ms; }

  /* Kolom kanan: beberapa card panel bertumpuk, di-scroll bersama */
  .props { overflow-y: auto; min-height: 0; display: flex; flex-direction: column; gap: 12px; }
  /* Tab panel kanan (tanpa ikon), gaya sama dengan tab panel kiri */
  .pt-tabs { flex: none; position: sticky; top: 0; z-index: 2; display: flex; gap: 4px; padding: 6px; border: 1px solid var(--border);
    border-radius: var(--radius); background: var(--surface); }
  .pt-tab { flex: 1; height: 34px; border: 0; border-radius: 10px; background: none; color: var(--muted); font: inherit; font-size: 13.5px;
    font-weight: 600; cursor: pointer; transition: background .15s, color .15s; }
  .pt-tab:hover { color: var(--text); background: var(--surface-2); }
  .pt-tab.on, .pt-tab.on:hover { color: #fff; background: var(--surface-3); box-shadow: none; }
  /* Slider lembut (semua slider editor). --p = persentase isi, diatur syncRange() */
  input[type=range] { -webkit-appearance: none; appearance: none; height: 18px; margin: 0; background: transparent; cursor: pointer; accent-color: auto; }
  input[type=range]:focus { outline: none; }
  input[type=range]::-webkit-slider-runnable-track { height: 4px; border-radius: 2px;
    background: linear-gradient(to right, rgba(120, 165, 230, .5) var(--p, 50%), rgba(255, 255, 255, .09) var(--p, 50%)); }
  input[type=range]::-webkit-slider-thumb { -webkit-appearance: none; width: 14px; height: 14px; margin-top: -5px; border: 0; border-radius: 50%;
    background: #d9e1eb; box-shadow: none; transition: transform .12s, background .12s; }
  input[type=range]:hover::-webkit-slider-thumb { transform: scale(1.12); background: #fff; }
  input[type=range]:focus-visible::-webkit-slider-thumb { box-shadow: 0 0 0 4px rgba(120, 165, 230, .25); }
  input[type=range]::-moz-range-track { height: 4px; border-radius: 2px; background: rgba(255, 255, 255, .09); }
  input[type=range]::-moz-range-progress { height: 4px; border-radius: 2px; background: rgba(120, 165, 230, .5); }
  input[type=range]::-moz-range-thumb { width: 14px; height: 14px; border: 0; border-radius: 50%; background: #d9e1eb; box-shadow: none; }
  input[type=range]:disabled { opacity: .35; cursor: default; }
  .vol-row { display: flex; align-items: center; gap: 10px; }
  .vol-row > .i { width: 18px; height: 18px; flex: none; color: var(--muted); }
  .vol-row .p-range { flex: 1; }
  .vol-row .btn { flex: none; font-variant-numeric: tabular-nums; }
  .p-card { flex: none; padding: 0; transition: border-color .2s, box-shadow .2s; }
  .p-card.focus { border-color: rgba(30, 112, 240, .69); box-shadow: 0 0 0 3px rgba(30, 112, 240, .19); }
  .p-sec { padding: 16px 18px; border-bottom: 1px solid var(--border); }
  .p-sec:last-child { border-bottom: 0; }
  .p-sec h3 { font-size: 13px; margin: 0 0 12px; display: flex; justify-content: space-between; align-items: center; gap: 8px; }
  .p-row { display: flex; align-items: center; gap: 10px; margin-top: 10px; font-size: 13.5px; }
  .p-sec h3 + .p-row, .p-sec h3 + .p-field { margin-top: 0; }
  /* Field bertumpuk: label kecil di atas, kontrol di bawah */
  .p-field { margin-top: 16px; }
  .p-row + .p-field, .p-field + .p-row { margin-top: 16px; }
  .p-label { display: flex; justify-content: space-between; align-items: baseline; gap: 8px; margin-bottom: 8px;
    font-size: 12px; font-weight: 500; color: var(--muted); }
  .p-label .cnt, .p-label .val { width: auto; font-size: 11.5px; font-variant-numeric: tabular-nums; color: var(--muted); }
  .p-input { padding: 9px 12px; font-size: 14px; }
  textarea.p-input { width: 100%; resize: vertical; min-height: 76px; line-height: 1.45; font: inherit; font-size: 14px; }
  /* Template grafik: contoh tampil di atas "frame video" gelap */
  .p-hint { margin-top: 6px; font-size: 11.5px; line-height: 1.45; color: var(--muted); }
  .p-hint b { color: var(--text-2); }
  .gfx-grid { flex: 1; min-height: 0; display: grid; grid-template-columns: 1fr; gap: 10px; padding: 6px 12px 12px; align-content: start; overflow-y: auto; }
  .gfxt { display: flex; flex-direction: column; gap: 7px; padding: 6px; border: 1px solid var(--border); border-radius: 12px; background: var(--surface-2);
    color: var(--text-2); font: inherit; font-size: 12px; font-weight: 600; cursor: pointer; text-align: left; transition: border-color .15s, background .15s; }
  .gfxt:hover { border-color: var(--border-strong); background: var(--surface-3); }
  .gfx-prev { display: grid; place-items: center; height: 92px; border-radius: 8px; overflow: hidden;
    background: radial-gradient(circle at 30% 20%, #3a4458, #141922 70%); }
  .gfx-pick { display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px; }
  .gpk { display: flex; flex-direction: column; gap: 5px; padding: 5px; border: 1px solid var(--border); border-radius: 10px; background: var(--surface-2);
    color: var(--text-2); font: inherit; font-size: 11.5px; font-weight: 600; cursor: pointer; transition: border-color .15s, background .15s, color .15s; }
  .gpk:hover { border-color: var(--border-strong); color: var(--text); }
  .gpk.on { border-color: var(--accent); box-shadow: 0 0 0 1px var(--accent); color: var(--accent-fg); }
  .gpk-img { display: grid; place-items: center; height: 58px; border-radius: 7px; overflow: hidden; background: radial-gradient(circle at 30% 20%, #3a4458, #141922 70%); }
  .gpk-img img { max-width: 92%; max-height: 52px; }
  .gpk-nm { padding: 0 3px 1px; text-align: left; }
  .gfx-prev canvas { max-width: 92%; max-height: 84px; }
  .gfxt span:last-child { padding: 0 4px 2px; }
  .p-range { display: block; width: 100%; margin: 0; accent-color: var(--accent); }
  .p-field .fontlist { margin-top: 0; }
  .p-row > label:first-child { width: 92px; flex: none; color: var(--muted); font-size: 12.5px; }
  .p-row input[type=range] { flex: 1; accent-color: var(--accent); }
  .p-row input[type=color] { width: 36px; height: 28px; border: 1px solid var(--border); border-radius: 7px; background: none; padding: 2px; cursor: pointer; }
  .p-row .val { width: 44px; text-align: right; font-variant-numeric: tabular-nums; color: var(--muted); font-size: 12.5px; }
  .switch { display: inline-flex; align-items: center; gap: 8px; cursor: pointer; font-size: 13.5px; }
  /* Semua pilihan aktif/nonaktif tampil sebagai toggle; menyala biru (gradasi) saat aktif */
  .switch input { -webkit-appearance: none; appearance: none; position: relative; flex: none; width: 36px; height: 20px; margin: 0;
    border-radius: 999px; border: 1px solid var(--border); background: var(--surface-3); cursor: pointer; transition: background .2s, box-shadow .2s; }
  .switch input::after { content: ""; position: absolute; top: 2px; left: 2px; width: 14px; height: 14px; border-radius: 50%;
    background: #fff; box-shadow: none; transition: transform .2s; }
  .switch input:checked { background: var(--grad); border-color: transparent; box-shadow: none; }
  .switch input:checked::after { transform: translateX(16px); }
  .switch input:disabled { opacity: .5; cursor: default; }
  .switch input:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }
  .framings { display: grid; grid-template-columns: repeat(3, 1fr); gap: 6px; }
  .framings.two { grid-template-columns: repeat(2, 1fr); }
  /* Background bingkai: Solid / Blur dengan contoh kecil */
  .ratios.six { grid-template-columns: repeat(6, 1fr); }
  .ratios.lay { grid-template-columns: repeat(4, 1fr); }
  .ratio .shape.mix { display: flex; flex-direction: column; justify-content: center; gap: 2px; background: none; }
  .ratio .shape.mix i { width: 18px; height: 15px; }
  .ratio .shape.mix i + i { opacity: .45; background: repeating-linear-gradient(135deg, currentColor 0 2px, transparent 2px 4px); }
  /* Split mix: media panel bawah */
  .mix-up { display: flex; align-items: center; gap: 12px; margin-top: 10px; padding: 14px; border: 1px dashed var(--border-strong); border-radius: 10px; cursor: pointer; color: var(--text-2); }
  .mix-up:hover { border-color: var(--accent); color: var(--text); }
  .mix-up > .i { width: 20px; height: 20px; flex: none; color: var(--accent-fg); }
  .mix-up b { display: block; font-size: 13px; }
  .mix-up small { display: block; font-size: 11.5px; color: var(--muted); margin-top: 2px; line-height: 1.4; }
  .mix-th { width: 44px; height: 44px; border-radius: 8px; object-fit: cover; background: var(--surface-3); flex: none; }
  .mix-cur { display: flex; align-items: center; gap: 10px; margin-top: 10px; font-size: 12.5px; }
  .mix-cur b { display: block; font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .mix-cur > span { flex: 1; min-width: 0; }
  /* Kotak atur media Split mix di preview: seret = geser, sudut = ubah ukuran (sudut seberang tetap) */
  .mixbox { position: absolute; z-index: 18; pointer-events: auto; cursor: move; border: 1.5px dashed var(--accent); box-shadow: 0 0 0 1px rgba(0, 0, 0, .35); touch-action: none; }
  .mixbox > i { position: absolute; width: 12px; height: 12px; background: #fff; border: 1.5px solid var(--accent); border-radius: 3px; }
  .mixbox > i::before { content: ""; position: absolute; inset: -8px; }
  .mixbox > i[data-mc="tl"] { left: -6px; top: -6px; cursor: nwse-resize; }
  .mixbox > i[data-mc="tr"] { right: -6px; top: -6px; cursor: nesw-resize; }
  .mixbox > i[data-mc="bl"] { left: -6px; bottom: -6px; cursor: nesw-resize; }
  .mixbox > i[data-mc="br"] { right: -6px; bottom: -6px; cursor: nwse-resize; }
  .mix-hint { margin: 8px 0 0; font-size: 11.5px; color: var(--muted); line-height: 1.45; }
  /* Bagian animasi di bawah setelan item terpilih */
  .anim-head { display: flex; align-items: center; gap: 7px; padding: 14px 18px 0; font-size: 12px; font-weight: 700; letter-spacing: .04em;
    text-transform: uppercase; color: var(--accent-fg); }
  .anim-head .i { width: 14px; height: 14px; }
  .ratio .shape.split { display: flex; flex-direction: column; justify-content: center; gap: 2px; background: none; }
  .ratio .shape.split i { width: 18px; height: 15px; }
  .split-opts { margin-top: 10px; padding: 10px; border: 1px solid var(--border); border-radius: 10px; background: var(--surface-2); }
  .split-lbl { margin-bottom: 8px; font-size: 11.5px; font-weight: 600; color: var(--muted); }
  .splits { display: grid; grid-template-columns: repeat(5, 1fr); gap: 6px; }
  .spl { display: flex; flex-direction: column; align-items: center; gap: 5px; padding: 7px 2px 6px; border: 1px solid var(--border-strong); border-radius: 8px;
    background: var(--surface); color: var(--text-2); font: inherit; font-size: 11px; font-weight: 600; font-variant-numeric: tabular-nums; cursor: pointer; }
  .spl .shape { display: flex; flex-direction: column; gap: 2px; width: 16px; height: 28px; }
  .spl .shape i { display: block; min-height: 3px; border-radius: 2px; background: var(--muted); }
  .spl:hover { border-color: var(--muted); }
  .spl.on { border-color: var(--accent); background: var(--accent-soft); color: var(--accent-fg); }
  .spl.on .shape i { background: var(--accent-fg); }
  .split-opts .p-row { margin-top: 8px; }
  .bgs { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
  .bgopt { display: flex; align-items: center; gap: 9px; height: 40px; padding: 0 10px; border: 1px solid var(--border-strong); border-radius: 10px;
    background: var(--surface); color: var(--text); font: inherit; font-size: 13px; font-weight: 600; cursor: pointer; transition: border-color .15s, background .15s; }
  .bgopt:hover:not(:disabled) { border-color: var(--muted); }
  .bgopt.on { border-color: var(--accent); background: var(--accent-soft); color: var(--accent-fg); }
  .bgopt:disabled { opacity: .45; cursor: default; }
  .bgopt span { width: 16px; height: 24px; border-radius: 4px; box-shadow: inset 0 0 0 1px rgba(255, 255, 255, .18); }
  .bgopt .sw-solid { background: #000; }
  .bgopt .sw-blur { background: linear-gradient(160deg, #5b8fd8, #2a3d63 55%, #c48a5a); filter: blur(1.5px); }
  .ratios { display: grid; grid-template-columns: repeat(5, 1fr); gap: 6px; }
  .ratio { display: flex; flex-direction: column; align-items: center; gap: 6px; padding: 9px 4px 7px; border: 1px solid var(--border); border-radius: 9px;
    background: none; color: var(--text-2); font: inherit; font-size: 11.5px; font-weight: 600; cursor: pointer; transition: border-color .15s, background .15s, color .15s; }
  .ratio:hover { border-color: var(--border-strong); color: var(--text); }
  .ratio.on { border-color: var(--accent); background: var(--accent-soft); color: var(--accent-fg); }
  .ratio .shape { width: 18px; height: 32px; display: grid; place-items: center; border-radius: 3px; background: rgba(148, 172, 214, .14); }
  .ratio .shape i { display: block; width: 18px; border-radius: 2px; background: currentColor; opacity: .8; }
  .splitband { position: absolute; top: 0; bottom: 0; background: rgba(var(--c-split-rgb), .3); border-left: 2px solid var(--c-split);
    pointer-events: none; }
  .splitband span { position: absolute; left: 4px; bottom: 2px; font-size: 9.5px; font-weight: 800; color: #b9a8ff; letter-spacing: .04em; }
  .framing { border: 1px solid var(--border); border-radius: 9px; padding: 8px 6px; cursor: pointer; text-align: center;
    background: var(--surface); font: inherit; font-size: 12px; color: inherit; line-height: 1.3; white-space: nowrap; }
  .framing.on { border-color: var(--accent); background: var(--accent-soft); color: var(--accent-fg); font-weight: 600; }
  .stepper { display: flex; align-items: center; background: var(--surface-2); border: 1px solid var(--border); border-radius: 8px; flex: 1; }
  .stepper input { width: 100%; min-width: 0; border: 0; background: transparent; color: var(--text); font: inherit; font-size: 13px;
    text-align: center; padding: 6px 0; outline: none; font-variant-numeric: tabular-nums; }
  .stepper button { font: inherit; border: 0; background: none; color: var(--muted); padding: 6px 8px; cursor: pointer; }
  .stepper button:hover { color: var(--text); }
  textarea.input { resize: vertical; min-height: 70px; font-size: 15px; line-height: 1.45; }
  .p-actions { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; margin-top: 12px; }
  .p-actions .btn { min-width: 0; white-space: nowrap; line-height: 1.3; padding: 7px 8px; font-size: 12.5px; }
  /* Panel pengaturan teks (subtitle & hook), gaya ala tool desain */
  .ts { display: flex; flex-direction: column; gap: 8px; padding: 10px; border: 1px solid var(--border); border-radius: 14px; background: rgba(255, 255, 255, .015); }
  .ts-row { display: flex; gap: 6px; min-width: 0; }
  .ts-f { position: relative; display: flex; align-items: center; gap: 7px; height: 36px; min-width: 0; flex: 1; padding: 0 10px;
    border: 1px solid var(--border); border-radius: 10px; background: var(--surface-2); font-size: 13px; transition: border-color .15s, box-shadow .15s; }
  .ts-f:focus-within { border-color: var(--accent); box-shadow: var(--ring); }
  .ts-f.grow { flex: 1; }
  .ts-f.w-size { flex: 0 0 76px; }
  .ts-f.w-wt { flex: 0 0 116px; }
  button.ts-f { justify-content: center; color: var(--text-2); cursor: pointer; font: inherit; font-size: 12.5px; white-space: nowrap; flex: 0 0 auto; }
  button.ts-f:hover { color: var(--text); border-color: var(--border-strong); }
  .ts-f select, .ts-f input { min-width: 0; width: 100%; border: 0; outline: 0; background: none; color: var(--text); font: inherit; padding: 0; }
  .ts-f select { appearance: none; -webkit-appearance: none; cursor: pointer; padding-right: 16px; }
  /* Pemilih font: tombol bertuliskan nama font dalam font itu; daftar pilihan dengan contoh teks */
  button.ts-f.fpk-btn { justify-content: space-between; flex: 1; padding-right: 28px; font-size: 14px; font-weight: 400; color: var(--text); text-align: left; }
  .fpk-btn span { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .fpk-btn .chev { transition: transform .15s; }
  .fpk-btn.on { border-color: var(--accent); }
  .fpk-btn.on .chev { transform: rotate(180deg); }
  .fpk-list { max-height: 300px; overflow-y: auto; display: grid; grid-template-columns: 1fr 1fr; gap: 6px; padding: 8px; margin-top: 2px;
    border: 1px solid var(--border-strong); border-radius: 10px; background: var(--surface-2); scrollbar-width: thin; }
  .fpk-cat { grid-column: 1 / -1; padding: 6px 2px 0; font-size: 11px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; color: var(--muted); }
  .fpk-cat:first-child { padding-top: 0; }
  .fpk-opt { display: flex; align-items: center; min-width: 0; min-height: 44px; padding: 8px 10px; border: 1px solid var(--border);
    border-radius: 8px; background: var(--surface); color: var(--text); font: inherit; cursor: pointer; text-align: left; transition: border-color .15s, background .15s; }
  .fpk-opt:hover { border-color: var(--border-strong); background: var(--surface-3); }
  .fpk-opt.on { border-color: var(--accent); background: var(--accent-soft); }
  .fpk-s { width: 100%; font-size: 17px; font-weight: 400; line-height: 1.25; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .ts-f select option, .ts-f select optgroup { background: var(--surface-2); color: var(--text); }
  .ts-f input { font-variant-numeric: tabular-nums; -moz-appearance: textfield; }
  .ts-f input::-webkit-inner-spin-button, .ts-f input::-webkit-outer-spin-button { -webkit-appearance: none; margin: 0; }
  .ts-f .chev { position: absolute; right: 9px; width: 13px; height: 13px; color: var(--muted); pointer-events: none; }
  .ts-f .u { flex: none; font-size: 11.5px; color: var(--muted); }
  .ts-f > .i { flex: none; width: 15px; height: 15px; color: var(--muted); }
  .ts-tools { display: flex; gap: 2px; padding: 3px; border-radius: 10px; background: var(--surface-2); border: 1px solid var(--border); }
  .ts-tools .sep { width: 1px; margin: 6px 3px; background: var(--border-strong); }
  .tb { flex: 1; height: 30px; display: grid; place-items: center; border: 0; border-radius: 8px; background: none; color: var(--muted); cursor: pointer;
    transition: background .12s, color .12s; }
  .tb .i { width: 16px; height: 16px; }
  .tb:hover { color: var(--text); background: var(--surface-3); }
  .tb.on { color: #fff; background: var(--accent-soft); box-shadow: inset 0 0 0 1px rgba(30, 112, 240, .45); }
  .ts-c { display: flex; align-items: center; gap: 8px; padding: 8px 8px 8px 12px; border: 1px solid var(--border); border-radius: 14px; background: rgba(255, 255, 255, .015); }
  .ts-c > label { width: 80px; flex: none; display: flex; align-items: center; font-size: 12.5px; color: var(--text-2); }
  .ts-c > label .tip { margin-left: 4px; }
  .lp-title .tip { vertical-align: 2px; }
  .ts-c .ts-f { height: 34px; }
  .ts-c .ts-f.hex { flex: 1.5; padding-left: 6px; }
  .ts-c .ts-f.hex input { text-transform: uppercase; }
  .sw { position: relative; flex: none; width: 22px; height: 22px; border-radius: 7px; box-shadow: inset 0 0 0 1px rgba(255, 255, 255, .22); cursor: pointer; overflow: hidden;
    background-image: linear-gradient(var(--c), var(--c)), repeating-conic-gradient(#999 0 25%, #555 0 50%); background-size: 100%, 8px 8px; }
  .sw input { position: absolute; inset: -6px; width: calc(100% + 12px); height: calc(100% + 12px); opacity: 0; cursor: pointer; }
  /* Format teks contoh (tidak ada teks terpilih): hanya tampilan */
  .ts-off { margin: 0; padding: 0; border: 0; min-width: 0; opacity: .5; }
  .ts-off * { cursor: default !important; }
  .ts-stack { display: flex; flex-direction: column; gap: 8px; margin-top: 12px; }
  .fontlist { margin-top: 10px; max-height: 196px; overflow-y: auto; border: 1px solid var(--surface-3); border-radius: 10px; padding: 4px 8px 8px; }
  .fontlist h4 { margin: 8px 0 6px; font-size: 10.5px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: var(--muted); }
  .fontgrid { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; }
  .fontopt { border: 1px solid var(--surface-3); border-radius: 8px; background: var(--surface); color: inherit; cursor: pointer;
    padding: 7px 8px; font-size: 15px; line-height: 1.2; text-align: left; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .fontopt:hover { border-color: var(--muted); }
  .fontopt.on { border-color: var(--accent); background: var(--accent-soft); color: var(--accent-fg); }
  .ov-file { display: flex; align-items: center; gap: 10px; margin-top: 10px; }
  .ov-thumb { width: 96px; height: 54px; flex: none; border-radius: 8px; border: 1px solid var(--surface-3); object-fit: contain;
    background: var(--surface-2); }
  .ov-file .btns { display: flex; gap: 6px; flex-wrap: wrap; }
  .hint { font-size: 12px; color: var(--muted); margin: 8px 0 0; line-height: 1.45; }
  .back-link { font: inherit; font-size: 12.5px; background: none; border: 0; color: var(--muted); cursor: pointer; padding: 0; }
  .back-link:hover { color: var(--text); }

  /* Bar seek & trim di bawah preview: seluruh rentang rekomendasi AI, bagian berwarna = clip */
  .phone, .scrub, .transport, .clips, .reels { -webkit-user-select: none; user-select: none; }
  body.dragging, body.dragging * { cursor: ew-resize !important; -webkit-user-select: none; user-select: none; }
  .scrub { width: 100%; flex: none; display: flex; gap: 10px; padding: 0 6px 0 0; }
  /* Area timeline bisa di-scroll horizontal saat diperbesar (zoom > 100%) */
  .tl-view { flex: 1; min-width: 0; overflow-x: auto; overflow-y: hidden; padding: 14px 7px 6px; margin: -4px 0 -6px; scrollbar-width: thin; }
  .tl-view .sc-inner { min-width: 100%; }
  /* Spektrum suara clip (dari audio video preview) */
  .wave { position: absolute; top: 124px; height: 18px; z-index: 1; border-radius: 0 0 8px 8px; pointer-events: none;
    background: linear-gradient(rgba(6, 8, 12, 0), rgba(6, 8, 12, .55)); }
  .sc-inner.m-audio .wave { opacity: .35; }
  /* Kolom ikon jenis track di kiri timeline (sejajar tiap track) */
  /* Kolom kiri timeline: ikon track + kontrol sembunyikan / bisukan / kunci */
  /* Kolom kiri timeline: chip ikon berwarna per track + kontrol sembunyikan / kunci / bisukan dalam kolom sejajar */
  .tl-gut { position: relative; flex: none; width: 96px; margin-top: 10px; border-right: 1px solid var(--border); cursor: default; }
  .tl-gut .trk { position: absolute; left: 0; right: 8px; display: grid; grid-template-columns: 22px 1fr repeat(3, 18px); column-gap: 2px; align-items: center; }
  .tl-gut .ti { grid-column: 1; width: 22px; height: 22px; display: grid; place-items: center; border-radius: 7px; color: #e4e7ec;
    background: rgba(255, 255, 255, .06); box-shadow: inset 0 0 0 1px rgba(255, 255, 255, .08); transition: opacity .15s; }
  .tl-gut .ti .i { width: 14px; height: 14px; }
  .tl-gut .tc { width: 18px; height: 18px; display: grid; place-items: center; padding: 0; border: 0; border-radius: 5px; background: none;
    color: rgba(228, 231, 236, .5); cursor: pointer; transition: background .15s, color .15s; }
  .tl-gut .tc[data-tc="hide"] { grid-column: 3; }
  .tl-gut .tc[data-tc="lock"] { grid-column: 4; }
  .tl-gut .tc[data-tc="mute"] { grid-column: 5; }
  .tl-gut .tc .i { width: 12px; height: 12px; }
  .tl-gut .tc:hover { background: rgba(255, 255, 255, .07); color: #f2f4f7; }
  .tl-gut .tc.on { color: #f2f4f7; background: rgba(255, 255, 255, .1); }
  .tl-gut .trk.off .ti { opacity: .35; }
  .tl-gut .t-media { top: 22px; height: 24px; }
  .tl-gut .t-text { top: 50px; height: 24px; }
  .tl-gut .t-fx { top: 78px; height: 24px; }
  .tl-gut .t-video { top: 106px; height: 36px; }
  .tl-gut .t-sub { top: 148px; height: 22px; }
  .tl-gut .t-music { top: 176px; height: 24px; }
  .tl-gut .t-sfx { top: 206px; height: 22px; }
  /* Track disembunyikan: item pudar; dikunci: item tidak bisa dipilih / digeser (klik = pindah playhead) */
  .sc-inner.h-media .sc-media, .sc-inner.h-text .sc-text, .sc-inner.h-fx .sc-fx, .sc-inner.h-sub .sc-lbl,
  .sc-inner.h-video .film, .sc-inner.h-video .sc-foot { opacity: .3; }
  .sc-inner.l-media .sc-media, .sc-inner.l-text .sc-text, .sc-inner.l-fx .sc-fx, .sc-inner.l-sub .sc-lbl,
  .sc-inner.l-video .sc-foot, .sc-inner.l-video .sc-tr, .sc-inner.l-video .sc-clip .handle { pointer-events: none; }
  .sc-inner.l-media .sc-media, .sc-inner.l-text .sc-text, .sc-inner.l-fx .sc-fx, .sc-inner.l-sub .sc-lbl, .sc-inner.l-video .sc-foot {
    background-image: repeating-linear-gradient(135deg, rgba(0, 0, 0, .28) 0 4px, transparent 4px 8px); }
  .phone.l-media .mimg, .phone.l-text .tbox, .phone.l-sub .sub { pointer-events: none; }
  .sc-inner { position: relative; height: 232px; cursor: pointer; touch-action: none; overflow: visible; }
  /* Penggaris waktu di atas track */
  .ruler { position: absolute; left: 0; right: 0; top: 0; height: 20px; pointer-events: none; }
  .ruler i { position: absolute; bottom: 2px; width: 1px; height: 4px; background: var(--border-strong); }
  .ruler i.maj { height: 8px; background: var(--muted); opacity: .6; }
  .ruler span { position: absolute; top: 0; padding-left: 4px; font-size: 10.5px; color: var(--muted); font-variant-numeric: tabular-nums; white-space: nowrap; }
  /* Track video: deretan frame dari clip */
  .film { position: absolute; left: 0; right: 0; top: 106px; height: 36px; display: flex; border-radius: 8px; overflow: hidden;
    background: var(--surface-2); pointer-events: none; }
  .film > * { flex: 1 1 0; min-width: 0; height: 100%; }
  .film img { display: block; width: 100%; object-fit: cover; border-right: 1px solid rgba(0, 0, 0, .45); animation: fadein .25s; }
  .film .ph { border-right: 1px solid var(--bg); background: linear-gradient(90deg, var(--surface-2), var(--surface-3), var(--surface-2)) 0 0 / 200% 100%;
    animation: shimmer 1.2s linear infinite; }
  @keyframes shimmer { to { background-position: -200% 0; } }
  @keyframes fadein { from { opacity: 0; } }
  /* Bagian di luar trim digelapkan */
  /* Footage tambahan di track video (awal / akhir clip) */
  .sc-foot { position: absolute; top: 106px; height: 36px; z-index: 2; border-radius: 8px; overflow: hidden; cursor: pointer;
    background: var(--surface-2) var(--th) 0 0 / auto 100% repeat-x; box-shadow: inset 0 0 0 1px rgba(var(--c-foot-rgb), .6); }
  .sc-foot::before { content: ""; position: absolute; inset: 0; background: linear-gradient(transparent 35%, rgba(0, 0, 0, .75)); pointer-events: none; }
  .sc-foot .nm { position: absolute; left: 7px; right: 7px; bottom: 4px; display: flex; align-items: center; gap: 5px; font-size: 10.5px; font-weight: 600;
    color: #fff; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; pointer-events: none; }
  .sc-foot .nm .i { width: 11px; height: 11px; flex: none; }
  .sc-foot .nm small { font-weight: 500; opacity: .75; }
  .sc-foot:hover { filter: brightness(1.12); }
  .sc-foot.on { box-shadow: inset 0 0 0 1px var(--c-foot); z-index: 4; }
  .sc-foot .fh { display: none; position: absolute; right: 0; top: 0; bottom: 0; width: 10px; cursor: ew-resize; background: rgba(255, 255, 255, .22); }
  .sc-foot .fh::after { content: ""; position: absolute; left: 4px; top: 10px; bottom: 10px; width: 2px; border-radius: 1px; background: #fff; }
  .sc-foot.on .fh { display: block; }
  /* Penanda saat media diseret ke timeline */
  .tl-drop { position: absolute; z-index: 6; pointer-events: none; border-radius: 6px; }
  .tl-drop.med { top: 22px; height: 24px; background: rgba(var(--c-media-rgb), .3); box-shadow: inset 0 0 0 2px var(--c-media); }
  .tl-drop.foot { top: 100px; height: 48px; width: 3px; margin-left: -1.5px; background: var(--c-foot); box-shadow: none; }
  .phone.foot .ovl { visibility: hidden; }
  .mtile .mdur { position: absolute; top: 5px; left: 5px; padding: 1px 6px; border-radius: 5px; background: rgba(0, 0, 0, .65); color: #fff;
    font-size: 10px; font-weight: 600; font-variant-numeric: tabular-nums; pointer-events: none; }
  .mtile img { pointer-events: none; }
  .sc-dim { position: absolute; top: 106px; height: 36px; background: rgba(10, 11, 13, .72); pointer-events: none; }
  .sc-dim.l { border-radius: 8px 0 0 8px; }
  .sc-dim.r { border-radius: 0 8px 8px 0; }
  /* Video baru bernuansa biru (border, glow, handle trim) saat dipilih */
  .sc-clip { position: absolute; top: 106px; height: 36px; border-radius: 8px; border: 2px solid transparent; pointer-events: none; }
  /* Handle trim selalu ada: monokrom saat video belum dipilih, biru saat dipilih */
  .sc-clip .handle { pointer-events: auto; background: linear-gradient(180deg, #24467e, #1a3463);
    box-shadow: inset 0 0 0 1px rgba(91, 155, 255, .28); transition: background .15s; }
  .sc-clip .handle::after { opacity: .7; }
  .sc-clip .handle:hover { background: linear-gradient(180deg, #2d58a0, #20417c); }
  .sc-clip.on .handle::after { opacity: 1; }
  .sc-clip.on { border-color: rgba(30, 112, 240, .55); box-shadow: 0 0 0 1px rgba(0, 0, 0, .5); }
  .sc-clip.on .handle { background: var(--grad); }
  /* Handle trim hanya muncul saat video dipilih */
  .sc-clip:not(.on) .handle { visibility: hidden; pointer-events: none; }
  /* Tombol + / penanda transisi di sambungan footage */
  .sc-tr { position: absolute; top: 113px; z-index: 5; width: 22px; height: 22px; margin-left: -11px; display: grid; place-items: center; padding: 0;
    border-radius: 50%; border: 1.5px solid var(--accent-fg); background: #0f1a2e; color: var(--accent-fg); cursor: pointer;
    box-shadow: none; transition: transform .15s, background .15s, color .15s, box-shadow .15s; }
  .sc-tr .i { width: 12px; height: 12px; pointer-events: none; }
  .sc-tr:hover { transform: scale(1.15); background: var(--grad); border-color: var(--accent-hover); color: #fff; box-shadow: none; }
  .sc-tr.has { width: 26px; margin-left: -13px; border-radius: 7px; background: var(--grad); border-color: var(--accent-hover); color: #fff; }
  .sc-tr.on { box-shadow: 0 0 0 2px #fff; }
  /* Panel Animasi: pilihan animasi masuk / keluar dengan contoh gerak kecil */
  .anims { display: grid; grid-template-columns: repeat(4, 1fr); gap: 6px; }
  /* Contoh kecil efek teks: ketik, kata per kata, sorot per kata */
  .tfxv { display: grid; place-items: center; width: 44px; height: 22px; border-radius: 5px; background: rgba(255, 255, 255, .05);
    font-size: 11px; font-weight: 800; color: var(--text); white-space: nowrap; }
  .tfxv i { font-style: normal; }
  .tfxv.tfx-type, .tfxv.tfx-word, .tfxv.tfx-karaoke { display: flex; justify-content: center; align-items: center; gap: 0; }
  .tfxv.tfx-word, .tfxv.tfx-karaoke { gap: 3px; }
  .tfx-type i { animation: tfxType 1.6s steps(1) infinite; }
  .tfx-type i:nth-child(2) { animation-name: tfxType2; } .tfx-type i:nth-child(3) { animation-name: tfxType3; }
  .tfx-word i { animation: tfxWord 1.6s ease infinite; } .tfx-word i:nth-child(2) { animation-name: tfxWord2; }
  .tfx-karaoke i { animation: tfxKar 1.6s steps(1) infinite; } .tfx-karaoke i:nth-child(2) { animation-name: tfxKar2; }
  @keyframes tfxType { 0% { opacity: 0; } 10%, 100% { opacity: 1; } }
  @keyframes tfxType2 { 0% { opacity: 0; } 30%, 100% { opacity: 1; } }
  @keyframes tfxType3 { 0% { opacity: 0; } 50%, 100% { opacity: 1; } }
  @keyframes tfxWord { 0% { opacity: 0; } 15%, 100% { opacity: 1; } }
  @keyframes tfxWord2 { 0%, 40% { opacity: 0; } 55%, 100% { opacity: 1; } }
  @keyframes tfxKar { 0% { color: #FFD400; } 50%, 100% { color: inherit; } }
  @keyframes tfxKar2 { 0% { color: inherit; } 50% { color: #FFD400; } 100% { color: inherit; } }
  .anims.three { grid-template-columns: repeat(3, 1fr); }
  /* Kartu template subtitle: contoh mini di atas latar gelap + nama */
  .tpls { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
  .tpl { display: flex; flex-direction: column; gap: 6px; padding: 6px 6px 8px; border: 1px solid var(--border); border-radius: 11px; background: var(--surface-2);
    color: var(--text-2); font: inherit; cursor: pointer; text-align: left; transition: border-color .15s, background .15s; }
  .tpl:hover { border-color: var(--border-strong); color: var(--text); }
  .tpl.on { border-color: var(--accent); background: var(--accent-soft); color: #fff; }
  .tpl-pv { height: 54px; display: grid; place-items: center; overflow: hidden; border-radius: 7px;
    background: radial-gradient(120% 90% at 30% 20%, #3a4252, #14171c 70%); }
  .tpd { white-space: nowrap; line-height: 1.1; }
  .tpd i { font-style: inherit; }
  .tpl b { padding: 0 2px; font-size: 12px; font-weight: 600; }
  .sw-row { display: flex; align-items: center; justify-content: space-between; }
  .sw-row > label:first-child { width: auto; flex: 1; white-space: nowrap; }
  .p-field > .fpk-btn { width: 100%; height: 38px; }
  .p-field > .fpk-list { margin-top: 6px; }
  .fpk-same { grid-column: 1 / -1; } .fpk-same .fpk-s { font-size: 13px; font-weight: 600; }
  .tfx-hl { width: 34px; height: 24px; padding: 0; border: 1px solid var(--border-strong); border-radius: 6px; background: none; cursor: pointer; }
  .anim { display: flex; flex-direction: column; align-items: center; gap: 6px; padding: 8px 4px 7px; border: 1px solid var(--border); border-radius: 9px;
    background: var(--surface-2); color: var(--text-2); font: inherit; font-size: 11px; cursor: pointer; transition: border-color .15s, background .15s; }
  .anim:hover { border-color: var(--border-strong); background: var(--surface-3); }
  .anim.on { border-color: var(--accent); background: var(--grad-soft); color: var(--text); font-weight: 600; }
  .av { position: relative; width: 30px; height: 22px; border-radius: 5px; background: rgba(255, 255, 255, .05); overflow: hidden; }
  .av::after { content: ""; position: absolute; left: 9px; top: 6px; width: 12px; height: 10px; border-radius: 2px; background: var(--accent-fg);
    animation: avIn 1.3s ease-out infinite paused; }
  .av.out::after { animation-name: avOut; animation-timing-function: ease-in; }
  .anim:hover .av::after, .anim.on .av::after { animation-play-state: running; }
  .av[data-none]::after { animation: none; opacity: .35; }
  .anim-set { margin: 0; padding: 0; border: 0; min-width: 0; }
  .anim-set:disabled .av::after { animation: none; }
  @keyframes avIn { 0% { transform: translate(var(--x, 0), var(--y, 0)) scale(var(--s, 1)); opacity: 0; } 55%, 100% { transform: none; opacity: 1; } }
  @keyframes avOut { 0%, 30% { transform: none; opacity: 1; } 85%, 100% { transform: translate(var(--x, 0), var(--y, 0)) scale(var(--s, 1)); opacity: 0; } }
  /* Template transisi: animasi A → B (diam di tengah, berjalan saat hover / dipilih) */
  .trs { position: relative; display: block; height: 54px; border-radius: 7px; overflow: hidden; background: #000; }
  .trs i { position: absolute; inset: 0; }
  /* A = klip gelap netral, B = biru primary */
  .trs .a { background: linear-gradient(135deg, #2a3140, #171b22); box-shadow: inset 0 0 0 1px rgba(255, 255, 255, .06); }
  .trs .b { background: var(--grad); }
  .trs::after { content: ""; position: absolute; inset: 0; opacity: 0; }
  .trs i, .trs::after { animation: 2.2s ease-in-out infinite both paused; animation-delay: -1.1s; }
  .fxt:hover .trs i, .fxt:hover .trs::after, .fxt.on .trs i, .fxt.on .trs::after { animation-play-state: running; }
  .tr-fade .b { animation-name: trFade; }
  .tr-fadeblack .b, .tr-fadewhite .b { animation-name: trHalf; }
  .tr-fadeblack::after, .tr-fadewhite::after { animation-name: trFlash; }
  .tr-fadeblack::after { background: #000; }
  .tr-fadewhite::after { background: #fff; }
  .tr-slideleft .a { animation-name: trOutL; } .tr-slideleft .b { animation-name: trInR; }
  .tr-slideright .a { animation-name: trOutR; } .tr-slideright .b { animation-name: trInL; }
  .tr-wipeleft .b { animation-name: trWipe; }
  .tr-circleopen .b { animation-name: trCircle; }
  .tr-zoomin .a { z-index: 1; animation-name: trZoom; }
  .tr-paper .b { animation-name: trCircle; }
  .tr-paper::after { animation-name: trPaper; background: radial-gradient(circle, transparent 30%, #f4f3ef 32%, #d9d8d2 46%, transparent 49%); }
  @keyframes trPaper { 0%, 25% { opacity: 0; transform: scale(.2); } 45% { opacity: 1; transform: scale(.6); } 85% { opacity: 1; transform: scale(2.4); } 100% { opacity: 0; transform: scale(2.6); } }
  /* Menu Intro video: screenshot clip + grafik hook + suara AI → footage awal */
  .it-body { flex: 1; min-height: 0; overflow-y: auto; padding: 4px 12px 14px; display: grid; gap: 12px; align-content: start; }
  .it-acts { display: grid; grid-template-columns: 1fr auto; gap: 6px; }
  .it-acts .btn { justify-content: center; }
  /* Ukuran tetap (bukan aspect-ratio) supaya tinggi baris grid tepat & tidak menumpuk pilihan di bawahnya */
  .it-prev { width: 153px; height: 272px; justify-self: center; border-radius: 10px; overflow: hidden; background: #000; position: relative; }
  .it-prev canvas { width: 100%; height: 100%; display: block; }
  .it-prev .ld { position: absolute; inset: 0; display: grid; place-items: center; color: var(--muted); font-size: 11.5px; }
  .it-lbl { font-size: 11.5px; font-weight: 600; color: var(--muted); display: flex; justify-content: space-between; align-items: center; margin-bottom: 5px; }
  .it-row { display: flex; gap: 6px; align-items: center; }
  .it-row .input { flex: 1; min-width: 0; padding: 7px 10px; font-size: 12.5px; }
  .it-text { width: 100%; min-height: 64px; resize: vertical; padding: 8px 10px; font: inherit; font-size: 12.5px; line-height: 1.4; }
  .it-seg { display: grid; grid-template-columns: repeat(3, 1fr); gap: 4px; }
  .it-seg button { font: inherit; font-size: 12px; padding: 6px 0; border-radius: 8px; border: 1px solid var(--border); background: var(--surface-2); color: var(--text-2); cursor: pointer; }
  .it-seg button.on { border-color: rgba(30, 112, 240, .75); background: rgba(30, 112, 240, .15); color: var(--text); }
  .it-note { margin: 0; font-size: 11.5px; color: var(--muted); line-height: 1.5; }
  .it-script { display: flex; gap: 8px; align-items: flex-start; justify-content: space-between; padding: 8px 10px; border-radius: 8px; border: 1px dashed var(--border-strong);
    font-size: 12.5px; line-height: 1.45; color: var(--text); }
  .it-gfx-link { justify-self: center; display: inline-flex; align-items: center; gap: 5px; margin-top: -4px; }
  .it-gfx-link .i { width: 14px; height: 14px; }
  .split-go { display: inline-flex; align-items: center; gap: 5px; margin-top: 6px; font: inherit; font-size: 12px; font-weight: 600; border: 0; background: none; color: var(--accent-fg); cursor: pointer; padding: 0; }
  .split-go .i { width: 12px; height: 12px; }
  .it-script .lnk-btn, .it-lbl .lnk-btn, .it-gfx-link { flex: none; font: inherit; font-size: 11.5px; font-weight: 600; border: 0; background: none; color: var(--accent-fg); cursor: pointer; padding: 0; }
  @keyframes trFade { 0%, 15% { opacity: 0; } 85%, 100% { opacity: 1; } }
  /* Template opening: video (biru) muncul dengan bar hitam / zoom out / fade, berjalan saat hover / dipilih */
  .ops { position: relative; display: block; height: 54px; border-radius: 7px; overflow: hidden; background: #000; }
  .ops i { position: absolute; left: 0; right: 0; }
  .ops .v { inset: 0; background: var(--grad); }
  .ops .v::after { content: ""; position: absolute; left: 22%; right: 22%; bottom: 18%; height: 6px; border-radius: 3px; background: rgba(255, 255, 255, .8); }
  .ops .t, .ops .b { height: 50%; background: #000; }
  .ops .t { top: 0; } .ops .b { bottom: 0; }
  .ops .f { inset: 0; background: #000; opacity: 0; }
  .ops i { animation: var(--od, 2s) var(--oe, ease-in-out) infinite both paused; animation-delay: calc(var(--od, 2s) * -0.3); }
  .fxt:hover .ops i, .fxt.on .ops i { animation-play-state: running; }
  .ops:not(.bars) .t, .ops:not(.bars) .b { display: none; }
  .ops.bars .t { animation-name: opT; } .ops.bars .b { animation-name: opB; }
  .ops.letter .t { animation-name: opTL; } .ops.letter .b { animation-name: opBL; }
  .ops.zoom .v { animation-name: opZ; }
  .ops.fade .f { animation-name: opF; }
  .ops.none .v { opacity: .35; }
  @keyframes opT { 0%, 12% { transform: none; } 62%, 100% { transform: translateY(-100%); } }
  @keyframes opB { 0%, 12% { transform: none; } 62%, 100% { transform: translateY(100%); } }
  @keyframes opTL { 0%, 8% { transform: none; } 30%, 55% { transform: translateY(-76%); } 75%, 100% { transform: translateY(-100%); } }
  @keyframes opBL { 0%, 8% { transform: none; } 30%, 55% { transform: translateY(76%); } 75%, 100% { transform: translateY(100%); } }
  @keyframes opZ { 0%, 12% { transform: scale(var(--oz, 1.3)); } 62%, 100% { transform: none; } }
  @keyframes opF { 0%, 12% { opacity: 1; } 62%, 100% { opacity: 0; } }
  /* Preview opening di atas video + teks (sama dengan hasil render) */
  .opfx { position: absolute; inset: 0; pointer-events: none; z-index: 40; overflow: hidden; }
  .opfx i { position: absolute; left: 0; right: 0; background: #000; }
  .opfx .t { top: 0; } .opfx .b { bottom: 0; }
  .opfx .f { inset: 0; opacity: 0; }
  /* Penanda opening di awal track video */
  .sc-op { position: absolute; top: 106px; height: 15px; z-index: 5; display: flex; align-items: center; gap: 4px; padding: 0 6px; min-width: 18px;
    border-radius: 8px 4px 4px 0; background: linear-gradient(90deg, #FFC400, rgba(255, 196, 0, .45)); color: #1a1405; font-size: 9.5px; font-weight: 800;
    letter-spacing: .02em; white-space: nowrap; overflow: hidden; cursor: pointer; box-shadow: 0 1px 4px rgba(0, 0, 0, .4); }
  .sc-op.on { box-shadow: 0 0 0 2px #fff, 0 1px 4px rgba(0, 0, 0, .4); }
  .sc-op .i { width: 10px; height: 10px; flex: none; }
  @keyframes trHalf { 0%, 49.9% { opacity: 0; } 50%, 100% { opacity: 1; } }
  @keyframes trFlash { 0%, 15% { opacity: 0; } 50% { opacity: 1; } 85%, 100% { opacity: 0; } }
  @keyframes trOutL { 0%, 15% { transform: none; } 85%, 100% { transform: translateX(-100%); } }
  @keyframes trInR { 0%, 15% { transform: translateX(100%); } 85%, 100% { transform: none; } }
  @keyframes trOutR { 0%, 15% { transform: none; } 85%, 100% { transform: translateX(100%); } }
  @keyframes trInL { 0%, 15% { transform: translateX(-100%); } 85%, 100% { transform: none; } }
  @keyframes trWipe { 0%, 15% { clip-path: inset(0 0 0 100%); } 85%, 100% { clip-path: inset(0); } }
  @keyframes trCircle { 0%, 15% { clip-path: circle(0% at 50% 50%); } 85%, 100% { clip-path: circle(75% at 50% 50%); } }
  @keyframes trZoom { 0%, 15% { transform: none; opacity: 1; } 85%, 100% { transform: scale(1.8); opacity: 0; } }
  .sc-seg { position: absolute; top: 106px; height: 36px; border-radius: 8px; border: 2px solid var(--accent); pointer-events: none; z-index: 1;
    box-shadow: none; }
  .sc-cut { position: absolute; top: 106px; height: 36px; width: 2px; margin-left: -1px; background: #fff; opacity: .85; pointer-events: none; z-index: 1;
    box-shadow: 0 0 0 1px rgba(0, 0, 0, .4); }
  .sc-gap { position: absolute; top: 106px; height: 36px; pointer-events: none; border-radius: 6px;
    background: repeating-linear-gradient(135deg, rgba(10, 11, 13, .82) 0 6px, rgba(10, 11, 13, .6) 6px 12px); }
  /* Toolbar di atas timeline */
  .tl-tools { display: flex; align-items: center; gap: 4px; margin: 0 -18px 10px; padding: 4px 12px; min-height: 34px;
    border-bottom: 1px solid var(--border); }
  .tl-tools .sep { width: 1px; height: 20px; margin: 0 6px; background: var(--border-strong); }
  .tl-tools .tl-hint { margin-left: auto; font-size: 11px; color: var(--muted); }
  .tl-tools .tl-tool { width: 26px; height: 26px; border-radius: 6px; }
  .tl-tool.on { background: var(--accent-soft); color: var(--accent-fg); border-color: transparent; }
  /* Rentang putar di penggaris waktu */
  /* Rentang putar: pita tipis & lembut; hanya handle ujungnya yang bisa ditarik (klik di pita = pindah playhead) */
  .prange { position: absolute; top: 0; height: 18px; z-index: 6; border-radius: 4px; pointer-events: none;
    box-shadow: inset 0 1px 0 rgba(148, 172, 214, .18); }
  .prange.custom { background: rgba(110, 168, 255, .07); box-shadow: inset 0 1.5px 0 rgba(110, 168, 255, .45); }
  .prange .pr-h { position: absolute; top: 2px; bottom: 2px; width: 4px; border-radius: 2px; pointer-events: auto; cursor: ew-resize;
    background: rgba(148, 172, 214, .35); transition: background .15s, width .15s, margin .15s; }
  .prange.custom .pr-h { background: rgba(110, 168, 255, .75); }
  .prange .pr-h:hover { width: 6px; background: var(--accent-fg); }
  .prange .pr-h::before { content: ""; position: absolute; inset: -2px -5px; }
  .prange .pr-h.l { left: 0; }
  .prange .pr-h.r { right: 0; }
  .pr-line { position: absolute; top: 18px; bottom: 0; width: 1px; z-index: 5; background: rgba(110, 168, 255, .22); pointer-events: none; }
  .pr-line.full { display: none; }
  /* Panel Layer: grup & item bisa diseret untuk mengubah urutan tumpuk */
  .layers { display: flex; flex-direction: column; gap: 8px; }
  .drop-before { box-shadow: inset 0 2px 0 var(--accent); }
  .drop-after { box-shadow: inset 0 -2px 0 var(--accent); }
  /* Skala timeline: 100% = pas selebar timeline, 50% = rentang 2x durasi total */
  .tl-zoom { display: flex; align-items: center; gap: 6px; }
  .tl-zoom .tl-tool { width: 22px; height: 22px; font-size: 15px; line-height: 1; font-weight: 600; color: var(--text-2); }
  .tl-zoom input[type=range] { width: 96px; }
  .tl-zoom .zv { min-width: 34px; font-size: 11px; color: var(--muted); text-align: right; font-variant-numeric: tabular-nums; }
  .tl-tools .tl-tool .i { width: 14px; height: 14px; }
  .tl-tool { width: 32px; height: 32px; display: grid; place-items: center; border: 1px solid var(--border); border-radius: 8px;
    background: var(--surface-2); color: var(--text-2); cursor: pointer; transition: background .15s, color .15s, opacity .15s; }
  .tl-tool .i { width: 16px; height: 16px; }
  .tl-tool:hover:not(:disabled) { background: var(--surface-3); color: var(--text); }
  .tl-tool:disabled { opacity: .35; cursor: default; }
  /* Track media di atas deretan frame: blok gambar dengan handle awal/akhir */
  .sc-media { position: absolute; top: 22px; height: 24px; display: flex; align-items: center; gap: 6px; padding: 0 8px; border-radius: 6px;
    background: var(--c-media); color: #fff; font-size: 10.5px; font-weight: 700; cursor: grab; z-index: 3; overflow: hidden;
    box-shadow: inset 0 0 0 1px rgba(0, 0, 0, .18); transition: filter .12s; }
  .sc-media:hover { filter: brightness(1.08); }
  .sc-media .mth { flex: none; width: 18px; height: 18px; border-radius: 3px; background: transparent center / contain no-repeat; pointer-events: none; }
  .sc-media .nm { min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; pointer-events: none; }
  /* Handle durasi hanya muncul saat blok media dipilih */
  .sc-media .mh { display: none; position: absolute; top: 0; bottom: 0; width: 10px; cursor: ew-resize; background: rgba(255, 255, 255, .22); }
  .sc-media.on { padding: 0 14px; }
  .sc-media.on .mh { display: block; }
  .sc-media .mh::after { content: ""; position: absolute; left: 4px; top: 7px; bottom: 7px; width: 2px; border-radius: 1px; background: #fff; }
  .sc-media .mh.l { left: 0; }
  .sc-media .mh.r { right: 0; }
  .tlx:has(.sc-media.on) .sc-media:not(.on), .tlx:has(.sc-text.on) .sc-text:not(.on), .tlx:has(.sc-fx.on) .sc-fx:not(.on) { opacity: .45; }
  .sc-media.on, .sc-text.on, .sc-fx.on { filter: brightness(1.1) saturate(1.1); }
  .sc-media.on { box-shadow: none; z-index: 4; }
  .sc-media.out { opacity: .4; }
  .tlx { position: absolute; inset: 0; }
  /* Track teks tambahan (di antara media & subtitle) */
  .sc-text { position: absolute; top: 50px; height: 24px; display: flex; align-items: center; gap: 6px; padding: 0 8px; border-radius: 6px;
    background: var(--c-text); color: #fff; font-size: 10.5px; font-weight: 700; cursor: grab; z-index: 3; overflow: hidden;
    box-shadow: inset 0 0 0 1px rgba(255, 255, 255, .14); transition: filter .12s; }
  .sc-text:hover { filter: brightness(1.12); }
  .sc-text .i { flex: none; width: 13px; height: 13px; pointer-events: none; }
  .sc-text .nm { min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; pointer-events: none; }
  .sc-text .mh { display: none; position: absolute; top: 0; bottom: 0; width: 10px; cursor: ew-resize; background: rgba(255, 255, 255, .22); }
  .sc-text .mh::after { content: ""; position: absolute; left: 4px; top: 7px; bottom: 7px; width: 2px; border-radius: 1px; background: #fff; }
  .sc-text .mh.l { left: 0; }
  .sc-text .mh.r { right: 0; }
  .sc-text.on { padding: 0 14px; box-shadow: none; z-index: 4; }
  .sc-text.on .mh { display: block; }
  .sc-text.out { opacity: .4; }
  /* Track efek transisi */
  .sc-fx { position: absolute; top: 78px; height: 24px; display: flex; align-items: center; gap: 6px; padding: 0 8px; border-radius: 6px;
    background: var(--c-fx); color: #fff; font-size: 10.5px; font-weight: 700; cursor: grab; z-index: 3; overflow: hidden;
    box-shadow: inset 0 0 0 1px rgba(255, 255, 255, .16); transition: filter .12s; }
  .sc-fx:hover { filter: brightness(1.1); }
  .sc-fx .i { flex: none; width: 13px; height: 13px; pointer-events: none; }
  .sc-fx .nm { min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; pointer-events: none; }
  .sc-fx .mh { display: none; position: absolute; top: 0; bottom: 0; width: 10px; cursor: ew-resize; background: rgba(255, 255, 255, .22); }
  .sc-fx .mh::after { content: ""; position: absolute; left: 4px; top: 7px; bottom: 7px; width: 2px; border-radius: 1px; background: #fff; }
  .sc-fx .mh.l { left: 0; }
  .sc-fx .mh.r { right: 0; }
  .sc-fx.on { padding: 0 14px; box-shadow: none; z-index: 4; }
  .sc-fx.on .mh { display: block; }
  .sc-fx.out { opacity: .4; }
  /* Track musik latar: blok bergelombang, bisa digeser & ditarik ujungnya */
  .sc-music { position: absolute; top: 176px; height: 24px; display: flex; align-items: center; gap: 6px; padding: 0 8px; border-radius: 6px;
    background: var(--c-music) repeating-linear-gradient(90deg, rgba(255, 255, 255, .13) 0 2px, transparent 2px 5px) 0 50% / 100% 46% no-repeat;
    color: #fff; font-size: 10.5px; font-weight: 700; cursor: grab; z-index: 3; overflow: hidden;
    box-shadow: inset 0 0 0 1px rgba(255, 255, 255, .16); transition: filter .12s; }
  .sc-music:hover { filter: brightness(1.1); }
  /* Track SFX: blok pendek selebar durasi suaranya, bisa digeser (juga ke bagian footage / intro) */
  .sc-sfx { position: absolute; top: 206px; height: 22px; min-width: 14px; z-index: 3; display: flex; align-items: center; gap: 4px; padding: 0 5px; border-radius: 6px;
    background: #C2410C; color: #fff; font-size: 11px; font-weight: 600; overflow: hidden; cursor: grab; box-shadow: inset 0 0 0 1px rgba(255, 255, 255, .18); }
  .sc-sfx .i { flex: none; width: 11px; height: 11px; pointer-events: none; }
  .sc-sfx span { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; pointer-events: none; }
  .sc-sfx.on { box-shadow: 0 0 0 2px #fff; z-index: 4; }
  .sc-inner.l-sfx .sc-sfx { pointer-events: none; opacity: .6; }
  .sc-inner.m-sfx .sc-sfx { opacity: .35; }
  .sfx-list { flex: 1; min-height: 0; overflow-y: auto; padding: 2px 12px 12px; display: grid; gap: 4px; align-content: start; }
  .sfx-row { display: grid; grid-template-columns: 26px 1fr auto; gap: 6px; align-items: center; padding: 3px 6px; border-radius: 9px; border: 1px solid var(--border); background: var(--surface-2); }
  .sfx-row.play { border-color: rgba(194, 65, 12, .7); }
  .sfx-row b { display: block; font-size: 12px; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .sfx-row small { display: block; font-size: 10.5px; line-height: 1.3; color: var(--muted); }
  .sfx-row button { width: 26px; height: 26px; padding: 0; border-radius: 8px; border: 0; background: none; color: var(--text-2); cursor: pointer; display: grid; place-items: center; }
  .sfx-row button:hover { background: var(--surface-3); color: var(--text); }
  .sfx-row .sfx-act { display: flex; gap: 0; }
  .sfx-row .sfx-act button { width: 24px; }
  .sfx-row .i { width: 15px; height: 15px; pointer-events: none; }
  /* Buat SFX dengan AI (ElevenLabs): prompt + pilihan cepat + durasi */
  /* Panel AI ringkas (buka/tutup lewat tombol ✨ di judul) supaya library tetap lega: 1 baris pilihan cepat + prompt + durasi */
  .sfx-head-btns { display: flex; gap: 6px; }
  .media-plus.on { border-color: rgba(194, 65, 12, .8); background: rgba(194, 65, 12, .18); color: #fdba74; }
  .sfx-ai { flex: none; margin: 0 12px 8px; padding: 8px; border-radius: 10px; border: 1px solid var(--border); background: var(--surface-2); display: grid; gap: 6px; }
  .sfx-ai[hidden] { display: none; }
  .sfx-ai .input { min-width: 0; padding: 6px 9px; font-size: 12px; }
  .sfx-ai-row { display: flex; gap: 6px; align-items: center; }
  .sfx-ai { min-width: 0; overflow: hidden; }
  .sfx-ai-row { min-width: 0; }
  .sfx-ai-row .input { flex: 1 1 0; width: auto; min-width: 0; }
  .sfx-ai-row select.input { flex: 0 0 auto; width: auto; padding-right: 22px; }
  .sfx-ai-row .sfx-chips { flex: 1 1 0; min-width: 0; }
  #lp-sfx { overflow-x: hidden; }
  .sfx-ai-st { font-size: 10.5px; color: #fdba74; line-height: 1.35; }
  .sfx-ai-st[hidden] { display: none; }
  .sfx-ai-go { flex: none; width: 32px; height: 30px; padding: 0; justify-content: center; }
  .sfx-ai-go .i { width: 15px; height: 15px; }
  .sfx-ai-go .spin { width: 12px; height: 12px; }
  .sfx-chips { display: flex; flex-wrap: nowrap; gap: 4px; overflow-x: auto; scrollbar-width: none; }
  .sfx-chips::-webkit-scrollbar { display: none; }
  .sfx-chips button { flex: none; }
  .sfx-row.new { animation: sfxNew 2.4s ease-out; }
  @keyframes sfxNew { 0%, 40% { border-color: rgba(194, 65, 12, .9); background: rgba(194, 65, 12, .18); } }
  .sfx-list .fx-sec { margin: 6px 2px 0; }
  .sfx-chips button { font: inherit; font-size: 11px; padding: 3px 8px; border-radius: 999px; border: 1px solid var(--border); background: var(--surface); color: var(--text-2); cursor: pointer; }
  .sfx-chips button:hover, .sfx-chips button.on { border-color: rgba(194, 65, 12, .8); color: var(--text); }
  .sfx-ai .it-row .btn { flex: none; }
  .sfx-row .ai { display: inline-block; margin-left: 4px; padding: 0 5px; border-radius: 4px; background: rgba(194, 65, 12, .25); color: #fdba74; font-size: 9.5px; font-weight: 800; vertical-align: 1px; }
  .sc-music .i { flex: none; width: 13px; height: 13px; pointer-events: none; }
  .sc-music .nm { min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; pointer-events: none; text-shadow: 0 1px 2px rgba(0, 0, 0, .5); }
  .sc-music .mh { display: none; position: absolute; top: 0; bottom: 0; width: 10px; cursor: ew-resize; background: rgba(255, 255, 255, .22); }
  .sc-music .mh::after { content: ""; position: absolute; left: 4px; top: 7px; bottom: 7px; width: 2px; border-radius: 1px; background: #fff; }
  .sc-music .mh.l { left: 0; }
  .sc-music .mh.r { right: 0; }
  .sc-music.on { padding: 0 14px; z-index: 4; filter: brightness(1.1) saturate(1.1); }
  .sc-music.on .mh { display: block; }
  .sc-music.out { opacity: .4; }
  .tlx:has(.sc-music.on) .sc-music:not(.on) { opacity: .45; }
  .sc-inner.m-music .sc-music { opacity: .35; }
  .sc-inner.l-music .sc-music { pointer-events: none; background-image: repeating-linear-gradient(135deg, rgba(0, 0, 0, .28) 0 4px, transparent 4px 8px); }
  /* Panel kiri: library musik */
  .mu-search { flex: none; padding: 6px 12px 4px; }
  .mu-search .input { padding: 7px 11px; font-size: 12.5px; }
  .mu-list { flex: 1; min-height: 0; overflow-y: auto; display: flex; flex-direction: column; gap: 2px; padding: 6px 8px 8px; }
  .mu-list.drop { outline: 2px dashed var(--accent); outline-offset: -6px; border-radius: 12px; }
  .mu-row { display: flex; align-items: center; gap: 8px; padding: 7px 6px 7px 7px; border-radius: 10px; }
  .mu-row:hover, .mu-row.play { background: var(--surface-2); }
  .mu-row .mu-pl { flex: none; width: 30px; height: 30px; display: grid; place-items: center; border: 0; border-radius: 50%; cursor: pointer;
    background: rgba(var(--c-music-rgb), .2); color: #c9c3ff; transition: background .15s; }
  .mu-row .mu-pl:hover, .mu-row.play .mu-pl { background: var(--c-music); color: #fff; }
  .mu-row .mu-pl .i { width: 14px; height: 14px; }
  .mu-row .mu-tx { flex: 1; min-width: 0; }
  .mu-row .mu-nm { font-size: 12.5px; font-weight: 600; line-height: 1.3; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; word-break: break-word; }
  .mu-row .mu-meta { margin-top: 2px; font-size: 11px; color: var(--muted); font-variant-numeric: tabular-nums; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .mu-row .mu-meta b { color: #c9c3ff; font-weight: 600; }
  .mu-row .mu-act { flex: none; display: flex; gap: 2px; }
  .mu-row .mu-act button { width: 26px; height: 26px; display: grid; place-items: center; border: 0; border-radius: 7px; background: none;
    color: var(--muted); cursor: pointer; }
  .mu-row .mu-act button .i { width: 14px; height: 14px; }
  .mu-row .mu-act button:hover { background: var(--surface-3); color: var(--text); }
  .mu-row .mu-act .mu-add { color: var(--text-2); background: var(--surface-3); }
  .mu-row .mu-act .mu-add:hover { background: var(--c-music); color: #fff; }
  .mu-row .mu-act .opt { display: none; }
  .mu-row:hover .mu-act .opt { display: grid; }
  .mu-row .mu-act .mu-del:hover { color: var(--err); }
  .mu-badge { flex: none; width: 44px; height: 44px; display: grid; place-items: center; border-radius: 12px; background: var(--c-music); color: #fff; }
  .mu-badge .i { width: 20px; height: 20px; }
  .mu-note { flex: none; padding: 8px 16px 12px; font-size: 11px; color: var(--muted); line-height: 1.45; border-top: 1px solid var(--border); }
  .mu-empty { margin: 6px 4px; padding: 22px 14px; border: 1px dashed var(--border-strong); border-radius: 12px; text-align: center; cursor: pointer;
    font-size: 12.5px; color: var(--muted); line-height: 1.5; }
  .mu-empty .i { display: block; width: 26px; height: 26px; margin: 0 auto 8px; color: #c9c3ff; }
  .mu-empty:hover { border-color: var(--c-music); }
  /* Panel kiri: daftar efek */
  .fx-tabs { flex: none; display: flex; gap: 4px; margin: 4px 12px 2px; padding: 4px; border: 1px solid var(--border); border-radius: 11px; background: var(--surface-2); }
  .fx-tabs button { flex: 1; height: 30px; border: 0; border-radius: 8px; background: none; color: var(--muted); font: inherit; font-size: 13px; font-weight: 600; cursor: pointer; }
  .fx-tabs button:hover { color: var(--text); }
  .fx-tabs button.on { background: var(--accent-soft); color: var(--accent-fg); }
  .fx-sec { grid-column: 1 / -1; margin: 6px 2px -2px; font-size: 11px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: var(--muted); }
  /* Contoh filter: pemandangan sederhana yang diberi grading */
  .sw3.flt { position: relative; background: linear-gradient(180deg, #7fb2e5 0%, #c9dcef 46%, #e7b07a 47%, #b5763f 70%, #4c3a2c 100%); }
  .sw3.flt i { position: absolute; inset: 0; mix-blend-mode: soft-light; }
  .fx-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; padding: 6px 12px 10px; align-content: start; overflow-y: auto; }
  .fxt { display: flex; flex-direction: column; gap: 6px; padding: 6px; border: 1px solid var(--border); border-radius: 10px; background: var(--surface-2);
    color: var(--text); font: inherit; font-size: 11.5px; font-weight: 600; text-align: left; cursor: pointer; transition: border-color .15s, background .15s; }
  .fxt:hover { border-color: var(--border-strong); background: var(--surface-3); }
  .fxt.on { border-color: rgba(255, 255, 255, .45); background: var(--surface-3); }
  .fxt .sw3 { height: 54px; border-radius: 7px; background-color: #15171b; overflow: hidden; }
  .fxt small { font-size: 10.5px; font-weight: 500; color: var(--muted); }
  /* Teks tambahan di preview */
  .tlayer { position: absolute; inset: 0; }
  .tbox { position: absolute; left: calc(70px * var(--k)); right: calc(70px * var(--k)); width: fit-content; pointer-events: auto; cursor: grab;
    word-wrap: break-word; white-space: pre-wrap; }
  .tbox span { -webkit-box-decoration-break: clone; box-decoration-break: clone; paint-order: stroke fill; }
  /* Panel kiri: tab Teks */
  .txt-presets { display: grid; grid-template-columns: 1fr; gap: 8px; padding: 10px 12px 4px; }
  .tpre { display: flex; align-items: center; justify-content: center; height: 54px; border: 1px solid var(--border); border-radius: 10px;
    background: linear-gradient(135deg, #1d2a46, #0f1829); color: #fff; cursor: pointer; transition: border-color .15s, box-shadow .15s, filter .15s; font: inherit; }
  .tpre:hover { filter: brightness(1.15); }
  .tpre.title { font-family: "Clip Montserrat", sans-serif; font-weight: 700; font-size: 20px; -webkit-text-stroke: 3px #000; paint-order: stroke fill; }
  .tpre.body { font-size: 14.5px; -webkit-text-stroke: 2px #000; paint-order: stroke fill; }
  .tpre.box span { background: #fff; color: #111; font-weight: 700; font-size: 13.5px; padding: 3px 9px; }
  .txt-sub { padding: 10px 16px 4px; font-size: 11.5px; font-weight: 600; letter-spacing: .04em; text-transform: uppercase; color: var(--muted); }
  .txt-fill { flex: 1; }
  .txt-list { flex: 1; min-height: 0; overflow-y: auto; display: flex; flex-direction: column; gap: 2px; padding: 4px 8px 8px; }
  .txt-item { display: flex; align-items: center; gap: 10px; padding: 8px 10px; border-radius: 10px; cursor: pointer; font-size: 13px; }
  .txt-item:hover { background: var(--surface-2); }
  .txt-item.on { background: var(--surface-3); }
  .txt-item .sw2 { flex: none; width: 4px; align-self: stretch; border-radius: 2px; background: var(--c-text); }
  .txt-item .tt { flex: 1; min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .txt-item small { flex: none; color: var(--muted); font-size: 11px; font-variant-numeric: tabular-nums; }
  .txt-empty { padding: 14px 16px; font-size: 12.5px; color: var(--muted); line-height: 1.5; }
  .sc-mempty { position: absolute; left: 0; width: var(--tlw, 100%); top: var(--top, 22px); height: 24px; display: flex; align-items: center; gap: 6px; padding: 0 10px;
    border: 1px dashed rgba(255, 255, 255, .07); border-radius: 6px; color: rgba(118, 125, 135, .7); font-size: 11px; pointer-events: none; }
  .sc-mempty .i { width: 13px; height: 13px; opacity: .8; }
  .sc-mempty .st { position: sticky; left: 10px; display: inline-flex; align-items: center; gap: 6px; white-space: nowrap; }
  /* Gambar media di preview */
  .mlayer { position: absolute; inset: 0; }
  .mimg { position: absolute; transform: translate(-50%, -50%); max-width: none; pointer-events: auto; cursor: grab; -webkit-user-drag: none; user-select: none; }
  .selbox.media:not(.gfx) [data-st="edit"], .selbox.media:not(.gfx) .sep { display: none; }
  /* Edit teks grafik langsung di layar */
  .gfx-ed { position: absolute; z-index: 30; pointer-events: auto; box-sizing: border-box; resize: none; overflow: hidden; outline: none;
    padding: 10px 12px; border: 1.5px solid var(--accent); border-radius: 8px; background: rgba(10, 14, 20, .88); color: #fff;
    font: 600 15px/1.4 Inter, system-ui, sans-serif; box-shadow: none; }
  .gfx-ed-cnt { position: absolute; z-index: 30; pointer-events: none; padding: 2px 7px; border-radius: 6px; background: rgba(10, 14, 20, .85);
    color: #cfd6e2; font: 600 11px Inter, system-ui, sans-serif; font-variant-numeric: tabular-nums; }
  /* Track subtitle: satu label per baris, klik untuk memilih baris itu */
  .sc-lbl { position: absolute; top: 148px; height: 22px; padding: 0 7px; border-radius: 6px; background: #1a1d23; color: #c9ced6;
    font-size: 10.5px; font-weight: 600; line-height: 22px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    cursor: pointer; z-index: 3; box-shadow: inset 0 0 0 1px rgba(255, 255, 255, .14); transition: filter .12s, box-shadow .12s; }
  .sc-lbl.narrow { padding: 0; color: transparent; }
  .sc-lbl:hover { filter: brightness(1.12); }
  .sc-lbl.out { opacity: .38; }
  .sc-lbl.on { background: #2a303b; color: #fff; box-shadow: inset 0 0 0 1px rgba(255, 255, 255, .14); z-index: 4; }
  .sc-split { position: absolute; top: 110px; z-index: 1; height: 5px; border-radius: 2px; background: var(--c-split); pointer-events: none; }
  .handle { position: absolute; top: -2px; bottom: -2px; width: 14px; background: var(--accent); cursor: ew-resize; z-index: 2; }
  .handle::after { content: ""; position: absolute; left: 5px; top: 30%; bottom: 30%; width: 4px; border-left: 1.5px solid #fff; border-right: 1.5px solid #fff; }
  .handle.l { left: -2px; border-radius: 6px 0 0 6px; }
  .handle.r { right: -2px; border-radius: 0 6px 6px 0; }
  .playhead { position: absolute; top: 4px; bottom: -4px; width: 2px; margin-left: -1px; background: #fff; box-shadow: 0 0 0 1px rgba(0, 0, 0, .4);
    pointer-events: none; z-index: 8; }
  .playhead .ph-knob { position: absolute; top: -14px; left: 50%; width: 13px; height: 15px; margin-left: -6.5px; pointer-events: auto; cursor: grab;
    background: var(--accent); border-radius: 4px 4px 7px 7px; box-shadow: none; transition: transform .12s; }
  .playhead .ph-knob::before { content: ""; position: absolute; inset: -4px -6px; }
  .playhead .ph-knob:hover { transform: scale(1.12); }
  body.dragging .ph-knob, body.moving .ph-knob { cursor: grabbing; }
  .sub.click, .hookbox.click { pointer-events: auto; cursor: pointer; }
  .hookbox { width: fit-content; margin: 0 auto; }
  .sub .sbox { display: inline-block; max-width: 100%; }
  /* Seleksi teks di preview ala editor: bingkai + titik sudut + toolbar mengambang */
  .selbox { position: absolute; z-index: 20; pointer-events: none; border: 1.5px solid var(--accent); border-radius: 4px;
    box-shadow: 0 0 0 1px rgba(0, 0, 0, .35); }
  .selbox > i { position: absolute; width: 10px; height: 10px; background: #fff; border: 1.5px solid var(--accent); border-radius: 3px;
    pointer-events: auto; cursor: nwse-resize; transition: transform .12s; }
  .selbox > i::before { content: ""; position: absolute; inset: -7px; }
  .selbox > i:hover { transform: scale(1.25); }
  .selbox > i:nth-child(2), .selbox > i:nth-child(3) { cursor: nesw-resize; }
  .selbox > i:nth-child(1) { left: -5px; top: -5px; }
  .selbox > i:nth-child(2) { right: -5px; top: -5px; }
  .selbox > i:nth-child(3) { left: -5px; bottom: -5px; }
  .selbox > i:nth-child(4) { right: -5px; bottom: -5px; }
  .selbox.editing { border-style: dashed; }
  .selbox.editing > i, .selbox.editing .seltool { display: none; }
  .seltool { position: absolute; left: 50%; bottom: calc(100% + 10px); transform: translateX(-50%); display: flex; gap: 2px; padding: 3px;
    pointer-events: auto; background: rgba(10, 17, 32, .92); border: 1px solid rgba(148, 172, 214, .2); border-radius: 10px;
    box-shadow: none; backdrop-filter: blur(6px); font-family: Inter, system-ui, sans-serif; white-space: nowrap; }
  .selbox.below .seltool { bottom: auto; top: calc(100% + 10px); }
  .seltool button { display: inline-flex; align-items: center; gap: 5px; border: 0; background: none; color: var(--text); font: inherit;
    font-size: 12px; font-weight: 500; padding: 5px 9px; border-radius: 7px; cursor: pointer; }
  .seltool button { color: #e8eef8 !important; }
  .seltool button:hover { background: rgba(148, 172, 214, .14); }
  .seltool button.del:hover { color: #ff8a80; background: rgba(255, 138, 128, .12); }
  .seltool .i { width: 13px; height: 13px; }
  .seltool .sep { width: 1px; margin: 3px 1px; background: rgba(148, 172, 214, .22); }
  /* Garis bantu saat menggeser teks (ala story Instagram): area aman + garis tengah, muncul saat teks "mentok" */
  .guides { position: absolute; inset: 0; z-index: 19; pointer-events: none; opacity: 0; transition: opacity .12s; }
  .guides.on { opacity: 1; }
  .guides .safe { position: absolute; left: calc(64px * var(--k)); right: calc(64px * var(--k)); top: calc(192px * var(--k)); bottom: calc(192px * var(--k));
    border: 1px dashed rgba(255, 255, 255, .22); border-radius: 6px; }
  .guides .gl { position: absolute; background: #ff3d9a; box-shadow: none; opacity: 0; transition: opacity .08s; }
  .guides .gl.show { opacity: 1; }
  .guides .gl.v { top: 0; bottom: 0; width: 1px; margin-left: -.5px; }
  .guides .gl.h { left: 0; right: 0; height: 1px; margin-top: -.5px; }
  .guides .gl.l { left: calc(64px * var(--k)); }
  .guides .gl.r { left: calc(1016px * var(--k)); }
  .guides .gl.cx { left: 50%; }
  .guides .gl.t { top: calc(192px * var(--k)); }
  .guides .gl.b { top: calc(1728px * var(--k)); }
  .guides .gl.cy { top: 50%; }
  .sub.click, .hookbox.click { cursor: grab; }
  body.moving, body.moving * { cursor: grabbing !important; }
  /* Edit langsung di preview */
  .ovl [contenteditable] { pointer-events: auto; cursor: text; outline: none; caret-color: var(--accent); -webkit-user-select: text; user-select: text; }

  /* Daftar baris subtitle di panel kanan */
  .lines { display: flex; flex-direction: column; gap: 4px; margin-top: 10px; }
  .ln { display: grid; grid-template-columns: 52px minmax(0, 1fr) auto; gap: 6px; align-items: center; padding: 3px 4px; border-radius: 8px; }
  .ln.on { background: var(--accent-soft); }
  .ln.dim { opacity: .45; }
  .ln .ln-t { font-size: 11.5px; color: var(--muted); font-variant-numeric: tabular-nums; cursor: pointer; text-align: right; }
  .ln input { width: 100%; min-width: 0; font: inherit; font-size: 13px; padding: 6px 8px; border-radius: 7px; border: 1px solid var(--border);
    background: var(--surface-2); color: var(--text); outline: none; }
  .ln input:focus { border-color: var(--accent); }
  .ln button { font: inherit; font-size: 14px; border: 0; background: none; color: var(--muted); cursor: pointer; padding: 4px 6px; border-radius: 6px; }
  .ln button:hover { color: var(--text); background: var(--surface-2); }

  /* Panel kiri: daftar clip proyek, pindah clip tanpa keluar editor */
  .clips { min-height: 0; display: flex; flex-direction: column; padding: 0; overflow: hidden; }
  /* Tab panel kiri: Clip | Media */
  /* Sidebar ikon vertikal di kiri panel: Clip | Media | Teks (menu baru tinggal ditambah ke bawah) */
  .clips { flex-direction: row !important; }
  .lp-tabs { flex: none; width: 56px; display: flex; flex-direction: column; align-items: center; gap: 6px; padding: 10px 0;
    border-right: 1px solid var(--border); overflow-y: auto; scrollbar-width: none; }
  .lp-tabs::-webkit-scrollbar { display: none; }
  .lp-tab { position: relative; flex: none; width: 40px; height: 40px; display: grid; place-items: center; border: 0; border-radius: 10px;
    background: none; color: var(--muted); cursor: pointer; transition: background .15s, color .15s; }
  .lp-tab .i { width: 19px; height: 19px; }
  .lp-tab .cnt { position: absolute; top: 2px; right: 1px; min-width: 15px; height: 15px; padding: 0 4px; border-radius: 8px; background: var(--surface-3);
    color: var(--text-2); font-size: 9.5px; font-weight: 700; line-height: 15px; text-align: center; font-variant-numeric: tabular-nums; }
  .lp-tab .cnt:empty { display: none; }
  .lp-tab:hover { color: var(--text); background: var(--surface-2); }
  .lp-tab.on, .lp-tab.on:hover { color: #fff; background: var(--surface-3); }
  .lp-tab.on .cnt { background: var(--border-strong); }
  .lp-body { flex: 1; min-width: 0; min-height: 0; display: flex; flex-direction: column; }
  .lp-title { flex: none; padding: 14px 16px 6px; font-size: 14px; font-weight: 700; }
  .lp-title-row { display: flex; align-items: center; justify-content: space-between; padding-right: 12px; padding-top: 10px; }
  .media-plus:disabled { opacity: .35; cursor: default; }
  .media-plus:disabled:hover { background: var(--surface-2); color: var(--text-2); border-color: var(--border-strong); }
  .tpre.on { border-color: var(--accent); box-shadow: 0 0 0 1px var(--accent); }
  .lp-pane { flex: 1; min-height: 0; display: flex; flex-direction: column; }
  .lp-pane[hidden] { display: none; }
  /* Tombol unggah: ikon plus monokrom */
  .media-plus { width: 30px; height: 30px; display: grid; place-items: center; border: 1px solid var(--border-strong); border-radius: 8px;
    background: var(--surface-2); color: var(--text-2); cursor: pointer; transition: background .15s, color .15s, border-color .15s; }
  .media-plus .i { width: 16px; height: 16px; }
  .media-plus:hover { background: var(--surface-3); color: var(--text); border-color: rgba(255, 255, 255, .24); }
  .media-grid { flex: 1; min-height: 0; overflow-y: auto; display: grid; grid-template-columns: 1fr 1fr; gap: 8px; padding: 10px 12px; align-content: start; }
  .media-grid.drop { outline: 2px dashed var(--accent); outline-offset: -6px; border-radius: 12px; }
  .mtile { position: relative; border-radius: 10px; overflow: hidden; cursor: pointer; background: var(--surface-2);
    aspect-ratio: 1; border: 1px solid var(--border); transition: border-color .15s; }
  .mtile img { width: 100%; height: 100%; object-fit: contain; }
  .mtile .mname { position: absolute; left: 0; right: 0; bottom: 0; padding: 14px 7px 5px; font-size: 10.5px; color: #fff; white-space: nowrap; overflow: hidden;
    text-overflow: ellipsis; background: linear-gradient(transparent, rgba(0, 0, 0, .8)); }
  .mtile .madd { position: absolute; inset: 0; display: grid; place-items: center; background: rgba(0, 40, 110, .55); color: #fff; font-size: 12px; font-weight: 600;
    opacity: 0; transition: opacity .15s; }
  .mtile:hover { border-color: var(--accent); }
  .mtile:hover .madd { opacity: 1; }
  .mtile .mdel { position: absolute; top: 5px; right: 5px; z-index: 1; width: 22px; height: 22px; display: grid; place-items: center; border: 0; border-radius: 6px;
    background: rgba(0, 0, 0, .65); color: #fff; cursor: pointer; opacity: 0; transition: opacity .15s; }
  .mtile .mdel .i { width: 12px; height: 12px; }
  .mtile:hover .mdel { opacity: 1; }
  .mtile .mdel:hover { background: #d33; }
  .media-empty { grid-column: 1 / -1; display: flex; flex-direction: column; align-items: center; gap: 8px; padding: 28px 12px; text-align: center;
    border: 1.5px dashed var(--border-strong); border-radius: 12px; color: var(--muted); font-size: 12.5px; line-height: 1.5; cursor: pointer; }
  .media-empty .i { width: 26px; height: 26px; }
  .media-empty:hover { border-color: var(--accent); color: var(--text-2); }
  /* Daftar clip di bawah: kartu preview vertikal 9:16 (gaya reels), judul & durasi melayang di atas preview */
  .reels { grid-area: rv; margin-top: -1px; padding: 0; display: flex; flex-direction: column; min-width: 0; }
  .reels-head { display: flex; align-items: center; gap: 8px; padding: 10px 16px 0; font-size: 13px; }
  .reels-head b { font-size: 14px; font-weight: 700; }
  .reels-head .cnt { color: var(--muted); font-variant-numeric: tabular-nums; }
  .reels-legend { display: inline-flex; align-items: center; gap: 5px; margin-left: 10px; font-size: 11.5px; color: var(--muted); }
  .reels-legend .rl-chk + .rl-chk, .reels-legend .rl-chk ~ .rl-chk { margin-left: 8px; }
  .rl-chk { display: inline-grid; place-items: center; width: 16px; height: 16px; border-radius: 50%; border: 1.5px solid rgba(255, 255, 255, .45); color: transparent; }
  .rl-chk .i { width: 10px; height: 10px; }
  .rl-chk.on { background: var(--grad); border-color: transparent; color: #fff; }
  .reels-row { display: flex; gap: 10px; overflow-x: auto; padding: 10px 16px 14px; scrollbar-width: thin; scroll-snap-type: x proximity; }
  .rc { position: relative; flex: none; width: 116px; aspect-ratio: 9 / 16; padding: 0; border: 0; border-radius: 12px; overflow: hidden; background: #000;
    cursor: pointer; font: inherit; color: #fff; text-align: left; scroll-snap-align: start; outline: 2px solid transparent; outline-offset: 2px;
    transition: outline-color .15s, transform .15s, opacity .15s; }
  .rc:hover { transform: translateY(-2px); }
  .rc:focus-visible { outline-color: var(--accent-fg); }
  .rc img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; }
  /* Lapisan gelap atas & bawah supaya label tetap terbaca di atas gambar apa pun */
  .rc::before { content: ""; position: absolute; inset: 0; z-index: 1;
    background: linear-gradient(rgba(0, 0, 0, .55), transparent 28%, transparent 45%, rgba(0, 0, 0, .88)); }
  .rc > span { position: absolute; z-index: 2; }
  /* Skor viral: makin tinggi makin pekat biru brand (gradasi penuh untuk skor ≥ 80) */
  .rc .vs { top: 7px; left: 7px; display: inline-flex; align-items: center; gap: 3px; padding: 2px 7px 2px 5px; border-radius: 999px;
    font-size: 10.5px; font-weight: 800; font-variant-numeric: tabular-nums; color: var(--text-2); background: rgba(11, 14, 20, .72);
    box-shadow: inset 0 0 0 1px rgba(160, 178, 210, .18); backdrop-filter: blur(4px); }
  .rc .vs .i { width: 10px; height: 10px; }
  .rc .vs.mid { color: #fff; background: rgba(0, 88, 211, .72); box-shadow: none; }
  .rc .vs.hi { color: #fff; background: var(--grad); box-shadow: none; }
  .rc .ck { top: 7px; right: 7px; width: 20px; height: 20px; display: grid; place-items: center; border-radius: 50%;
    border: 1.5px solid rgba(255, 255, 255, .7); background: rgba(0, 0, 0, .35); color: transparent; }
  .rc .ck .i { width: 12px; height: 12px; }
  .rc .ck.on { border-color: transparent; background: var(--grad); color: #fff; }
  .rc .cap { left: 8px; right: 8px; bottom: 8px; display: flex; flex-direction: column; gap: 4px; }
  .rc .cap b { font-size: 11.5px; font-weight: 700; line-height: 1.25; text-shadow: 0 1px 3px rgba(0, 0, 0, .6);
    display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden; }
  .rc .cap small { display: flex; align-items: center; gap: 5px; font-size: 10.5px; font-weight: 600; color: rgba(255, 255, 255, .85); font-variant-numeric: tabular-nums; }
  .rc .cap .du { padding: 1px 5px; border-radius: 4px; background: rgba(0, 0, 0, .55); }
  .rc .st { padding: 1px 5px; border-radius: 4px; font-size: 10px; font-weight: 700; background: rgba(0, 0, 0, .55); }
  .rc .st.run { color: var(--warn); }
  .rc .st.err { color: #ff8a80; }
  .rc .st.up { display: inline-flex; align-items: center; gap: 2px; color: var(--accent-fg); }
  .rc .st.up .i { width: 9px; height: 9px; }
  /* Clip yang sedang diedit: bingkai biru */
  .rc.on { outline-color: var(--accent-fg); cursor: default; }
  .rc.on:hover { transform: none; }
  .rc.loading img { opacity: .45; }
  .rc.loading::after { content: ""; position: absolute; z-index: 3; left: 50%; top: 50%; width: 24px; height: 24px; margin: -12px; border-radius: 50%;
    border: 2.5px solid rgba(255, 255, 255, .25); border-top-color: #fff; animation: spin .8s linear infinite; }
  /* Pemisah vertikal: clip yang sudah diupload di kanan, sedikit diredupkan */
  .rc-sep { flex: none; align-self: stretch; width: 1px; margin: 0 4px; background: var(--border-strong); }
  .rc-sep ~ .rc:not(.on) { opacity: .6; }
  .rc-sep ~ .rc:hover { opacity: 1; }
  .prep { max-width: 460px; width: 100%; margin: auto; padding: 28px 28px 24px; }
  .prep h2 { font-size: 18px; margin: 0 0 4px; }
  .prep .prep-sub { color: var(--muted); font-size: 13.5px; margin: 0 0 20px; }
  .steps { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 4px; }
  .steps li { display: grid; grid-template-columns: 26px 1fr auto; gap: 10px; align-items: center; padding: 9px 10px; border-radius: 10px;
    font-size: 14px; color: var(--muted); }
  .steps li.active { background: var(--surface-2); color: var(--text); font-weight: 600; }
  .steps li.done { color: var(--text-2); }
  .steps .dot { width: 22px; height: 22px; border-radius: 50%; border: 2px solid var(--surface-3); display: grid; place-items: center;
    font-size: 12px; font-weight: 800; color: #fff; }
  .steps li.done .dot { background: var(--ok); border-color: var(--ok); }
  .steps li.active .dot { border-color: var(--accent); border-right-color: transparent; animation: spin .8s linear infinite; }
  .steps small { font-size: 12.5px; color: var(--muted); font-weight: 500; font-variant-numeric: tabular-nums; }
  .prep .progress { margin: 18px 0 10px; height: 6px; border-radius: 3px; }
  .prep .note { font-size: 12.5px; color: var(--muted); }
  /* Clip belum siap: kerangka editor di belakang + modal tunggu dengan latar gelap */
  .sk { pointer-events: none; user-select: none; }
  .sk .phone { display: grid; place-items: center; }
  .sk-bar { height: 12px; border-radius: 6px; background: linear-gradient(90deg, var(--surface-2) 25%, var(--surface-3) 50%, var(--surface-2) 75%);
    background-size: 200% 100%; animation: shimmer 1.6s linear infinite; }
  .sk .props { padding: 18px; gap: 14px; }
  .sk .props .sk-bar:nth-child(odd) { width: 60%; }
  .sk-trk { display: flex; flex-direction: column; gap: 10px; padding-top: 14px; }
  .sk-trk .sk-bar { height: 22px; }
  /* Modal terapkan style ke clip lain */
  .sty { position: fixed; inset: 0; z-index: 60; display: grid; place-items: center; padding: 16px; background: rgba(5, 6, 8, .72); backdrop-filter: blur(6px); animation: fadein .2s; }
  .sty[hidden] { display: none; }
  /* Pilihan kata per tampilan subtitle */
  .chunk-seg { flex: 1; display: flex; gap: 3px; padding: 3px; border: 1px solid var(--border); border-radius: 10px; background: var(--surface-2); }
  .chunk-seg button { flex: 1; min-width: 0; height: 28px; padding: 0 6px; border: 0; border-radius: 7px; background: none; color: var(--muted);
    font: inherit; font-size: 12px; font-weight: 600; cursor: pointer; font-variant-numeric: tabular-nums; }
  .chunk-seg button:first-child { flex: 2.2; }
  .chunk-seg button:hover { color: var(--text); }
  .chunk-seg button.on { background: var(--accent); color: #fff; }
  .rm-card { width: 100%; max-width: 460px; padding: 24px; }
  .rm-card h3 { margin: 0 0 4px; font-size: 18px; }
  .rm-card > p { margin: 0 0 18px; font-size: 13px; color: var(--muted); line-height: 1.5; }
  .rm-sec { margin: 0 0 8px; font-size: 11.5px; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; color: var(--muted); }
  .rm-opts { display: grid; grid-template-columns: minmax(0, 1fr); gap: 10px; margin-bottom: 18px; }
  .rm-opt { display: flex; gap: 14px; align-items: center; width: 100%; min-width: 0; padding: 14px 16px; border: 1px solid var(--border-strong); border-radius: 16px;
    background: var(--surface-2); color: var(--text); font: inherit; text-align: left; cursor: pointer; transition: border-color .15s, background .15s; }
  .rm-opt:hover { border-color: rgba(91, 155, 255, .45); }
  .rm-opt.on { border-color: var(--accent); background: var(--accent-soft); }
  .rm-opt:disabled { opacity: .45; cursor: default; }
  .rm-opt .rm-tx { flex: 1; min-width: 0; }
  .rm-opt b { display: block; font-size: 14px; }
  .rm-opt small { display: block; margin-top: 2px; font-size: 12px; color: var(--muted); line-height: 1.4; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .rm-ic { flex: none; width: 40px; height: 40px; display: grid; place-items: center; border-radius: 12px; background: var(--surface-3); color: var(--text-2); }
  .rm-opt.on .rm-ic { background: var(--accent); color: #fff; }
  .rm-ic .i { width: 19px; height: 19px; }
  .rm-radio { flex: none; width: 18px; height: 18px; border-radius: 50%; border: 2px solid var(--border-strong); }
  .rm-opt.on .rm-radio { border: 5px solid var(--accent); background: #fff; }
  .rm-seg { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 4px; padding: 4px; border: 1px solid var(--border); border-radius: 14px; background: var(--surface-2); }
  .rm-seg button { display: flex; align-items: center; justify-content: center; gap: 7px; height: 38px; border: 0; border-radius: 10px; background: none;
    color: var(--muted); font: inherit; font-size: 13px; font-weight: 600; cursor: pointer; }
  .rm-seg button .i { width: 15px; height: 15px; }
  .rm-seg button:hover { color: var(--text); }
  .rm-seg button.on { background: var(--surface-3); color: #fff; box-shadow: inset 0 0 0 1px var(--border-strong); }
  .rm-seg button.on[data-mu="with"] { background: var(--c-music); box-shadow: none; }
  .rm-note { display: block; margin-top: 8px; font-size: 12px; color: var(--muted); line-height: 1.45; }
  .rm-foot { display: flex; justify-content: flex-end; gap: 8px; margin-top: 22px; }
  .sty-card { width: 100%; max-width: 840px; max-height: calc(100vh - 32px); display: flex; flex-direction: column; padding: 0; overflow: hidden; }
  .sty-head { padding: 20px 22px 14px; border-bottom: 1px solid var(--border); }
  .sty-head h3 { margin: 0 0 4px; font-size: 17px; }
  .sty-head p { margin: 0; font-size: 12.5px; color: var(--muted); line-height: 1.5; }
  .sty-body { flex: 1; min-height: 0; overflow-y: auto; display: grid; grid-template-columns: 300px 1fr; gap: 22px; padding: 16px 22px; }
  .sty-body h4 { display: flex; justify-content: space-between; align-items: center; margin: 0 0 8px; font-size: 11.5px; font-weight: 700;
    letter-spacing: .05em; text-transform: uppercase; color: var(--muted); }
  .sty-body h4 button { border: 0; background: none; color: var(--accent-fg); font: inherit; font-size: 11.5px; text-transform: none; letter-spacing: 0; cursor: pointer; padding: 0; }
  .sty-opt { display: flex; gap: 10px; align-items: flex-start; padding: 8px 10px; border-radius: 10px; cursor: pointer; }
  .sty-opt:hover { background: var(--surface-2); }
  .sty-opt input { margin: 2px 0 0; width: 15px; height: 15px; flex: none; accent-color: var(--accent); }
  .sty-opt b { display: block; font-size: 13px; font-weight: 600; }
  .sty-opt small { display: block; font-size: 11.5px; color: var(--muted); line-height: 1.4; margin-top: 1px; }
  .sty-opt.na { opacity: .45; cursor: default; }
  /* Pengaturan teks utama template grafik */
  .gt-row { display: flex; gap: 8px; margin: 10px 0 4px; }
  .gt-al { display: flex; gap: 2px; padding: 3px; border: 1px solid var(--border); border-radius: 10px; background: var(--surface-2); }
  .gt-al .tb { width: 32px; height: 28px; }
  .gt-wt { flex: 1; min-width: 0; }
  /* Panel kiri: preset editing */
  .ps-new { display: flex; gap: 6px; padding: 6px 12px 4px; }
  .ps-new .input { flex: 1; min-width: 0; padding: 7px 10px; font-size: 12.5px; }
  .ps-list { flex: 1; min-height: 0; overflow-y: auto; display: flex; flex-direction: column; gap: 8px; padding: 8px 12px 10px; }
  .ps-card { display: grid; grid-template-columns: 52px minmax(0, 1fr); gap: 10px; padding: 8px; border: 1px solid var(--border); border-radius: 14px; background: var(--surface-2); }
  .ps-card:hover { border-color: var(--border-strong); }
  .ps-prev { position: relative; width: 52px; aspect-ratio: 9 / 16; border-radius: 8px; overflow: hidden;
    background: linear-gradient(180deg, #2a3242, #121722); container-type: inline-size; }
  .ps-prev img { position: absolute; transform: translate(-50%, -50%); }
  .ps-prev .sb { position: absolute; left: 0; right: 0; transform: translateY(-100%); text-align: center; font-size: 7px; font-weight: 900; letter-spacing: .02em; }
  .ps-card .nm { font-size: 12.5px; font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .ps-card .dt { font-size: 10.5px; color: var(--muted); margin-top: 1px; }
  .ps-chips { display: flex; flex-wrap: wrap; gap: 3px; margin-top: 5px; }
  .ps-chips span { display: inline-flex; align-items: center; gap: 3px; max-width: 100%; padding: 1px 6px; border-radius: 6px; background: var(--surface-3);
    font-size: 10px; color: var(--text-2); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .ps-chips i { flex: none; width: 7px; height: 7px; border-radius: 50%; box-shadow: 0 0 0 1px rgba(255, 255, 255, .25); }
  .ps-acts { display: flex; gap: 4px; margin-top: 7px; }
  .ps-acts .btn { height: 26px; padding: 0 9px; font-size: 11.5px; }
  .ps-acts .btn.primary { flex: 1; }
  .ps-acts .ic { width: 26px; padding: 0; }
  .ps-acts .ic .i { width: 13px; height: 13px; margin: 0; }
  .ps-empty { margin: 4px 0; padding: 20px 14px; border: 1px dashed var(--border-strong); border-radius: 12px; text-align: center; font-size: 12px; color: var(--muted); line-height: 1.5; cursor: pointer; }
  .ps-empty:hover { border-color: var(--accent); }
  .sty-clip .tt .me { margin-left: 4px; font-size: 10px; font-weight: 700; color: var(--accent-fg); }
  .p-check { display: flex; align-items: center; gap: 8px; margin: 10px 0 2px; font-size: 12.5px; color: var(--text-2); cursor: pointer; }
  .p-check input { width: 15px; height: 15px; margin: 0; accent-color: var(--accent); }
  .sty-sub { display: flex; flex-wrap: wrap; align-items: center; gap: 6px 12px; margin: -4px 0 6px 35px; font-size: 11.5px; color: var(--muted); }
  .sty-sub label { display: inline-flex; align-items: center; gap: 5px; color: var(--text-2); cursor: pointer; }
  .sty-sub input { margin: 0; accent-color: var(--accent); }
  .sty-opt.na:hover { background: none; }
  /* Pilihan clip tujuan: baris ringkas dengan thumbnail kecil */
  .sty-clips { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 8px; }
  .sty-clip { position: relative; display: grid; grid-template-columns: 30px minmax(0, 1fr) 20px; gap: 10px; align-items: center; padding: 6px 10px 6px 6px;
    border: 1px solid var(--border); border-radius: 12px; background: var(--surface-2); cursor: pointer; transition: border-color .15s, background .15s; }
  .sty-clip:hover { border-color: var(--border-strong); }
  .sty-clip:has(input:checked) { border-color: var(--accent); background: var(--accent-soft); }
  .sty-clip input { position: absolute; opacity: 0; pointer-events: none; }
  .sty-clip .th { position: relative; width: 30px; aspect-ratio: 9 / 16; border-radius: 6px; overflow: hidden; background: var(--surface-3); }
  .sty-clip .th img { width: 100%; height: 100%; object-fit: cover; display: block; }
  .sty-clip .th .busy2 { position: absolute; inset: 0; display: grid; place-items: center; background: rgba(5, 6, 8, .55); }
  .sty-clip .th .busy2 .spin { width: 12px; height: 12px; }
  .sty-clip .tx { min-width: 0; }
  .sty-clip .tt { display: block; font-size: 12.5px; font-weight: 600; line-height: 1.3; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .sty-clip .tt .no { color: var(--muted); font-weight: 700; margin-right: 4px; }
  .sty-clip .st { display: block; margin-top: 2px; font-size: 11px; color: var(--muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .sty-clip .st:empty { display: none; }
  .sty-clip .st.ok { color: var(--ok); }
  .sty-clip .st.err { color: var(--err); }
  .sty-clip .ck { width: 20px; height: 20px; display: grid; place-items: center; border-radius: 50%; border: 2px solid var(--border-strong); color: transparent; }
  .sty-clip .ck .i { width: 12px; height: 12px; }
  .sty-clip:has(input:checked) .ck { border-color: var(--accent); background: var(--accent); color: #fff; }
  .sty-clip.na { opacity: .5; cursor: default; }
  .sty-clip.na:hover { border-color: var(--border); }
  .sty-foot { display: flex; align-items: center; gap: 10px; padding: 14px 22px; border-top: 1px solid var(--border); }
  .sty-foot label { flex: 1; display: flex; gap: 8px; align-items: center; font-size: 12.5px; color: var(--text-2); cursor: pointer; }
  .sty-foot input { accent-color: var(--accent); width: 15px; height: 15px; margin: 0; }
  @media (max-width: 700px) { .sty-body { grid-template-columns: 1fr; } .sty-foot { flex-wrap: wrap; } .sty-foot label { flex-basis: 100%; } }
  .wait { position: fixed; inset: 0; z-index: 60; display: grid; place-items: center; padding: 16px;
    background: rgba(5, 6, 8, .72); backdrop-filter: blur(6px); animation: fadein .2s; }
  .wait .prep { margin: 0; max-height: calc(100vh - 32px); overflow-y: auto; }
  .wait .w-info { display: flex; gap: 10px; align-items: flex-start; margin: 0 0 18px; padding: 12px 14px; border-radius: 10px;
    background: var(--accent-soft); color: var(--text); font-size: 13px; line-height: 1.5; }
  .wait .w-info .spin { flex: none; margin-top: 2px; }
  .wait .w-foot { display: flex; justify-content: flex-end; gap: 8px; margin-top: 18px; }
  .vload-text { display: flex; flex-direction: column; align-items: center; gap: 10px; color: #fff; font-size: 13px; }
  .pending { display: flex; align-items: center; gap: 10px; padding: 8px 12px; margin-bottom: 10px; font-size: 13px; flex: none;
    background: var(--warn-soft); color: var(--warn); border-radius: 10px; }
  .pending b { font-weight: 600; }
  .prep .progress { margin: 18px 0 10px; }

  /* Layar sempit (HP/tablet): kembali bertumpuk & bisa di-scroll */
  @media (max-width: 900px) {
    body { overflow: auto; }
    .wrap { height: auto; }
    #app { display: flex; flex-direction: column; }
    #main { display: contents; }
    .clips { order: 2; margin-top: 12px; }
    .reels { order: 3; }
    .reels-legend { display: none; }
    .ed { grid-template-columns: minmax(0, 1fr); }
    .phone { flex: none; width: min(340px, 100%); min-height: 0; }
    .props { overflow: visible; }
    /* Preview: tinggi mengikuti lebar (9:16), bukan tinggi kolom yang di layar sempit tidak tetap */
    .pv-view { flex: none; overflow: visible; }
    .pv-view .phone { height: auto; width: min(340px, 100%); aspect-ratio: 9 / 16; }
    .stage { padding: 10px; }
    .transport { grid-template-columns: auto 1fr auto; }
    /* Bar atas: status disembunyikan, tombol cukup ikon */
    .bar { flex-wrap: wrap; gap: 8px; }
    .bar .title { display: none; }
    .bar > .btn { margin-left: auto; }
    .bar > .btn ~ .btn { margin-left: 0; }
    .bar > .btn { width: 40px; height: 36px; padding: 0; font-size: 0; gap: 0; }
    .bar > .btn .i { width: 17px; height: 17px; margin: 0; }
    .transport { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 6px; max-width: 100%; }
  }
  /* Sentuhan gradasi khusus editor */
  .framing.on, .fontopt.on, .ln.on { background: var(--grad-soft); }
  .handle { background: var(--grad); }
  .playhead { background: #fff; }
  .busy .ring { border-color: rgba(255, 255, 255, .2); border-top-color: #0058D3; border-right-color: #003A8C; }
  .pending { background-image: var(--grad-soft); }
  /* Panel editor menyatu: tanpa jarak & tanpa sudut membulat; garis tepi yang bersebelahan ditumpuk jadi satu */
  #app { column-gap: 0; }
  #app .card, #app .pending { border-radius: 0; box-shadow: none; }
  #app .card[role="dialog"], .wait .card[role="dialog"] { border-radius: var(--radius-modal); box-shadow: 0 24px 60px rgba(0, 0, 0, .5); }
  #main > .ed, #main > .prep { margin-left: -1px; }
  .ed { gap: 0; }
  .tlbar { margin-top: -1px; }
  #main > .pending { margin-bottom: 0; border: 1px solid var(--border); border-bottom: 0; }
  .props { gap: 0; margin-left: -1px; border: 1px solid var(--border); background: var(--surface); }
  .pt-tabs { border: 0; border-bottom: 1px solid var(--border); border-radius: 0; }
  .props .p-card { border: 0; border-bottom: 1px solid var(--border); }
  /* Hanya sudut terluar pembungkus panel yang membulat */
  #app .clips { border-top-left-radius: var(--radius); }
  #app .props, #main > .prep, #main > .pending { border-top-right-radius: var(--radius); }
  #main:has(> .pending:not([hidden])) .props { border-top-right-radius: 0; }
  #app .reels { border-radius: 0 0 var(--radius) var(--radius); }
  @media (max-width: 900px) {
    #main > .ed, #main > .prep, .props { margin-left: 0; }
    .props { margin-top: -1px; }
    .clips { margin-top: -1px; }
  }
  /* ---------- Persona warna editor: satu konsep dari brand BisaClip (biru #0058D3 → navy #003A8C) ----------
     Netral bernuansa navy; warna track satu keluarga biru: biru, indigo, cyan, sky. */
  :root {
    --c-media: #2563C9; --c-media-rgb: 37, 99, 201;
    --c-text: #4A4DCB; --c-text-rgb: 74, 77, 203;
    --c-fx: #0B8297; --c-fx-rgb: 11, 130, 151;
    --c-music: #5B4FD6; --c-music-rgb: 91, 79, 214;
    --c-foot: #2B86CC; --c-foot-rgb: 43, 134, 204;
    --c-split: #7C8CFF; --c-split-rgb: 124, 140, 255;
  }
  /* Warna dasar mengikuti token netral app.css, sama dengan editor manual (project.php) */
  :root { --accent-fg: #8fb8ff; }
  #app .card { background-image: none; }
  .pt-tab.on, .pt-tab.on:hover, .lp-tab.on, .lp-tab.on:hover { background: var(--accent-soft) !important; color: var(--accent-fg) !important; }
  .framing.on, .fontopt.on, .ln.on { background: var(--accent-soft); }
  .tooltip, .tipbox { box-shadow: none; }
  /* Gelap netral: label subtitle, area di luar trim, kotak media */
  :root .sc-lbl { background: #1a1d21; color: #c9ced6; box-shadow: inset 0 0 0 1px rgba(255, 255, 255, .1); }
  :root .sc-lbl.on { background: #24282d; color: #fff; box-shadow: inset 0 0 0 1px rgba(143, 184, 255, .55); }
  :root .fxt .sw3 { background-color: var(--surface-2); }
  :root .sc-dim { background: rgba(10, 11, 13, .74); }
  :root .sc-gap { background: repeating-linear-gradient(135deg, rgba(10, 11, 13, .85) 0 6px, rgba(10, 11, 13, .62) 6px 12px); }
  :root .tl-gut .ti { color: var(--text-2); background: none; box-shadow: none; }
  :root .tl-gut .tc { color: var(--muted); }
  :root .tl-gut .tc.on { color: var(--accent-fg); background: var(--accent-soft); }
  :root .sc-tr { background: var(--surface-2); }
  :root .trs .a { background: linear-gradient(135deg, #24282d, #121417); }
  :root input[type=range]::-webkit-slider-runnable-track {
    background: linear-gradient(to right, rgba(47, 123, 240, .75) var(--p, 50%), rgba(255, 255, 255, .12) var(--p, 50%)); }
  :root input[type=range]::-moz-range-progress { background: rgba(47, 123, 240, .75); }
  /* Halaman editor tanpa bayangan (bayangan konten video seperti teks tetap) */
  :root { --shadow: none; --glow: none; }
  .btn.primary, .btn.primary:hover, .toast, .card { box-shadow: none !important; }

  /* ---------- Tata letak seperti editor manual: rail menu di kiri · panel menu (Galeri clip / Media / …) · preview · properti · timeline ----------
     Aside menu & .ed memakai display: contents supaya rail, isi menu, preview, dan properti langsung jadi item grid #app. */
  #app { grid-template-columns: 56px 268px minmax(0, 1fr) 380px; grid-template-rows: auto minmax(0, 1fr) auto;
    grid-template-areas: "nav gal pend pend" "nav gal stage side" "tl tl tl tl"; }
  #app > .clips, #main > .ed { display: contents; }
  /* Rail ikon + label (sama dengan .rail editor manual) */
  .lp-tabs { grid-area: nav; width: auto; flex-direction: column; align-items: center; justify-content: flex-start; gap: 4px; padding: 8px 0; overflow-y: auto;
    background: var(--surface); border: 1px solid var(--border); }
  /* Rail ikon saja: ikon garis, berubah jadi ikon solid saat menu aktif (nama menu di tooltip) */
  .lp-tab { flex: none; width: 40px; height: 40px; display: grid; place-items: center; padding: 0; border-radius: 11px; }
  .lp-tab .i { grid-area: 1 / 1; width: 22px; height: 22px; }
  .lp-tab .ri-on { display: none; }
  .lp-tab.on .ri { display: none; }
  .lp-tab.on .ri-on { display: block; }
  .lp-tab .cnt { position: absolute; top: 3px; right: 4px; min-width: 15px; height: 15px; line-height: 15px; font-size: 9.5px; }
  .lp-tab.on .cnt { background: var(--accent); color: #fff; }
  /* Panel kiri: Galeri clip (bawaan) atau isi menu lain, bergantian di tempat yang sama */
  .reels { grid-area: gal; min-height: 0; overflow: hidden; }
  body.side-menu .reels { display: none; }
  .reels-head { padding: 12px 14px 0; }
  .reels-head .cnt { margin-left: auto; font-size: 11.5px; }
  .reels-prog { height: 4px; margin: 10px 14px 0; border-radius: 2px; background: var(--surface-3); overflow: hidden; }
  .reels-prog i { display: block; height: 100%; width: 0; border-radius: 2px; background: var(--grad); transition: width .3s; }
  .reels-legend { display: flex; align-items: center; gap: 6px; margin: 0; padding: 7px 14px 2px; font-size: 11px; color: var(--muted); }
  .reels-legend #reels-done { color: var(--text-2); font-weight: 600; }
  .reels .rl-chk { width: 14px; height: 14px; } .reels .rl-chk .i { width: 9px; height: 9px; }
  /* Detail kartu: badge lebih kecil, judul 2 baris, nomor clip + durasi */
  .rc .vs { top: 6px; left: 6px; padding: 1px 6px 1px 4px; font-size: 10px; }
  .rc .ck { top: 6px; right: 6px; width: 18px; height: 18px; }
  .rc .ck .i { width: 11px; height: 11px; }
  .rc .cap { left: 7px; right: 7px; bottom: 7px; gap: 5px; }
  .rc .cap b { font-size: 11px; line-height: 1.3; -webkit-line-clamp: 2; }
  .rc .cap small { gap: 4px; font-size: 10px; }
  .rc .cap .no { color: rgba(255, 255, 255, .7); font-weight: 700; }
  .rc .cap .du { padding: 0 4px; }
  .rc.on { outline-color: var(--accent-fg); box-shadow: 0 0 0 4px rgba(47, 123, 240, .18); }
  .rc:not(.on):hover { box-shadow: inset 0 0 0 1px rgba(255, 255, 255, .22); }
  /* flex-wrap (bukan grid): tinggi kartu mengikuti rasio 9:16 dari lebarnya, tidak dipadatkan oleh tinggi panel */
  .reels-row { flex: 1; min-height: 0; display: flex; flex-wrap: wrap; align-content: flex-start; gap: 10px 8px; padding: 12px 12px 16px;
    overflow-x: hidden; overflow-y: auto; scroll-snap-type: none; scrollbar-width: thin; }
  .rc { width: calc(50% - 4px); border-radius: 10px; box-shadow: inset 0 0 0 1px rgba(255, 255, 255, .06); outline-offset: 2px; }
  .rc:hover { transform: none; filter: brightness(1.08); }
  .rc-sep { flex: 0 0 100%; width: 100%; height: auto; margin: 4px 0 -2px; display: flex; align-items: center; gap: 6px; background: none;
    font-size: 10.5px; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; color: var(--muted); }
  .rc-sep::after { content: ""; flex: 1; height: 1px; background: var(--border-strong); order: 3; }
  .rc-sep b { order: 2; min-width: 18px; height: 16px; padding: 0 5px; border-radius: 8px; background: var(--surface-3); color: var(--text-2);
    font-size: 10px; line-height: 16px; text-align: center; }
  #main > .pending { grid-area: pend; }
  .ed > .stage { grid-area: stage; }
  .ed > .props { grid-area: side; }
  .lp-body { grid-area: gal; min-height: 0; overflow: hidden; background: var(--surface); border: 1px solid var(--border); }
  .lp-body .it-body { overflow-y: auto; }
  #main > .prep { grid-column: 3 / 5; grid-row: 1 / 3; }
  body:not(.side-menu) .lp-body { display: none; }
  /* Timeline selalu tampil, sudut bawah membulat */
  #app .tlbar { margin-top: -1px; padding: 0 16px 12px; border-radius: 0 0 var(--radius) var(--radius); }

  /* ---------- Timeline versi bersih ---------- */
  .tl-tools { margin: 0 -16px 6px; padding: 4px 10px; min-height: 36px; border-bottom: 1px solid var(--border); background: none; }
  .tl-tool { border: 0; background: none; color: var(--text-2); }
  .tl-tool:hover:not(:disabled) { background: var(--surface-2); color: var(--text); }
  .tl-tool:disabled { opacity: .3; }
  .tl-hint { font-size: 11.5px; color: var(--muted); opacity: .8; }
  /* Kolom track: ikon jenis track + tombol sembunyikan / kunci / bisukan (selalu terlihat, menyala saat aktif) */
  .tl-gut { border-right-color: transparent; }
  :root .tl-gut .ti { background: none; box-shadow: none; color: var(--muted); }
  .tl-gut .trk:hover .ti { color: var(--text); }
  :root .tl-gut .tc { color: rgba(168, 182, 205, .75); }
  :root .tl-gut .tc:hover { color: var(--text); background: rgba(148, 172, 214, .1); }
  /* Penggaris lebih tipis */
  .ruler span { font-size: 10px; opacity: .75; }
  .ruler i { opacity: .5; }
  /* Track kosong: garis tipis tanpa teks */
  .sc-mempty { border: 0 !important; background: rgba(148, 172, 214, .035) !important; border-radius: 6px; height: 22px; }
  .sc-mempty .st { display: none; }
  /* Item track: sudut lebih bulat, tanpa garis tepi berat */
  .sc-media, .sc-text, .sc-music, .sc-fx { border-radius: 7px; box-shadow: none; }
  /* Label subtitle: lebih kalem, teks kecil */
  :root .sc-lbl { height: 20px; margin-top: 1px; background: rgba(148, 172, 214, .08); color: var(--text-2); box-shadow: none; font-size: 10.5px; border-radius: 5px; }
  :root .sc-lbl:hover { background: rgba(148, 172, 214, .14); filter: none; }
  :root .sc-lbl.on { background: var(--accent-soft); color: #fff; box-shadow: inset 0 0 0 1px rgba(110, 168, 255, .45); }
  :root .sc-lbl.narrow { color: transparent; }
  .film, .sc-clip, .sc-foot { border-radius: 7px; }
  /* Posisi track (dari atas): 4 track utama terlihat langsung — Gambar 22 · Video 52 · Subtitle 96 · Musik 126 —
     lalu Teks 156 · Efek 186 · SFX 216 (scroll vertikal). Baris 26 px, video 40 px. Dipakai juga oleh area drop
     (bindTimelineDrop) & track kosong (--top) di JS. */
  .sc-inner { height: 246px; }
  /* align-items: flex-start: area track setinggi isinya (tidak dipotong setinggi kotak scroll) supaya semua track ikut ter-scroll */
  .scrub { max-height: 176px; overflow-y: auto; overflow-x: hidden; scrollbar-width: thin; align-items: flex-start; }
  .tl-gut .t-media { top: 22px; height: 26px; } .tl-gut .t-video { top: 52px; height: 40px; } .tl-gut .t-sub { top: 96px; height: 26px; }
  .tl-gut .t-music { top: 126px; height: 26px; } .tl-gut .t-text { top: 156px; height: 26px; } .tl-gut .t-fx { top: 186px; height: 26px; }
  .tl-gut .t-sfx { top: 216px; height: 26px; }
  .tl-gut .ti { width: 22px; height: 22px; } .tl-gut .ti .i { width: 14px; height: 14px; }
  .tl-gut .tc { width: 20px; height: 20px; }
  .sc-media, .tl-drop.med, .sc-mempty { top: var(--top, 22px); height: 26px; }
  .sc-media { top: 22px; } .sc-music { top: 126px; height: 26px; } .sc-text { top: 156px; height: 26px; } .sc-fx { top: 186px; height: 26px; }
  .sc-sfx { top: 216px; height: 26px; }
  .sc-media, .sc-text, .sc-fx, .sc-music { font-size: 11.5px; border-radius: 8px; }
  .sc-media .mth { width: 18px; height: 18px; }
  .film, .sc-foot, .sc-dim, .sc-clip, .sc-seg, .sc-cut, .sc-gap { top: 52px; height: 40px; }
  .sc-op { top: 52px; height: 15px; }
  .wave { top: 74px; height: 18px; }
  .sc-tr { top: 61px; }
  .tl-drop.foot { top: 48px; height: 48px; }
  :root .sc-lbl { top: 96px; height: 26px; line-height: 26px; margin-top: 0; font-size: 11px; }
  .tl-tools { min-height: 32px; margin-bottom: 2px; }
  #app .tlbar { padding-bottom: 8px; }

  @media (max-width: 900px) {
    #app { display: flex; }
    .lp-tabs { order: 0; width: 100%; flex-direction: row; overflow-x: auto; }
    #main > .pending { order: 1; }
    .ed > .stage { order: 2; }
    .reels, .lp-body { order: 3; }
    .lp-body { max-height: 70vh; }
    .ed > .props { order: 4; }
    .tlbar { order: 5; }
    .reels-row { max-height: 60vh; }
    .rc { width: calc(33.333% - 6px); }
  }

  /* ---------- Gaya visual editor manual (project.php): panel kartu terpisah, abu-abu netral, kontrol pill ----------
     Hanya tampilan; tata letak & fitur clip editor tetap. */
  .wrap { padding: 0 14px 12px; }
  .bar { padding: 10px 0; gap: 8px; }
  .bar .brand { display: none; }
  .bar .back { width: 34px; height: 34px; border-radius: 9px; border: 1px solid transparent; background: none; color: var(--text-2); }
  .bar .back:hover { background: var(--surface-2); color: var(--text); }
  .bar .title { border-left: 0; padding-left: 4px; }
  .bar .title span { font-size: 12px; }
  /* row-gap 0: baris banner "pending" yang kosong tidak menambah jarak; jarak vertikal diberi lewat margin */
  #app { column-gap: 10px; row-gap: 0; }
  #main > .pending:not([hidden]) { margin-bottom: 10px !important; }
  #app .tlbar { margin-top: 10px !important; }
  #app .card, #app .pending, .lp-tabs, .lp-body, .reels, .ed > .stage, .ed > .props, #app .tlbar, #main > .prep {
    margin: 0 !important; border: 1px solid var(--border); border-radius: 14px !important; background-color: var(--surface); }
  .ed > .props { overflow-y: auto; }
  .lp-tabs { padding: 6px; }
  .lp-tab.on, .lp-tab.on:hover { background: var(--accent-soft) !important; color: #8fb8ff !important; }
  /* Kontrol putar: tombol play bulat putih seperti editor manual */
  .tp-btns { gap: 6px; }
  .tp-skip { border: 0; background: none; color: var(--text-2); }
  .tp-skip:hover { background: var(--surface-2); color: var(--text); }
  #play { width: 42px; height: 42px; border-radius: 50%; background: #fff; color: #000; }
  #play:hover { background: #dfe6f2; color: #000; }
  #play .i { width: 18px; height: 18px; }
  .pv-zoom { border: 0; background: none; }
  /* Pilihan segmen: wadah abu-abu, pilihan aktif = pill lebih terang (seperti .seg editor manual) */
  .framings { gap: 3px; padding: 3px; border: 1px solid var(--border); border-radius: 10px; background: var(--surface-2); }
  .framings .framing { height: 30px; padding: 0 6px; border: 0; border-radius: 7px; background: none; color: var(--muted); font-size: 12px; font-weight: 600; }
  .framings .framing:hover { color: var(--text); }
  .framings .framing.on, .framings .framing.on:hover { background: var(--surface-3) !important; color: #fff; box-shadow: inset 0 0 0 1px var(--border-strong); }
  .chunk-seg button.on { background: var(--surface-3); color: #fff; box-shadow: inset 0 0 0 1px var(--border-strong); }
  /* Timeline kartu: jarak ke kartu di atasnya sama dengan jarak antarpanel (10 px) */
  #app .tlbar { padding: 0 14px 10px; margin-top: 10px !important; }
  .tl-tools { margin: 0 -14px 4px; padding: 4px 10px; }
</style>
</head>
<body>
<div class="wrap">
  <div class="bar">
    <a class="back" href="studio.php?id=<?= htmlspecialchars($pid) ?>" title="Kembali ke Studio" aria-label="Kembali ke Studio"><?= icon('arrow-left') ?></a>
    <a class="brand" href="index.php"><img src="assets/logo-96.png" alt=""><span><b>Bisa</b>Clip</span></a>
    <div class="title"><span id="t-status">Memuat…</span></div>
    <button class="btn" id="btn-style" disabled title="Salin style clip ini (subtitle, hook, filter, dll.) ke clip lain"><?= icon('copy') ?> Terapkan style</button>
    <button class="btn" id="btn-save" disabled><?= icon('save') ?> Simpan</button>
    <button class="btn primary" id="btn-render" disabled><?= icon('film') ?> Render clip</button>
  </div>
  <div id="app">
    <aside class="card clips" id="clips">
      <div class="lp-tabs">
        <button class="lp-tab on" data-lp="clips" title="Galeri clip" aria-label="Galeri clip"><svg class="i ri" aria-hidden="true"><use href="assets/icons.svg?v=<?= filemtime(__DIR__ . '/assets/icons.svg') ?>#rail-clips"/></svg><svg class="i ri-on" aria-hidden="true"><use href="assets/icons.svg?v=<?= filemtime(__DIR__ . '/assets/icons.svg') ?>#rail-clips-fill"/></svg></button>
        <button class="lp-tab" data-lp="media" title="Media" aria-label="Media"><svg class="i ri" aria-hidden="true"><use href="assets/icons.svg?v=<?= filemtime(__DIR__ . '/assets/icons.svg') ?>#rail-media"/></svg><svg class="i ri-on" aria-hidden="true"><use href="assets/icons.svg?v=<?= filemtime(__DIR__ . '/assets/icons.svg') ?>#rail-media-fill"/></svg><span class="cnt" id="media-info"></span></button>
        <button class="lp-tab" data-lp="music" title="Musik" aria-label="Musik"><svg class="i ri" aria-hidden="true"><use href="assets/icons.svg?v=<?= filemtime(__DIR__ . '/assets/icons.svg') ?>#rail-music"/></svg><svg class="i ri-on" aria-hidden="true"><use href="assets/icons.svg?v=<?= filemtime(__DIR__ . '/assets/icons.svg') ?>#rail-music-fill"/></svg><span class="cnt" id="music-info"></span></button>
        <button class="lp-tab" data-lp="sfx" title="Sound effect" aria-label="Sound effect"><svg class="i ri" aria-hidden="true"><use href="assets/icons.svg?v=<?= filemtime(__DIR__ . '/assets/icons.svg') ?>#rail-sfx"/></svg><svg class="i ri-on" aria-hidden="true"><use href="assets/icons.svg?v=<?= filemtime(__DIR__ . '/assets/icons.svg') ?>#rail-sfx-fill"/></svg><span class="cnt" id="sfx-info"></span></button>
        <button class="lp-tab" data-lp="text" title="Teks" aria-label="Teks"><svg class="i ri" aria-hidden="true"><use href="assets/icons.svg?v=<?= filemtime(__DIR__ . '/assets/icons.svg') ?>#rail-text"/></svg><svg class="i ri-on" aria-hidden="true"><use href="assets/icons.svg?v=<?= filemtime(__DIR__ . '/assets/icons.svg') ?>#rail-text-fill"/></svg><span class="cnt" id="text-info"></span></button>
        <button class="lp-tab" data-lp="fx" title="Efek & Filter" aria-label="Efek & Filter"><svg class="i ri" aria-hidden="true"><use href="assets/icons.svg?v=<?= filemtime(__DIR__ . '/assets/icons.svg') ?>#rail-fx"/></svg><svg class="i ri-on" aria-hidden="true"><use href="assets/icons.svg?v=<?= filemtime(__DIR__ . '/assets/icons.svg') ?>#rail-fx-fill"/></svg><span class="cnt" id="fx-info"></span></button>
        <button class="lp-tab" data-lp="tr" title="Transisi" aria-label="Transisi"><svg class="i ri" aria-hidden="true"><use href="assets/icons.svg?v=<?= filemtime(__DIR__ . '/assets/icons.svg') ?>#rail-tr"/></svg><svg class="i ri-on" aria-hidden="true"><use href="assets/icons.svg?v=<?= filemtime(__DIR__ . '/assets/icons.svg') ?>#rail-tr-fill"/></svg><span class="cnt" id="tr-info"></span></button>
        <button class="lp-tab" data-lp="intro" title="Intro video (hook + suara AI)" aria-label="Intro video"><svg class="i ri" aria-hidden="true"><use href="assets/icons.svg?v=<?= filemtime(__DIR__ . '/assets/icons.svg') ?>#rail-intro"/></svg><svg class="i ri-on" aria-hidden="true"><use href="assets/icons.svg?v=<?= filemtime(__DIR__ . '/assets/icons.svg') ?>#rail-intro-fill"/></svg><span class="cnt" id="intro-info"></span></button>
        <button class="lp-tab" data-lp="gfx" title="Template grafik" aria-label="Template grafik"><svg class="i ri" aria-hidden="true"><use href="assets/icons.svg?v=<?= filemtime(__DIR__ . '/assets/icons.svg') ?>#rail-gfx"/></svg><svg class="i ri-on" aria-hidden="true"><use href="assets/icons.svg?v=<?= filemtime(__DIR__ . '/assets/icons.svg') ?>#rail-gfx-fill"/></svg></button>
        <button class="lp-tab" data-lp="preset" title="Preset editing" aria-label="Preset editing"><svg class="i ri" aria-hidden="true"><use href="assets/icons.svg?v=<?= filemtime(__DIR__ . '/assets/icons.svg') ?>#rail-preset"/></svg><svg class="i ri-on" aria-hidden="true"><use href="assets/icons.svg?v=<?= filemtime(__DIR__ . '/assets/icons.svg') ?>#rail-preset-fill"/></svg><span class="cnt" id="preset-info"></span></button>
      </div>
      <div class="lp-body">
      <div class="lp-pane" id="lp-media" hidden>
        <div class="lp-title lp-title-row"><span>Media</span>
          <button class="media-plus" id="media-add" title="Unggah media" aria-label="Unggah media"><?= icon('plus') ?></button>
          <input type="file" id="media-input" accept="image/png,image/jpeg,image/webp,video/mp4,video/quicktime,video/webm,video/x-m4v" multiple hidden></div>
        <div class="media-grid" id="media-grid"></div>
      </div>
      <div class="lp-pane" id="lp-music" hidden>
        <div class="lp-title lp-title-row"><span>Musik</span>
          <button class="media-plus" id="music-add" title="Unggah musik ke library" aria-label="Unggah musik"><?= icon('plus') ?></button>
          <input type="file" id="music-input" accept="audio/*,.mp3,.m4a,.aac,.wav,.ogg,.flac" multiple hidden></div>
        <div class="mu-search"><input class="input" id="music-q" type="search" placeholder="Cari di library musik…" autocomplete="off"></div>
        <div class="mu-list" id="music-list"></div>
        <div class="mu-note">Library musik dipakai bersama semua proyek. Unduhan format MP3 / WAV dari Beranda otomatis masuk ke sini.</div>
      </div>
      <div class="lp-pane" id="lp-text" hidden>
        <div class="lp-title lp-title-row"><span>Teks</span>
          <button class="media-plus" id="text-add" title="Tambah teks dengan gaya yang dipilih" aria-label="Tambah teks" disabled><?= icon('plus') ?></button></div>
        <div class="txt-presets">
          <button class="tpre title" data-tpre="title">Judul</button>
          <button class="tpre body" data-tpre="body">Teks biasa</button>
          <button class="tpre box" data-tpre="box"><span>Teks kotak</span></button>
        </div>
      </div>
      <div class="lp-pane" id="lp-fx" hidden>
        <div class="lp-title lp-title-row"><span>Efek &amp; Filter</span>
          <button class="media-plus" id="fx-add" title="Tambah efek yang dipilih" aria-label="Tambah efek" disabled><?= icon('plus') ?></button></div>
        <div class="fx-tabs" id="fx-tabs"><button type="button" data-fxtab="fx">Efek</button><button type="button" data-fxtab="grade">Filter</button></div>
        <div class="fx-grid" id="fx-grid"></div>
      </div>
      <div class="lp-pane" id="lp-tr" hidden>
        <div class="lp-title">Transisi</div>
        <div class="fx-tabs" id="tr-tabs"><button type="button" data-trtab="open">Opening</button><button type="button" data-trtab="join">Sambungan</button></div>
        <div class="fx-grid" id="tr-grid"></div>
        <div class="fx-grid" id="op-grid" hidden></div>
      </div>
      <div class="lp-pane" id="lp-sfx" hidden>
        <div class="lp-title lp-title-row"><span>Sound effect</span>
          <span class="sfx-head-btns"><button class="media-plus" id="sfx-ai-toggle" title="Buat sound effect dengan AI (ElevenLabs)" aria-label="Buat sound effect dengan AI" aria-expanded="false" hidden><?= icon('sparkles') ?></button>
          <button class="media-plus" id="sfx-add" title="Unggah sound effect (MP3, WAV, M4A, OGG)" aria-label="Unggah sound effect"><?= icon('plus') ?></button></span></div>
        <input type="file" id="sfx-input" accept="audio/*,.mp3,.m4a,.aac,.wav,.ogg,.flac" multiple hidden>
        <div class="sfx-ai" id="sfx-ai" hidden>
          <div class="sfx-ai-row"><select class="input" id="sfx-dur" aria-label="Durasi" title="Durasi suara">
            <option value="">Auto</option><option value="0.5">0,5s</option><option value="1">1s</option><option value="1.5">1,5s</option>
            <option value="2">2s</option><option value="3">3s</option><option value="5">5s</option></select>
            <div class="sfx-chips" id="sfx-chips"></div></div>
          <div class="sfx-ai-row"><input class="input" id="sfx-prompt" maxlength="450" spellcheck="false" autocomplete="off" placeholder="Deskripsi suara (Inggris)…"
              title="Hasil ElevenLabs otomatis terunduh & masuk library">
            <button class="btn sm primary sfx-ai-go" id="sfx-gen" type="button" title="Generate dengan ElevenLabs (Enter)" aria-label="Generate"><?= icon('sparkles') ?></button></div>
          <div class="sfx-ai-st" id="sfx-ai-st" hidden></div>
        </div>
        <div class="sfx-list" id="sfx-list"></div>
      </div>
      <div class="lp-pane" id="lp-intro" hidden>
        <div class="lp-title">Intro video</div>
        <div class="it-body" id="it-body"></div>
      </div>
      <div class="lp-pane" id="lp-preset" hidden>
        <div class="lp-title lp-title-row"><span>Preset</span>
          <button class="media-plus" id="preset-add" title="Simpan style clip ini sebagai preset" aria-label="Simpan preset"><?= icon('plus') ?></button></div>
        <form class="ps-new" id="preset-form" hidden>
          <input class="input" id="preset-name" maxlength="60" placeholder="Nama preset" autocomplete="off">
          <button class="btn sm primary" type="submit">Simpan</button>
        </form>
        <div class="ps-list" id="preset-list"></div>
        <div class="mu-note">Preset menyimpan style clip: subtitle, grafik template &amp; hook (posisi, warna, teks), framing, filter, teks &amp; logo tetap, dan musik. Bisa dipakai di semua proyek.</div>
      </div>
      <div class="lp-pane" id="lp-gfx" hidden>
        <div class="lp-title">Template grafik</div>
        <div class="gfx-grid" id="gfx-grid"></div>
      </div>
      </div>
    </aside>
    <div id="main"><div class="card prep"><span class="spin"></span></div></div>
    <section class="card reels" id="reels" aria-label="Galeri clip">
      <div class="reels-head">
        <b>Galeri clip</b><span class="cnt" id="reels-cnt"></span>
      </div>
      <div class="reels-prog" title="Clip yang sudah diedit"><i id="reels-bar"></i></div>
      <div class="reels-legend"><span class="rl-chk on"><?= icon('check') ?></span> <span id="reels-done"></span> sudah diedit</div>
      <div class="reels-row" id="reels-row"></div>
    </section>
    <div class="sty" id="rmod" hidden>
      <div class="card rm-card" role="dialog" aria-modal="true" aria-labelledby="rm-title">
        <h3 id="rm-title">Render</h3>
        <p>Pilih clip yang mau dirender. Hasilnya muncul di daftar clip &amp; folder downloads.</p>
        <div class="rm-sec">Yang dirender</div>
        <div class="rm-opts">
          <button type="button" class="rm-opt" data-scope="one"><span class="rm-ic"><?= icon('film') ?></span>
            <span class="rm-tx"><b>Clip ini</b><small id="rm-one-sub"></small></span><i class="rm-radio"></i></button>
          <button type="button" class="rm-opt" data-scope="pending"><span class="rm-ic all"><?= icon('upload') ?></span>
            <span class="rm-tx"><b>Semua yang belum diupload</b><small id="rm-pend-sub"></small></span><i class="rm-radio"></i></button>
          <button type="button" class="rm-opt" data-scope="all"><span class="rm-ic all"><?= icon('layers') ?></span>
            <span class="rm-tx"><b>Semua clip</b><small id="rm-all-sub"></small></span><i class="rm-radio"></i></button>
        </div>
        <div id="rm-music">
          <div class="rm-sec">Musik latar</div>
          <div class="rm-seg"><button type="button" data-mu="with"><?= icon('music') ?> Dengan musik</button><button type="button" data-mu="without"><?= icon('volume') ?> Tanpa musik</button><button type="button" data-mu="both"><?= icon('layers') ?> Keduanya</button></div>
          <small class="rm-note" id="rm-music-note"></small>
        </div>
        <div class="rm-foot"><button class="btn ghost" type="button" id="rm-cancel">Batal</button>
          <button class="btn primary" type="button" id="rm-go"><?= icon('film') ?> Render</button></div>
      </div>
    </div>
    <div class="sty" id="sty" hidden>
      <div class="card sty-card" role="dialog" aria-modal="true" aria-labelledby="sty-title">
        <div class="sty-head"><h3 id="sty-title">Terapkan style ke clip lain</h3>
          <p id="sty-desc">Style dari clip ini disalin ke clip yang dipilih. Isi tiap clip tetap miliknya sendiri: teks subtitle, potongan, dan kalimat hook tidak ikut berubah.</p></div>
        <div class="sty-body">
          <div><h4>Yang disalin</h4><div id="sty-parts"></div></div>
          <div><h4><span>Clip tujuan</span><button type="button" id="sty-all">Pilih semua</button></h4><div class="sty-clips" id="sty-clips"></div></div>
        </div>
        <div class="sty-foot">
          <label><input type="checkbox" id="sty-render"> Langsung render ulang clip tujuan</label>
          <button class="btn ghost" type="button" id="sty-cancel">Batal</button>
          <button class="btn primary" type="button" id="sty-go"><?= icon('copy') ?> Terapkan</button>
        </div>
      </div>
    </div>
  </div>
</div>
<video id="vid" preload="auto" playsinline hidden></video>

<script src="assets/common.js?v=<?= filemtime(__DIR__ . '/assets/common.js') ?>"></script>
<script src="assets/gfx.js?v=<?= filemtime(__DIR__ . '/assets/gfx.js') ?>"></script>
<script>
const PID = <?= json_encode($pid) ?>;
const UPLOAD_MAX = <?= (function () { $b = fn($v) => (int) $v * (['K' => 1 << 10, 'M' => 1 << 20, 'G' => 1 << 30][strtoupper(substr(trim($v), -1))] ?? 1);
  return min($b(ini_get('upload_max_filesize')), $b(ini_get('post_max_size'))); })() ?>;
let IID = <?= json_encode($iid) ?>;  // clip yang sedang diedit; bisa berganti lewat daftar clip
let vid = $('vid');       // video proxy clip aktif (diganti saat pindah clip)
let itCfg = null, itFrame = null, itBusy = false, itAudio = null; // menu Intro: pengaturan form, screenshot, status generate, suara
const FONTS = <?= json_encode($fonts, JSON_UNESCAPED_UNICODE) ?>;
const EFFECTS = <?= json_encode(EFFECT_PRESETS, JSON_UNESCAPED_UNICODE) ?>;
const TRANSITIONS = <?= json_encode(TRANSITION_PRESETS, JSON_UNESCAPED_UNICODE) ?>;
const OPENINGS = <?= json_encode(OPENING_PRESETS, JSON_UNESCAPED_UNICODE) ?>;
// Suara AI intro (Gemini TTS)
const ttsVoices = <?= json_encode(tts_voices()['voices'], JSON_UNESCAPED_UNICODE) ?>;
const voiceInfo = id => ttsVoices.find(v => v.id === id);
const voiceName = id => voiceInfo(id)?.name || id;
const TTS_STYLES = <?= json_encode(array_map(fn($x) => $x[0], TTS_STYLES), JSON_UNESCAPED_UNICODE) ?>;
// Bawaan: suara terakhir dipakai, kalau tidak ada Puck (pria ceria)
function defaultVoice() {
  let v = '';
  try { v = localStorage.getItem('ed-intro-voice') || ''; } catch {}
  return voiceInfo(v) ? v : 'Puck';
}
const PAPER_DUR = 0.63; // transisi kertas robek: 19 frame @30 fps (tools/paper_tear.py)
const OP_LETTERBOX = <?= OPENING_LETTERBOX ?>;
const FONT_CATS = [['sans', 'Sans'], ['display', 'Display / tebal'], ['serif', 'Serif'], ['script', 'Tulisan tangan']];
const fontFamily = k => `"${(FONTS.find(f => f.k === k) || FONTS[0]).family}", "Arial Black", sans-serif`;
const fontLabel = k => (FONTS.find(f => f.k === (k || 'arial-black')) || FONTS[0]).label;
/* Pemilih font visual: tiap pilihan bertuliskan nama font dalam bentuk font itu sendiri */
let fpkOpen = null; // target panel teks yang daftar font-nya sedang terbuka
function fontListHtml(tg, c) {
  const opt = tg === 'hl' || tg === 'gfx', cur = opt ? c.font : c.font || 'arial-black';
  return `<div class="fpk-list" role="listbox" aria-label="Font">${opt ? `<button type="button" class="fpk-opt fpk-same${cur ? '' : ' on'}" data-fpk-v="" data-fpk-tg="${tg}"><span class="fpk-s">${tg === 'hl' ? 'Sama dengan subtitle' : 'Bawaan template'}</span></button>` : ''}${FONT_CATS.map(([cat, n]) => {
    const list = FONTS.filter(f => f.cat === cat);
    return list.length ? `<div class="fpk-cat">${n}</div>${list.map(f => `<button type="button" class="fpk-opt${f.k === cur ? ' on' : ''}" role="option"
      aria-selected="${f.k === cur}" data-fpk-v="${f.k}" data-fpk-tg="${tg}" title="${esc(f.label)}"><span class="fpk-s" style='font-family:${fontFamily(f.k)}'>${esc(f.label)}</span></button>`).join('')}` : '';
  }).join('')}</div>`;
}
function pickFont(tg, k) {
  if (tg === 'gfx') {
    // Font teks utama template grafik; ketebalan yang tidak ada di font baru kembali ke bawaan
    const m = E.media[sel.i];
    if (!m?.gfx) return;
    m.gfx.tf = k;
    const ws = k ? FONTS.find(f => f.k === k)?.w || [] : [400, 500, 600, 700, 800, 900];
    if (m.gfx.tw && !ws.includes(+m.gfx.tw)) m.gfx.tw = '';
    fpkOpen = null;
    changed(); renderProps(); scheduleGfx(m, true);
    if (!m.ref) syncGfxOthers(m);
    return;
  }
  if (tg === 'hl') { E.style.hl_font = k; fpkOpen = null; changed(); renderProps(); draw(); return; } // font highlight subtitle
  const c = cfgOf(tg);
  c.font = k;
  // Ketebalan yang tidak ada di font baru kembali ke bawaan
  if (c.weight && !fontWeightOf(c)) c.weight = 0;
  fpkOpen = null;
  changed();
  renderProps();
  draw();
}
// Daftar pilihan font; tiap nama ditampilkan memakai font-nya sendiri. target: 'style' (subtitle) atau 'hook'
/* Panel teks: font, ukuran, tebal, jarak huruf, perataan, gaya, warna. Semua input bertanda data-ts="target.field". */
const swatch = (k, v) => `<label class="sw" style="--c:${v}"><input type="color" data-ts="${k}" value="${v}"></label>`;
const hexField = (k, v) => `<div class="ts-f hex">${swatch(k, v)}<input data-hex="${k}" value="${v.slice(1)}" maxlength="7" spellcheck="false" aria-label="Kode warna"><span class="u">Hex</span></div>`;
const numField = (k, v, min, max, step, unit, icon, tip, cls = '') => `<div class="ts-f ${cls}" ${tip ? `title="${tip}"` : ''}>${icon ? ic(icon) : ''}
  <input type="number" data-ts="${k}" value="${v}" min="${min}" max="${max}" step="${step}"><span class="u">${unit}</span></div>`;
// Objek pengaturan & elemen preview per target: 'style' (subtitle), 'hook', atau 'texts.N' (teks tambahan)
const cfgOf = tg => tg.split('.').reduce((o, k) => o[k], E);
const textElOf = tg => tg === 'style' ? $('sub')?.querySelector('.sbox') : tg === 'hook' ? $('hook')?.querySelector('span')
  : $('tlayer')?.querySelector(`.tbox[data-ti="${tg.split('.')[1]}"] span`);
// Pilihan ketebalan: bawaan font (+ bold sintetis) dan ketebalan asli yang terpasang untuk font itu
const WEIGHT_NAMES = { 400: 'Regular', 500: 'Medium', 600: 'Semi Bold', 700: 'Bold', 800: 'Extra Bold', 900: 'Black' };
// Ketebalan sebenarnya file font bawaan (<key>.ttf, dipakai saat weight = 0); diukur dengan membandingkan lebar teks dengan file -w<bobot>
const FONT_BASE_W = { montserrat: 800, poppins: 800, inter: 800, rubik: 800, nunito: 900, oswald: 700, 'playfair-display': 800, merriweather: 900,
  lora: 700, 'libre-baskerville': 700, 'cormorant-garamond': 700, 'bodoni-moda': 800, 'eb-garamond': 700, 'roboto-slab': 800, fraunces: 400, cinzel: 400, caveat: 600, 'dancing-script': 600 };
function weightOptions(c) {
  const ws = FONTS.find(f => f.k === (c.font || 'arial-black'))?.w || [];
  // Font dengan beberapa ketebalan asli: Regular, Medium, Semi Bold, Bold, Extra Bold, Black (tanpa pilihan "bawaan")
  if (ws.length) {
    // Belum pernah dipilih: font bawaan (+ tebal sintetis = paling tebal)
    const k = c.font || 'arial-black', cur = fontWeightOf(c) || (c.bold ? ws.at(-1) : ws.includes(FONT_BASE_W[k]) ? FONT_BASE_W[k] : ws.at(-1));
    return ws.map(w => `<option value="${w}" ${cur === w ? 'selected' : ''}>${WEIGHT_NAMES[w]}</option>`).join('');
  }
  // Font display satu ketebalan: hanya normal & tebal sintetis
  const cur = c.bold ? 'b' : 'r';
  return [['r', 'Normal'], ['b', 'Lebih tebal']].map(([v, n]) => `<option value="${v}" ${cur === v ? 'selected' : ''}>${n}</option>`).join('')
    + '<option disabled>Font ini hanya 1 ketebalan</option>';
}
function textPanel(tg, cfg) {
  const c = cfg || cfgOf(tg), isSub = tg === 'style', isText = tg.startsWith('texts.') || tg === 'preview';
  const tb = (attr, on, icon, tip) => `<button type="button" class="tb ${on ? 'on' : ''}" ${attr} title="${tip}">${ic(icon)}</button>`;
  return `<div class="ts-stack">
    <div class="ts">
      <div class="ts-row">
        <button type="button" class="ts-f grow fpk-btn${fpkOpen === tg ? ' on' : ''}" data-fpk="${tg}" aria-expanded="${fpkOpen === tg}" title="Pilih font"
          style='font-family:${fontFamily(c.font || 'arial-black')}'><span>${esc(fontLabel(c.font))}</span>${ic('chevron-down').replace('class="i"', 'class="i chev"')}</button>
        ${numField(tg + '.size', c.size, isText ? 20 : isSub ? 40 : 30, isText ? 200 : isSub ? 180 : 140, 1, 'px', '', 'Ukuran huruf', 'w-size')}
        <div class="ts-f w-wt"><select data-wt="${tg}" aria-label="Ketebalan">${weightOptions(c)}</select>${ic('chevron-down').replace('class="i"', 'class="i chev"')}</div>
      </div>
      ${fpkOpen === tg ? fontListHtml(tg, c) : ''}
      <div class="ts-row">
        ${numField(tg + '.spacing', c.spacing, -5, 30, 0.5, 'px', 'spacing', 'Jarak antar huruf')}
        ${tg === 'hook' ? numField('hook.duration', c.duration, 0.5, 10, 0.1, 'dtk', 'play', 'Durasi tampil hook') : ''}
        <button type="button" class="ts-f" data-p="center-text" data-v="${tg}" title="Kembalikan teks ke tengah layar">${ic('align-center')} Ke tengah</button>
      </div>
      <div class="ts-tools">
        ${['left', 'center', 'right'].map(a => tb(`data-align="${tg}" data-v="${a}"`, (c.align || 'center') === a, 'align-' + a, { left: 'Rata kiri', center: 'Rata tengah', right: 'Rata kanan' }[a])).join('')}
        <span class="sep"></span>
        ${tb(`data-flag="${tg}.italic"`, c.italic, 'italic', 'Miring')}
        ${tb(`data-flag="${tg}.underline"`, c.underline, 'underline', 'Garis bawah')}
        ${isSub ? tb('data-flag="style.uppercase"', c.uppercase, 'case', 'Huruf kapital semua') : ''}
      </div>
    </div>
    <div class="ts-c"><label>${isSub ? 'Warna' : 'Warna teks'}</label>${hexField(tg + '.color', c.color)}${numField(tg + '.opacity', c.opacity, 0, 100, 1, '%', '', 'Opacity')}</div>
    ${isSub ? `<div class="ts-c"><label>Border</label>${hexField('style.stroke', c.stroke)}${numField('style.stroke_w', c.stroke_w, 0, 20, 0.5, 'px', '', 'Tebal border')}</div>
      <div class="ts-c"><label>Kata<span class="tip" data-tip="Jumlah kata yang tampil sekaligus. Otomatis = satu baris penuh seperti hasil transkrip.${E.caption === 'kinetic' ? ' Tidak berlaku untuk teks kinetik.' : ''}">?</span></label>
        <div class="chunk-seg">${[[0, 'Otomatis'], [1, '1'], [2, '2'], [3, '3'], [4, '4']].map(([v, n]) =>
          `<button type="button" class="${(c.chunk || 0) === v ? 'on' : ''}" data-chunk="${v}" title="${v ? v + ' kata per tampilan' : 'Satu baris penuh'}">${n}</button>`).join('')}</div></div>`
    : `${isText ? `<div class="ts-c"><label>Border</label>${hexField(tg + '.stroke', c.stroke)}${numField(tg + '.stroke_w', c.stroke_w, 0, 20, 0.5, 'px', '', 'Tebal border (tidak dipakai saat latar aktif)')}</div>` : ''}
      <div class="ts-c"><label>Latar</label>${hexField(tg + '.bg', c.bg)}${numField(tg + '.bg_opacity', c.bg_opacity, 0, 100, 1, '%', '', 'Opacity latar')}</div>`}
  </div>`;
}
// Ganti perataan tanpa membuat teks melompat: titik jangkar dihitung ulang dari posisi kotak teks sekarang
function setAlign(tg, a) {
  const c = cfgOf(tg), el = textElOf(tg);
  const k = $('phone').clientWidth / 1080, o = $('ovl').getBoundingClientRect();
  if (el && el.offsetParent) {
    const r = el.getBoundingClientRect(), l = (r.left - o.left) / k, w = r.width / k;
    c.x = Math.round((a === 'left' ? l : a === 'right' ? l + w : l + w / 2) / 1080 * 1e4) / 1e4;
  } else if (tg === 'style' || c.x != null) {
    c.x = { left: SAFE.l, center: 540, right: SAFE.r }[a] / 1080;
  }
  c.align = a;
}
// Rasio split screen: persen tinggi panel atas (sama dengan SPLIT_RATIOS di editor_lib.php)
const SPLIT_RATIOS = [50, 40, 60, 30, 70];
const splitTop = () => Math.round(1920 * (SPLIT_RATIOS.includes(E.frame.split) ? E.frame.split : 50) / 100 / 2) * 2;
// Layout "Split game" (bingkai split) = gameplay atas + webcam bawah; selain itu ikuti pembicara (sama dengan editor_lib.php)
function syncLayout() {
  E.layout = E.frame.ratio === 'split' ? 'gaming' : 'speaker';
}
// Auto split (toggle): layar terbelah 2 orang otomatis di bagian yang menampilkan 2 narasumber; hanya untuk layout Full
const autoSplitOn = () => !!E.split.enabled && (E.frame.ratio || 'full') === 'full';
// Layout bingkai: rasio area video (lebar / tinggi) di layar 9:16; selain Full, video di tengah dengan background solid (hitam) atau blur
const FRAME_RATIOS = [['full', 'Full', 9 / 16], ['1:1', '1:1', 1], ['4:5', '4:5', 0.8], ['3:4', '3:4', 0.75], ['16:9', '16:9', 16 / 9]];
function frameGeom(CW = 1080, CH = 1920) {
  // Split mix: clip di panel atas (ikuti pembicara), panel bawah media pilihan user
  if (E.layout !== 'gaming' && E.frame?.ratio === 'mix') {
    const h = CH * splitTop() / 1920;
    return { a: CW / h, full: false, mix: true, x: 0, y: 0, w: CW, h, r: (E.frame.radius || 0) * CW / 1080, on: true, blur: false };
  }
  const f = E.layout === 'gaming' ? {} : E.frame || {}, a = (FRAME_RATIOS.find(r => r[0] === f.ratio) || FRAME_RATIOS[0])[2];
  const full = ['full', 'split'].includes(f.ratio || 'full'), h = full ? CH : CW / a; // split screen selalu layar penuh
  return { a, full, x: 0, y: (CH - h) / 2, w: CW, h, r: (f.radius || 0) * CW / 1080, on: !full || (f.radius || 0) > 0, blur: !full && f.bg === 'blur' };
}
// Bar hitam & sudut membulat di atas video + efek (sama dengan masker render)
function drawFrameMask(g, CW, CH) {
  const G = frameGeom(CW, CH);
  // Background blur sudah digambar paintMain di luar bingkai (sudut membulat lewat clip)
  if (!G.on || G.blur || (E.split.enabled && splitPair(t))) return;
  g.save();
  g.fillStyle = '#000';
  g.beginPath();
  g.rect(0, 0, CW, CH);
  g.roundRect(G.x, G.y, G.w, G.h, G.r);
  g.fill('evenodd');
  g.restore();
  if (G.mix) drawMix(g, CW, CH, G);
  else if (g === ctx && !$('mixbox').hidden) placeMixBox();
}
// Media panel bawah Split mix: foto diam / video diulang mengikuti waktu clip, memenuhi panel (dipotong rata tengah)
const mixEls = {};
function mixEl(m) {
  let el = mixEls[m.file];
  if (!el) {
    if (m.kind === 'video') {
      el = Object.assign(document.createElement('video'), { muted: true, loop: true, playsInline: true, preload: 'auto', src: 'previews/overlays/' + m.file });
      el.addEventListener('loadeddata', () => draw());
      el.addEventListener('seeked', () => { if (!playing) draw(); });
    } else {
      el = new Image();
      el.onload = () => draw();
      el.src = 'previews/overlays/' + m.file;
    }
    mixEls[m.file] = el;
  }
  return el;
}
// Ukuran & posisi media dalam px desain (lebar layar 1080): s = skala dari ukuran "memenuhi panel", cx/cy = titik tengah (pecahan panel)
function mixRect(iw, ih) {
  const m = E.frame.mix, T = splitTop(), ph = 1920 - T, k = Math.max(1080 / iw, ph / ih) * (m.s ?? 1);
  const w = iw * k, h = ih * k;
  return { x: 1080 * (m.cx ?? 0.5) - w / 2, y: T + ph * (m.cy ?? 0.5) - h / 2, w, h, T, ph };
}
const mixDims = el => el instanceof HTMLVideoElement ? [el.videoWidth, el.videoHeight] : [el.naturalWidth, el.naturalHeight];
function drawMix(g, CW, CH, G) {
  const m = E.frame.mix, y = G.h, h = CH - y;
  g.fillStyle = m ? '#000' : '#15171c';
  g.fillRect(0, y, CW, h);
  if (!m) {
    g.fillStyle = 'rgba(255,255,255,.45)';
    g.font = `600 ${Math.round(CW / 26)}px Inter, system-ui, sans-serif`;
    g.textAlign = 'center';
    g.fillText('Pilih foto / video untuk panel ini', CW / 2, y + h / 2);
    if (g === ctx) placeMixBox();
    return;
  }
  const el = mixEl(m), vid2 = m.kind === 'video';
  if (vid2) {
    if (el.readyState < 2 || !el.duration) return;
    const want = ((t - E.trim[0]) % el.duration + el.duration) % el.duration;
    if (playing && !fp) { if (el.paused) el.play().catch(() => {}); if (Math.abs(el.currentTime - want) > 0.35) el.currentTime = want; }
    else { if (!el.paused) el.pause(); if (Math.abs(el.currentTime - want) > 0.04) el.currentTime = want; }
  } else if (!el.complete || !el.naturalWidth) return;
  const [iw, ih] = mixDims(el), r = mixRect(iw, ih), k = CW / 1080;
  g.save();
  g.beginPath();
  g.rect(0, y, CW, h);
  g.clip();
  g.drawImage(el, r.x * k, r.y * k, r.w * k, r.h * k);
  g.restore();
  if (g === ctx) placeMixBox();
}
// Kotak atur media di atas preview (tampil saat media panel bawah dipilih)
let mixSel = false, mixDrag = null;
function placeMixBox() {
  const box = $('mixbox'), m = E?.frame?.mix;
  if (!box) return;
  const el = m && E.frame.ratio === 'mix' && mixEls[m.file];
  const ok = !!(mixSel && el && !fp && mixDims(el)[0]);
  box.hidden = !ok;
  if (!ok) return;
  const r = mixRect(...mixDims(el));
  Object.assign(box.style, { left: r.x / 10.8 + '%', top: r.y / 19.2 + '%', width: r.w / 10.8 + '%', height: r.h / 19.2 + '%' });
}
function mixPoint(e) {
  const o = $('ovl').getBoundingClientRect();
  return [(e.clientX - o.left) / o.width * 1080, (e.clientY - o.top) / o.height * 1920];
}
function bindMixBox() {
  const box = $('mixbox');
  if (!box || box._bound) return;
  box._bound = true;
  // Klik media di panel bawah preview: pilih lalu langsung bisa diseret
  $('cv').addEventListener('pointerdown', e => {
    if (!E || fp || E.frame.ratio !== 'mix' || !E.frame.mix || mixDrag) return;
    if (mixPoint(e)[1] < splitTop()) return;
    mixSel = true;
    draw();
    startMixDrag(e, null);
  });
  box.addEventListener('pointerdown', e => startMixDrag(e, e.target.dataset.mc || null));
  box.addEventListener('wheel', e => {
    e.preventDefault();
    const m = E.frame.mix;
    m.s = Math.round(Math.max(0.2, Math.min(4, (m.s ?? 1) * Math.exp(-e.deltaY * 0.0015))) * 1000) / 1000;
    draw(); syncMixSlider();
    clearTimeout(box._t); box._t = setTimeout(changed, 400);
  }, { passive: false });
  // Klik di luar media & panel properti: kotak disembunyikan
  document.addEventListener('pointerdown', e => {
    if (!mixSel || e.target.closest('#mixbox, #cv, #props')) return;
    mixSel = false; placeMixBox();
  });
}
function startMixDrag(e, corner) {
  const m = E.frame.mix, el = m && mixEls[m.file];
  if (!el || !mixDims(el)[0]) return;
  e.preventDefault(); e.stopPropagation();
  pause();
  const r = mixRect(...mixDims(el)), [px, py] = mixPoint(e);
  mixDrag = { corner, px, py, r, s: m.s ?? 1, cx: m.cx ?? 0.5, cy: m.cy ?? 0.5,
    ox: corner?.[1] === 'l' ? r.x + r.w : r.x, oy: corner?.[0] === 't' ? r.y + r.h : r.y };
  const move = ev => {
    const d = mixDrag, [x, y] = mixPoint(ev);
    if (!d.corner) {
      // Geser; tengah panel menempel (snap) kalau dekat
      let cx = d.cx + (x - d.px) / 1080, cy = d.cy + (y - d.py) / d.r.ph;
      if (Math.abs(cx - 0.5) < 0.015) cx = 0.5;
      if (Math.abs(cy - 0.5) < 0.015) cy = 0.5;
      m.cx = Math.round(Math.max(-1, Math.min(2, cx)) * 1e4) / 1e4;
      m.cy = Math.round(Math.max(-1, Math.min(2, cy)) * 1e4) / 1e4;
    } else {
      // Ubah ukuran dari sudut, sudut seberang tetap; rasio media terjaga
      const f = Math.max(Math.abs(x - d.ox) / d.r.w, Math.abs(y - d.oy) / d.r.h);
      const s2 = Math.max(0.2, Math.min(4, d.s * f)), w = d.r.w * s2 / d.s, h = d.r.h * s2 / d.s;
      const l = d.corner[1] === 'l' ? d.ox - w : d.ox, tp = d.corner[0] === 't' ? d.oy - h : d.oy;
      m.s = Math.round(s2 * 1000) / 1000;
      m.cx = Math.round((l + w / 2) / 1080 * 1e4) / 1e4;
      m.cy = Math.round((tp + h / 2 - d.r.T) / d.r.ph * 1e4) / 1e4;
    }
    draw(); syncMixSlider();
  };
  const up = () => {
    removeEventListener('pointermove', move);
    removeEventListener('pointerup', up);
    const moved = m.s !== mixDrag.s || m.cx !== mixDrag.cx || m.cy !== mixDrag.cy;
    mixDrag = null;
    if (moved) changed();
  };
  addEventListener('pointermove', move);
  addEventListener('pointerup', up);
}
// Slider "Ukuran" di panel ikut berubah saat media diubah dari preview
function syncMixSlider() {
  const r = $('props')?.querySelector('[data-mixs]');
  if (!r) return;
  r.value = Math.round((E.frame.mix.s ?? 1) * 100);
  r.nextElementSibling.textContent = r.value + '%';
  syncRange(r);
}
/* Template grafik (GFX, gfxCanvas, wrapText, richLines, …): assets/gfx.js, dipakai bersama editor manual */

/**
 * Banner selebar layar: pita memanjang sampai tepi bawah layar dari posisi grafik sekarang.
 * Tepi atas grafik tetap (atau topPx kalau diberikan); ext & titik tengah y dihitung ulang.
 */
async function fitBand(m, topPx = null) {
  const g = m.gfx;
  if (!g || !GFX[g.type]?.full) return;
  if (g.fill === false) { g.ext = 0; return; }
  const s = 1080 * m.w / (GFX_W + 36);                           // px layar per px desain (kanvas = lebar + margin 2×18)
  const h0 = (await gfxCanvas({ ...g, ext: 0 }, 1)).height;     // tinggi kanvas tanpa perpanjangan
  const top = topPx ?? m.y * 1920 - (h0 + Math.max(0, g.ext || 0)) * s / 2;
  g.ext = Math.max(0, Math.min(4000, Math.ceil((1920 - top) / s - h0) + 4));
  m.y = Math.round((top + (h0 + g.ext) * s / 2) / 1920 * 1e4) / 1e4;
}
const isFullGfx = m => !!m?.gfx && !!GFX[m.gfx.type]?.full;

// Preview langsung (data URL), lalu PNG diunggah (ditunda sedikit saat mengetik). Simpan & render menunggu unggahan selesai.
const gfxJobs = new Map();
async function previewGfx(m) {
  await fitBand(m);
  const c = await gfxCanvas(m.gfx, 1);
  m._src = c.toDataURL('image/png');
  draw();
}
function scheduleGfx(m, now) {
  previewGfx(m);
  clearTimeout(m._t);
  m._t = setTimeout(() => uploadGfx(m), now ? 0 : 700);
}
async function uploadGfx(m) {
  m._t = null;
  const job = (async () => {
    await fitBand(m);
    const c = await gfxCanvas(m.gfx, 2);
    const blob = await new Promise(r => c.toBlob(r, 'image/png'));
    const fd = new FormData();
    fd.append('file', blob, 'grafik.png');
    const res = await fetch('api.php?action=overlay_upload', { method: 'POST', body: fd });
    const d = await res.json().catch(() => ({ error: 'Respons server tidak valid.' }));
    if (!res.ok || d.error) throw new Error(d.error || 'Grafik gagal disimpan.');
    m.file = d.file;
    renderTimeline();
  })();
  gfxJobs.set(m.id, job);
  try { await job; } catch (err) { toast(err.message); } finally { if (gfxJobs.get(m.id) === job) gfxJobs.delete(m.id); }
}
async function flushGfx() {
  E.media.forEach(m => { if (m._t) { clearTimeout(m._t); uploadGfx(m); } });
  await Promise.allSettled([...gfxJobs.values()]);
}

// Teks hook hasil AI dijadikan grafik template "Hook (AI)" di track Media: teks, warna, posisi & durasi sama dengan hook lama.
// Hook lama dimatikan supaya tidak tampil dobel; tersimpan saat clip disimpan / dirender.
function migrateHook() {
  const H = E.hook;
  if (!H?.enabled || !String(H.text || '').trim()) return;
  const [t0, t1] = E.trim;
  const m = { id: Math.random().toString(36).slice(2, 10), file: '', name: 'Grafik ' + GFX.hook.name, s: round3(t0),
    e: round3(Math.min(t1, t0 + Math.min(H.duration || 3.5, Math.max(0.5, t1 - t0 - 0.1)))), x: H.x ?? 0.5, y: 0.17, w: 0.87,
    gfx: { type: 'hook', label: '', text: String(H.text).trim(), c1: '#FFFFFF', c2: (H.bg || '#FFFFFF').toUpperCase(), c3: (H.color || '#111111').toUpperCase() },
    anim: { ...GFX_ANIM }, _fromHook: true };
  E.media.push(m);
  H.enabled = false;
  E._migratedHook = true;
  // Template yang terakhir dipilih di proyek ini dipakai juga untuk clip ini (teks tetap hook clip ini)
  const look = gfxLook();
  if (look && GFX[look.type]) { setGfxLook(m, look); scheduleGfx(m, true); return; }
  // Posisi vertikal disamakan dengan tepi atas hook lama setelah tinggi grafik diketahui
  gfxCanvas(m.gfx, 1).then(c => {
    const top = H.y != null ? H.y * 1920 : (frameGeom().blur ? 330 : 230);
    m.y = Math.round((top + (c.height - 36) / 2) / 1920 * 1e4) / 1e4;
    draw();
  });
  scheduleGfx(m, true);
}

// Kotak ketik di atas grafik: setiap ketikan langsung memperbarui grafik. Enter = selesai, Shift+Enter = baris baru, Esc = batal
let gfxEd = null;
function startGfxEdit(i) {
  const m = E.media[i];
  if (!m?.gfx || gfxEd) return;
  pause();
  if (sel.type !== 'media' || sel.i !== i) select({ type: 'media', i });
  const el = $('mlayer').querySelector(`.mimg[data-mi="${i}"]:not([hidden])`);
  if (!el) return toast('Taruh playhead di dalam durasi grafik untuk mengedit langsung.');
  const o = $('ovl').getBoundingClientRect(), r = el.getBoundingClientRect(), orig = m.gfx.text;
  const ta = document.createElement('textarea'), cnt = document.createElement('span');
  ta.className = 'gfx-ed'; ta.value = orig; ta.maxLength = 200; ta.spellcheck = false;
  cnt.className = 'gfx-ed-cnt';
  const w = Math.max(220, r.width);
  Object.assign(ta.style, { left: (r.left - o.left + (r.width - w) / 2) + 'px', top: (r.top - o.top) + 'px', width: w + 'px', minHeight: Math.max(70, r.height) + 'px' });
  const fit = () => { ta.style.height = 'auto'; ta.style.height = ta.scrollHeight + 'px'; cnt.style.left = (parseFloat(ta.style.left) + w - 62) + 'px'; cnt.style.top = (r.top - o.top + ta.offsetHeight + 6) + 'px'; };
  const upd = () => { m.gfx.text = ta.value; cnt.textContent = `${ta.value.length}/200`; fit(); changed(); scheduleGfx(m); };
  const end = () => {
    if (!gfxEd) return;
    gfxEd = null;
    ta.remove(); cnt.remove();
    renderProps();
  };
  ta.oninput = upd;
  ta.onkeydown = e => {
    e.stopPropagation();
    if (e.key === 'Escape') { ta.value = orig; upd(); end(); }
    else if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); end(); }
  };
  ta.onblur = end;
  $('ovl').append(ta, cnt);
  gfxEd = { ta };
  cnt.textContent = `${orig.length}/200`;
  fit();
  ta.focus();
  ta.select();
}

// Template grafik: default tanpa animasi; satu clip hanya punya satu template (pilih template lain = ganti template)
const GFX_ANIM = { in: 'none', out: 'none', din: 0.4, dout: 0.4 };
const GFX_LOOK_KEYS = ['type', 'label', 'c1', 'c2', 'c3', 'c4', 'c5', 'ls', 'ts', 'lh', 'tw', 'ta', 'tf', 'fill'];
// Kalimat hook clip ini (hasil AI); kalau kosong pakai judul clip
const clipHookText = () => String(E.hook?.text || '').trim() || String(project?.items?.find(x => x.iid === IID)?.title || '').trim();
// Grafik template utama clip ini (bukan bagian intro / footage)
const mainGfx = () => E.media.find(m => m.gfx && !m.ref);
// Posisi, ukuran & waktu bawaan per jenis template: hook di detik-detik awal, lainnya sepanjang clip
function gfxGeom(m, type) {
  const [t0, t1] = E.trim, pre = GFX[type], hook = /^hook/.test(type);
  Object.assign(m, { x: 0.5, y: pre.full ? 0.85 : hook ? 0.17 : 0.3, w: pre.full ? 1 : hook ? 0.87 : 0.84 });
  m.s = round3(t0);
  m.e = round3(hook ? Math.min(t1, t0 + Math.min(E.hook?.duration || 3.5, Math.max(0.5, t1 - t0 - 0.1))) : t1);
}
// Ganti template: teks & font dipertahankan; warna & label mengikuti template baru; posisi/waktu ikut jenis template kalau jenisnya berubah
function setGfxType(m, type) {
  const pre = GFX[type], g = m.gfx, sameCat = GFX[g.type]?.cat === pre.cat;
  Object.assign(g, { type, c1: pre.c1, c2: pre.c2, c3: pre.c3, label: pre.noLabel ? '' : (sameCat && g.label) || pre.label });
  if (pre.c4) Object.assign(g, { c4: pre.c4, c5: pre.c5 }); else { delete g.c4; delete g.c5; }
  if (!sameCat) { gfxGeom(m, type); delete g.ext; }
  m.name = 'Grafik ' + pre.name;
}
// Tampilan template (tanpa teks) + posisi & waktu relatif, dipakai clip lain di proyek ini
const lookKey = () => 'ed-gfx-look-' + PID;
function gfxLook() { try { return JSON.parse(localStorage.getItem(lookKey()) || 'null'); } catch { return null; } }
function lookOf(m) {
  const g = Object.fromEntries(GFX_LOOK_KEYS.filter(k => m.gfx[k] !== undefined).map(k => [k, m.gfx[k]]));
  return { ...g, x: m.x, y: m.y, w: m.w, opacity: m.opacity, anim: m.anim || null, ds: round3(m.s - E.trim[0]),
    de: m.e >= E.trim[1] - 0.3 ? null : round3(m.e - m.s) };   // de null = sampai akhir clip
}
function setGfxLook(m, look, trim = E.trim) {
  const { x, y, w, opacity, anim, ds, de, ...g } = look;
  GFX_LOOK_KEYS.forEach(k => delete m.gfx[k]);
  Object.assign(m.gfx, g);
  Object.assign(m, { x, y, w, anim: anim ? { ...anim } : { ...GFX_ANIM }, name: 'Grafik ' + (GFX[g.type]?.name || '') });
  if (opacity != null) m.opacity = opacity;
  const [t0, t1] = trim;
  m.s = round3(Math.min(t1 - 0.5, t0 + Math.max(0, ds || 0)));
  m.e = round3(de == null ? t1 : Math.min(t1, m.s + Math.max(0.5, de)));
}

function addGraphic(type) {
  if (!E || !$('cv')) return toast('Tunggu editor selesai dimuat.');
  if (isLocked('media')) return toast('Track Media sedang dikunci.');
  const cur = sel.type === 'media' && E.media[sel.i]?.gfx && !E.media[sel.i].ref ? E.media[sel.i] : mainGfx();
  pause();
  if (cur) {
    // Sudah ada template: ganti, bukan menambah
    setGfxType(cur, type);
    changed(); select({ type: 'media', i: E.media.indexOf(cur) }); renderTimeline(); scheduleGfx(cur, true);
    return syncGfxOthers(cur);
  }
  const pre = GFX[type];
  const m = { id: Math.random().toString(36).slice(2, 10), file: '', name: 'Grafik ' + pre.name, s: 0, e: 0, x: 0.5, y: 0.3, w: 0.84,
    gfx: { type, label: pre.label, text: clipHookText().slice(0, 200) || pre.text, c1: pre.c1, c2: pre.c2, c3: pre.c3, ...(pre.c4 ? { c4: pre.c4, c5: pre.c5 } : {}) },
    anim: { ...GFX_ANIM } };
  gfxGeom(m, type);
  E.media.push(m);
  if (E.hook) E.hook.enabled = false;   // hook lama tidak tampil dobel
  changed();
  select({ type: 'media', i: E.media.length - 1 });
  scheduleGfx(m, true);
  syncGfxOthers(m);
}

// Template yang dipilih ikut terpasang di clip lain proyek ini, masing-masing dengan kalimat hook clipnya sendiri.
// Berjalan di latar (ditunda sebentar supaya klik beruntun hanya diterapkan sekali); pilihan terbaru membatalkan yang lama.
let gfxSyncT = null, gfxSyncGen = 0;
function syncGfxOthers(m) {
  try { localStorage.setItem(lookKey(), JSON.stringify(lookOf(m))); } catch {}
  clearTimeout(gfxSyncT);
  gfxSyncT = setTimeout(() => runGfxSync(lookOf(m), E.trim), 1200);
}
async function runGfxSync(look, srcTrim) {
  const gen = ++gfxSyncGen, others = (project?.items || []).filter(it => it.iid !== IID);
  let n = 0;
  for (const it of others) {
    if (gen !== gfxSyncGen) return;
    try {
      const d = await api('edit_get', null, `&id=${PID}&iid=${it.iid}&peek=1`);
      const T = d.edit;
      if (!T) continue;   // editor clip itu belum siap: template dipasang saat clip dibuka
      T.media = T.media || [];
      const main = T.media.find(x => x.gfx && !x.ref);
      const text = (main?.gfx.text || String(T.hook?.text || '').trim() || String(it.title || '').trim()).slice(0, 200);
      if (!text) continue;
      const c = main || { id: Math.random().toString(36).slice(2, 10), file: '', gfx: { text } };
      setGfxLook(c, look, T.trim);
      c.gfx.text = text;
      // Satu template per clip: grafik template lain di clip itu dilepas
      T.media = T.media.filter(x => !x.gfx || x.ref || x === c);
      if (!main) T.media.push(c);
      if (T.hook) T.hook.enabled = false;
      // Banner selebar layar: tepi atas sama dengan clip ini, pita dihitung ulang untuk teks clip tujuan
      if (GFX[c.gfx.type]?.full) {
        const top = look.y * 1920 - (await gfxCanvas({ ...c.gfx, ext: 0 }, 1)).height * (1080 * look.w / (GFX_W + 36)) / 2;
        await fitBand(c, top);
      }
      c.file = await gfxFile(c.gfx);
      if (gen !== gfxSyncGen) return;
      await api('edit_save', { id: PID, iid: it.iid, edit: T });
      vcache.delete(it.iid); peeked.delete(it.iid);
      n++;
    } catch {}
  }
  if (n && gen === gfxSyncGen) toast(`Template grafik juga dipasang di ${n} clip lain, dengan hook masing-masing`);
}

async function renderGfxList() {
  const cats = [...new Set(Object.values(GFX).map(g => g.cat))];
  $('gfx-grid').innerHTML = cats.map(c => `<div class="fx-sec">${esc(c)}</div>` + Object.entries(GFX).filter(([, g]) => g.cat === c)
    .map(([k, g]) => `<button class="gfxt" data-gfx-add="${k}"><span class="gfx-prev" id="gp-${k}"></span><span>${esc(g.name)}</span></button>`).join('')).join('');
  for (const [k, g] of Object.entries(GFX)) {
    const c = await gfxCanvas({ type: k, ...g }, 0.6);
    $('gp-' + k)?.appendChild(c);
  }
}
$('gfx-grid').addEventListener('click', e => { const b = e.target.closest('[data-gfx-add]'); if (b) addGraphic(b.dataset.gfxAdd); });
renderGfxList();

// Panel kanan untuk grafik terpilih: teks, warna, waktu, ukuran
// Gambar kecil tiap template (dibuat sekali) untuk pilihan template di panel kanan
const gfxThumbs = {};
async function ensureGfxThumbs() {
  for (const [k, t] of Object.entries(GFX)) {
    if (gfxThumbs[k]) continue;
    gfxThumbs[k] = (await gfxCanvas({ type: k, ...t, text: t.cat === 'Teks hook' ? 'Teks hook kamu di sini' : t.text }, 0.4)).toDataURL('image/png');
    document.querySelectorAll(`[data-gthumb="${k}"]`).forEach(el => { el.innerHTML = `<img src="${gfxThumbs[k]}" alt="">`; });
  }
}
ensureGfxThumbs();

function gfxPanelHtml(m) {
  const g = m.gfx, pre = GFX[g.type] || GFX.clipnews;
  // Warna label kosong (grafik lama) = ikut warna kartu / judul
  const cv = k => g[k] || (k === 'c4' ? g.c1 : k === 'c5' ? g.c3 : '#FFFFFF');
  const col = (k, n) => `<div class="ts-c"><label>${n}</label><div class="ts-f hex"><label class="sw" style="--c:${cv(k)}"><input type="color" data-gfxc="${k}" value="${cv(k).toLowerCase()}"></label>
    <input data-gfxh="${k}" value="${cv(k).slice(1)}" maxlength="7" spellcheck="false" aria-label="Kode warna"><span class="u">Hex</span></div></div>`;
  return `<div class="card p-card"><div class="p-sec">
    <h3><span>Grafik · ${esc(pre.name)}</span> <button class="back-link" data-p="back">← Kembali</button></h3>
    <div class="p-field" style="margin-top:0"><div class="p-label"><label>Template</label></div>
      <div class="gfx-pick">${Object.entries(GFX).filter(([, t]) => t.cat === pre.cat).map(([k, t]) => `<button type="button" class="gpk${k === g.type ? ' on' : ''}" data-gfx-type="${k}" title="${esc(t.name)}">
        <span class="gpk-img" data-gthumb="${k}">${gfxThumbs[k] ? `<img src="${gfxThumbs[k]}" alt="">` : ''}</span><span class="gpk-nm">${esc(t.name)}</span></button>`).join('')}</div></div>
    ${pre.noLabel ? '' : `<div class="p-field"><div class="p-label"><label>Label</label><span class="cnt">${g.label.length}/40</span></div><input class="input p-input" data-gfx="label" value="${esc(g.label)}" maxlength="40" spellcheck="false"></div>
      <div class="p-row"><label>Ukuran label</label><input type="range" min="60" max="200" step="5" data-gfx="ls" value="${g.ls ?? 100}"><span class="val">${g.ls ?? 100}%</span></div>`}
    <div class="p-field"><div class="p-label"><label>${pre.noLabel ? 'Teks hook' : 'Judul'}</label><span class="cnt">${g.text.length}/200</span></div><textarea class="input p-input" data-gfx="text" rows="3" maxlength="200" spellcheck="false">${esc(g.text)}</textarea>
      ${pre.rich ? '<div class="p-hint">Apit kata dengan <b>*tanda bintang*</b> untuk warna sorotan, mis. *sahabat sendiri*. Label = baris sumber di bawah.</div>' : ''}</div>
    <div class="p-field"><div class="p-label"><label>Font teks</label></div>
      <button type="button" class="ts-f fpk-btn${fpkOpen === 'gfx' ? ' on' : ''}" data-fpk="gfx" aria-expanded="${fpkOpen === 'gfx'}" style='font-family:${g.tf ? fontFamily(g.tf) : '"Clip Montserrat", sans-serif'}'>
        <span>${g.tf ? esc(fontLabel(g.tf)) : 'Bawaan template'}</span>${ic('chevron-down').replace('class="i"', 'class="i chev"')}</button>
      ${fpkOpen === 'gfx' ? fontListHtml('gfx', { font: g.tf || '' }) : ''}</div>
    <div class="gt-row">
      <div class="gt-al">${[['', 'Bawaan', 'reset'], ['left', 'Rata kiri', 'align-left'], ['center', 'Rata tengah', 'align-center'], ['right', 'Rata kanan', 'align-right']].map(([v, t, i]) =>
        `<button type="button" class="tb ${(g.ta || '') === v ? 'on' : ''}" data-gfx-align="${v}" title="${t}">${ic(i)}</button>`).join('')}</div>
      <div class="ts-f gt-wt"><select data-gfx="tw" aria-label="Ketebalan teks">${[['', 'Tebal bawaan'], ...(g.tf ? FONTS.find(f => f.k === g.tf)?.w || [] : [400, 500, 600, 700, 800, 900]).map(w => [String(w), WEIGHT_NAMES[w]])].map(([v, t]) =>
        `<option value="${v}" ${(g.tw || '') === v ? 'selected' : ''}>${t}</option>`).join('')}</select>${ic('chevron-down').replace('class="i"', 'class="i chev"')}</div>
    </div>
    <div class="p-row"><label>Ukuran teks</label><input type="range" min="60" max="160" step="5" data-gfx="ts" value="${g.ts ?? 100}"><span class="val">${g.ts ?? 100}%</span></div>
    <div class="p-row"><label>Jarak baris</label><input type="range" min="70" max="160" step="5" data-gfx="lh" value="${g.lh ?? 100}"><span class="val">${g.lh ?? 100}%</span></div>
    <div class="ts-stack" style="margin-top:16px">${pre.cn[0] ? col('c1', pre.cn[0]) : ''}${col('c2', pre.cn[1])}${col('c3', pre.cn[2])}${pre.cn[3] && !pre.noLabel ? col('c4', pre.cn[3]) + col('c5', pre.cn[4]) : ''}</div>
    ${m.ref ? refNoteHtml : `<div class="p-row" style="margin-top:16px"><label>Mulai</label>${stepper('ms', m.s)}</div>
    <div class="p-row"><label>Selesai</label>${stepper('me', m.e)}</div>`}
    <div class="p-row"><label>Ukuran</label><input type="range" min="0.3" max="1" step="0.01" data-k="media.${sel.i}.w" value="${m.w}"><span class="val">${Math.round(m.w * 100)}%</span></div>
    ${pre.full ? `<label class="p-check"><input type="checkbox" data-gfxfill ${g.fill !== false ? 'checked' : ''}> Pita sampai bawah layar
      <span class="tip" data-tip="Pita memanjang sampai tepi bawah layar di posisi mana pun grafik diletakkan, jadi tidak ada ruang kosong di bawahnya.">?</span></label>` : ''}
    <div class="p-row"><label>Opacity</label><input type="range" min="0" max="100" step="1" data-k="media.${sel.i}.opacity" value="${m.opacity ?? 100}"><span class="val">${m.opacity ?? 100}%</span></div>
    <div class="p-actions">
      <button class="btn sm" data-p="media-center">${ic('align-center')} Ke tengah</button>
      <button class="btn sm danger" data-p="media-remove">${ic('trash')} Hapus grafik</button>
    </div>
  </div></div>`;
}

/* ---------- Kontrol track timeline: sembunyikan / bisukan / kunci ---------- */
const TRACKS = [['media', 'trk-media', 'Media'], ['text', 'trk-text', 'Teks'], ['fx', 'trk-fx', 'Efek'], ['video', 'trk-video', 'Video'], ['sub', 'trk-sub', 'Subtitle'], ['music', 'music', 'Musik'], ['sfx', 'volume', 'SFX']];
const trk = k => (E.tracks[k] ||= {});
const isHidden = k => k === 'sub' ? !E.subtitles : !!E.tracks?.[k]?.hide;
const isLocked = k => !!E.tracks?.[k]?.lock;
const isMuted = () => !!E.tracks?.video?.mute;
const musicMuted = () => !!E.tracks?.music?.mute;
// Track dari jenis pilihan
// Urutan lapisan di atas video, paling atas dulu: teks (+hook), subtitle, media & grafik
const layerOrder = () => {
  const o = (E.layers || []).filter(k => ['text', 'sub', 'media'].includes(k));
  ['text', 'sub', 'media'].forEach(k => { if (!o.includes(k)) o.push(k); });
  return o;
};
// Tumpukan di preview mengikuti urutan lapisan
function applyLayerOrder() {
  if (!$('mlayer')) return;
  layerOrder().forEach((k, i) => {
    const z = (3 - i) * 4;
    if (k === 'media') $('mlayer').style.zIndex = z;
    if (k === 'sub') { $('sub').style.zIndex = z; if ($('kcv')) $('kcv').style.zIndex = z; }
    if (k === 'text') { $('tlayer').style.zIndex = z + 1; $('hook').style.zIndex = z; }
  });
}
const selTrack = type => ({ media: 'media', text: 'text', effect: 'fx', line: 'sub', video: 'video', footage: 'video', transition: 'video', music: 'music', sfx: 'sfx' })[type];

function renderTrackCtl() {
  const G = $('tl-gut');
  if (!G || !E) return;
  G.querySelectorAll('.trk').forEach(row => {
    const k = row.dataset.trk, name = TRACKS.find(x => x[0] === k)[2];
    row.classList.toggle('off', isHidden(k) || (k === 'music' && musicMuted()) || (k === 'sfx' && sfxMuted()));
    row.querySelectorAll('.tc').forEach(b => {
      const c = b.dataset.tc, on = c === 'hide' ? isHidden(k) : c === 'mute' ? (k === 'music' ? musicMuted() : k === 'sfx' ? sfxMuted() : isMuted()) : isLocked(k);
      b.classList.toggle('on', on);
      b.innerHTML = ic(c === 'hide' ? (on ? 'eye-off' : 'eye') : c === 'mute' ? (on ? 'volume-x' : 'volume') : (on ? 'lock' : 'unlock'));
      b.setAttribute('aria-label', { hide: on ? `Tampilkan ${name}` : `Sembunyikan ${name}`, mute: on ? `Bunyikan ${name}` : `Bisukan ${name}`, lock: on ? `Buka kunci ${name}` : `Kunci ${name}` }[c]);
      b.title = b.getAttribute('aria-label');
    });
  });
  const flags = TRACKS.flatMap(([k]) => [isHidden(k) ? 'h-' + k : '', isLocked(k) ? 'l-' + k : '']).concat(isMuted() ? ['m-audio'] : [], musicMuted() ? ['m-music'] : [], sfxMuted() ? ['m-sfx'] : []).filter(Boolean);
  $('tl').className = ['sc-inner', ...flags].join(' ');
  applyLayerOrder();
  $('phone').className = ['phone', ...flags.filter(f => f.startsWith('l-')), fp ? 'foot' : ''].filter(Boolean).join(' ');
}

function bindTrackCtl() {
  $('tl-gut').addEventListener('click', e => {
    const b = e.target.closest('[data-tc]');
    if (!b || !E) return;
    const k = b.closest('.trk').dataset.trk, c = b.dataset.tc;
    if (c === 'hide' && k === 'sub') E.subtitles = !E.subtitles;
    else if (c === 'mute' && k === 'music') trk('music').mute = !musicMuted();
    else if (c === 'mute' && k === 'sfx') trk('sfx').mute = !sfxMuted();
    else if (c === 'mute') trk('video').mute = !isMuted();
    else trk(k)[c] = !trk(k)[c];
    changed();
    // Item terpilih di track yang baru dikunci / disembunyikan dilepas
    if ((c === 'lock' || c === 'hide') && selTrack(sel.type) === k && (isLocked(k) || isHidden(k))) select({ type: 'clip' });
    else { renderTimeline(); renderProps(); }
    applyVolume();
    draw();
  });
}

/* ---------- Animasi masuk / keluar (teks, hook, media). Rumus sama dengan anim_phase() di subtitles.php ---------- */
const ANIMS = [['none', 'Tidak ada'], ['fade', 'Fade'], ['up', 'Geser naik'], ['down', 'Geser turun'], ['left', 'Geser kiri'], ['right', 'Geser kanan'], ['zoom', 'Zoom'], ['pop', 'Pop']];
const ANIM_NONE = { in: 'none', out: 'none', din: 0.4, dout: 0.4, text: 'none', tdur: 1, hl: '#FFD400', blur: false, smooth: false };
/* Efek teks per huruf / kata (teks tambahan). Rumus sama dengan text_fx_body() di subtitles.php: hanya transparansi & warna
   yang berubah, jadi susunan baris tidak bergeser. tdur = lama ketik / munculnya semua kata; sorot = merata sepanjang teks. */
const TEXT_FX = [['none', 'Tidak ada'], ['type', 'Ketik'], ['word', 'Kata per kata'], ['karaoke', 'Sorot per kata']];
function textFxHtml(text, fx) {
  const units = fx === 'type' ? Array.from(text) : text.split(/(\s+)/).filter(u => u !== '');
  return units.map(u => u.trim() ? `<span class="fxu">${esc(u)}</span>` : esc(u)).join('');
}
function paintTextFx(sp, c, time) {
  const a = c.anim, units = sp.querySelectorAll('.fxu'), n = Math.max(1, units.length), lt = time - c.s;
  if (a.text === 'karaoke') {
    const step = Math.max(0.05, (c.e - c.s) / n), cur = Math.floor(lt / step);
    units.forEach((u, k) => { u.style.color = k === cur ? a.hl : ''; });
    return;
  }
  const step = (a.tdur || 1) / n, fade = a.text === 'word';
  units.forEach((u, k) => {
    const p = lt - k * step;
    u.style.opacity = fade ? tProg(p, 0, 120, a.smooth) : p >= 0 ? 1 : 0;
  });
}
const HOOK_ANIM = { in: 'fade', out: 'fade', din: 0.15, dout: 0.25 };
function animPhase(kind, phase, p) {
  p = Math.min(1, Math.max(0, p));
  const D = 140, S = Math.sin, H = Math.PI / 2;
  if (phase === 'in') {
    const e = 1 - (1 - p) ** 3;
    return {
      fade: [0, 0, 1, p], up: [0, D * (1 - e), 1, p], down: [0, -D * (1 - e), 1, p], left: [D * (1 - e), 0, 1, p], right: [-D * (1 - e), 0, 1, p],
      zoom: [0, 0, 0.6 + 0.4 * e, p], pop: [0, 0, p < 0.6 ? Math.max(0.05, 1.12 * S(p / 0.6 * H)) : 1.12 - 0.12 * S((p - 0.6) / 0.4 * H), Math.min(1, p * 3)],
    }[kind] || [0, 0, 1, 1];
  }
  const i = p ** 3;
  return {
    fade: [0, 0, 1, 1 - p], up: [0, -D * i, 1, 1 - p], down: [0, D * i, 1, 1 - p], left: [-D * i, 0, 1, 1 - p], right: [D * i, 0, 1, 1 - p],
    zoom: [0, 0, 1 - 0.4 * i, 1 - p], pop: [0, 0, p < 0.35 ? 1 + 0.1 * S(p / 0.35 * H) : Math.max(0.05, 1.1 * (1 - S((p - 0.35) / 0.65 * H))), p < 0.6 ? 1 : 1 - (p - 0.6) / 0.4],
  }[kind] || [0, 0, 1, 1];
}
function animDur(a, len) {
  let din = a && a.in !== 'none' ? a.din : 0, dout = a && a.out !== 'none' ? a.dout : 0;
  if (din + dout > len * 0.9 && din + dout > 0) { const f = len * 0.9 / (din + dout); din *= f; dout *= f; }
  return [din, dout];
}
// Keadaan animasi item (s..e) pada waktu time: { dx, dy, s, a }
function animAt(a, s, e, time) {
  if (!a || (a.in === 'none' && a.out === 'none')) return { dx: 0, dy: 0, s: 1, a: 1 };
  const [din, dout] = animDur(a, e - s);
  const I = din > 0 ? animPhase(a.in, 'in', (time - s) / din) : [0, 0, 1, 1];
  const O = dout > 0 ? animPhase(a.out, 'out', (time - (e - dout)) / dout) : [0, 0, 1, 1];
  return { dx: I[0] + O[0], dy: I[1] + O[1], s: I[2] * O[2], a: Math.max(0, Math.min(1, I[3] * O[3])) };
}
// Transform CSS animasi (jarak dalam px layar 1080)
const animCss = A => `translate(calc(${A.dx}px * var(--k)), calc(${A.dy}px * var(--k))) scale(${A.s})`;

let E = null;            // resep edit
let fp = null;           // footage yang sedang tampil di preview: { i: indeks E.footage, at: detik } — null = clip utama
let playing = false, lastTick = 0;
const fvid = document.createElement('video');  // pemutar footage video
fvid.preload = 'auto'; fvid.playsInline = true;
fvid.onloadeddata = fvid.onseeked = () => draw();
fvid.onloadedmetadata = () => { if (fp && E?.footage[fp.i]?.file === fvid.dataset.f) fvid.currentTime = fp.at; };
let meta = null;         // item & video
let t = 0;               // waktu absolut (detik video sumber)
let sel = { type: 'clip' };
let dirty = false, pps = 10, cv, ctx, looping = false;
let loadSeq = 0;          // menandai pemuatan terbaru, supaya respons clip sebelumnya diabaikan

/* ---------- Memuat data ---------- */

async function load(retry) {
  const seq = ++loadSeq, iid = IID;
  let d;
  try { d = await api('edit_get', null, `&id=${PID}&iid=${iid}${retry === true ? '&retry=1' : ''}`); }
  catch (err) {
    if (seq !== loadSeq) return; $('wait')?.remove(); $('main').innerHTML = `<div class="card prep"><b>Gagal memuat editor</b><p class="muted">${esc(err.message)}</p></div>`; return; }
  if (seq !== loadSeq) return;
  document.title = d.item.title + ' · Editor · BisaClip';
  if (!d.preparing) $('wait')?.remove();
  if (d.error) {
    $('main').innerHTML = `<div class="card prep"><b>Editor gagal disiapkan</b><p class="muted small">${esc(d.error)}</p>
      <button class="btn primary" onclick="load(true)">${ic('refresh')} Coba lagi</button></div>`;
    return;
  }
  if (d.preparing) {
    renderLoading(d);
    setTimeout(() => { if (seq === loadSeq) load(); }, 1500);
    return;
  }
  adoptEdit(d);
  vid.src = d.proxy;
  buildUI();
  setDirty(!!E._migratedHook);
  showPending(d);
  upgradeProxy(IID);
}

/* Preview lama (proxy 480p) dibuat ulang 720p di latar belakang (±10–20 dtk), lalu video preview ditukar tanpa mengubah posisi putar.
   Server menolak dengan cepat kalau proxy sudah tajam / video sumber lokal tidak ada. Sekali per clip per sesi. */
const proxyChecked = new Set();
async function upgradeProxy(iid) {
  if (proxyChecked.has(iid) || !E || E.pending) return;
  proxyChecked.add(iid);
  let d;
  try { d = await api('edit_proxy_hd', { id: PID, iid }); } catch { return; }
  if (!d.upgraded) return;
  vcache.delete(iid); peeked.delete(iid);
  if (iid !== IID) return; // sudah pindah clip: versi tajam dipakai saat clip ini dibuka lagi
  const keep = t, wasPlaying = playing;
  pause();
  vid.addEventListener('loadeddata', () => { seek(keep); if (wasPlaying) play(); }, { once: true });
  vid.src = d.proxy;
  toast('Preview ditingkatkan ke 720p');
}

function adoptEdit(d) {
  E = d.edit;
  meta = d;
  if (E.layout === 'split') { E.layout = 'speaker'; E.split = { enabled: true, min: 0.75 }; }
  E.split = E.split || { enabled: false, min: 0.75 };
  E.overlay = { enabled: false, file: '', pos: 'bottom', size: 0.9, offset: 0, ...(E.overlay || {}) };
  E.split.early = E.split.early ?? 0;
  if (E.style.x === undefined) E.style.x = 0.5;
  E.media = E.media || [];
  E.volume = E.volume ?? 100;
  // Fitur keyframe volume dihapus: keyframe lama dibuang, volume kembali ke nilai tetap clip / musik
  E.volKf = [];
  (E.music || []).forEach(m => delete m.kf); // E.music baru dilengkapi di bawah
  E.opening = E.opening && OPENINGS[E.opening.type] ? E.opening : null;
  itCfg = itFrame = null; // menu Intro mulai dari pengaturan clip ini
  E.sfx = E.sfx || [];
  E.texts = E.texts || [];
  E.cuts = E.cuts || [];
  E.gaps = E.gaps || [];
  E.effects = (E.effects || []).filter(fx => EFFECTS[fx.type]);
  E.footage = E.footage || [];
  E.music = E.music || [];
  E.caption = E.caption === 'kinetic' ? 'kinetic' : 'subtitle';
  E.frame = { ratio: 'full', radius: 0, ...(E.frame && !Array.isArray(E.frame) ? E.frame : {}) };
  // Resep lama (sama dengan upgrade_layout di editor_lib.php): crop tengah → ikuti pembicara mati, background blur → bingkai 16:9 + blur,
  // letterbox → bingkai 3:4, streaming game → jenis streaming game + split 70:30, split screen otomatis → split 50:50
  if (E.layout === 'blur') { if (E.frame.ratio === 'full') E.frame.ratio = '16:9'; E.frame.bg = 'blur'; }
  else if (E.layout === 'letterbox' && E.frame.ratio === 'full') E.frame.ratio = '3:4';
  else if (E.layout === 'crop_center') E.follow = false;
  else if (E.layout === 'gaming' && E.frame.ratio !== 'split') Object.assign(E.frame, { ratio: 'split', split: 70 }), E.kind = 'gaming';
  // Split 2 orang versi lama (layout Split di clip podcast) → layout Full + Auto split
  else if (E.frame.ratio === 'split' && E.layout !== 'gaming' && E.kind !== 'gaming') { E.frame.ratio = 'full'; E.split.enabled = true; }
  E.kind = E.kind === 'gaming' || (E.kind == null && E.layout === 'gaming') ? 'gaming' : 'podcast';
  E.follow = E.follow !== false;
  E.frame.bg = E.frame.bg === 'blur' ? 'blur' : 'solid';
  E.frame.mix = E.frame.mix?.file ? E.frame.mix : null;
  if (!SPLIT_RATIOS.includes(E.frame.split)) E.frame.split = E.frame.ratio === 'split' ? 70 : 50;
  syncLayout();
  E.kinetic = { beats: [], y: 0.3, x: 0.5, ...(E.kinetic && !Array.isArray(E.kinetic) ? E.kinetic : {}) };
  E._klayout = null;
  E.layers = ['text', 'sub', 'media']; // urutan lapisan tetap (fitur atur layer dihapus)
  E.tracks = E.tracks && !Array.isArray(E.tracks) ? E.tracks : {};
  E.transitions = E.transitions && !Array.isArray(E.transitions) ? E.transitions : {};
  fp = null; fvid.pause();
  migrateOverlay(E);
  E.style = { bold: false, italic: false, underline: false, spacing: 1, align: 'center', opacity: 100, stroke: '#000000', stroke_w: 7, ...E.style };
  normSubStyle(E.style);
  E.hook = { anim: { ...HOOK_ANIM }, size: 68, bold: false, italic: false, underline: false, spacing: 0, align: 'center', color: '#111111', opacity: 100,
    bg: '#FFFFFF', bg_opacity: 100, ...E.hook };
  t = E.trim[0];
  migrateHook();
  resetHistory();
  document.title = d.item.title + ' · Editor · BisaClip';
}

// Selama persiapan berjalan: tampilkan banner dan ambil subtitle/segmen kamera begitu tersedia
function showPending(d) {
  $('pending').hidden = !E.pending;
  if (!E.pending) return;
  $('pending-stage').textContent = d.prep_stage ? '(' + d.prep_stage.replace('Menyiapkan editor: ', '') + ')' : '';
  const seq = loadSeq;
  setTimeout(() => pollPending(seq), 2500);
}

async function pollPending(seq) {
  if (seq !== loadSeq) return;
  let d;
  try { d = await api('edit_get', null, `&id=${PID}&iid=${IID}`); } catch { return setTimeout(() => pollPending(seq), 4000); }
  if (seq !== loadSeq) return;
  if (!d.edit) return setTimeout(() => pollPending(seq), 2500);
  const gotLines = !E.lines.length && d.edit.lines.length;
  E.lines = d.edit.lines;
  E.segments = d.edit.segments;
  if ('cam' in d.edit) E.cam = d.edit.cam;
  // Subtitle baru datang dari server: riwayat undo dimulai ulang supaya undo tidak menghapus subtitle tersebut
  if (gotLines) resetHistory();
  E.pending = d.edit.pending;
  if (gotLines || !E.pending) {
    renderTimeline();
    if (sel.type === 'clip') renderProps();
    draw();
  }
  if (!E.pending) toast('Subtitle & deteksi wajah siap');
  showPending(d);
}

/* ---------- Layar loading masuk editor ---------- */

function renderLoading(d) {
  const j = d.job || {}, stage = j.stage || '', src = d.source;
  const srcDone = src && src.status === 'done';
  const srcFailed = src && !srcDone && !ACTIVE.includes(src.status);
  const waitingSrc = stage.includes('video sumber') || (src && ACTIVE.includes(src.status));
  const steps = [
    { label: 'Unduh video sumber', state: srcDone || srcFailed ? 'done' : waitingSrc ? 'active' : 'todo',
      info: srcDone ? 'siap' : srcFailed ? 'lewat link' : src ? Math.floor(src.progress || 0) + '%' : '' },
    { label: 'Buat video preview', state: !waitingSrc && /preview|membaca/i.test(stage) ? 'active' : 'todo', info: '' },
    { label: 'Buka editor', state: 'todo', info: '' },
  ];
  // Langkah sebelum langkah aktif dianggap selesai
  const ai = steps.findIndex(s => s.state === 'active');
  steps.forEach((s, k) => { if (ai > k) s.state = 'done'; });
  const pct = waitingSrc && src ? (src.progress || 0) * 0.8 : ai === 1 ? 85 : 5;
  const queued = /Menunggu giliran|Menunggu…|persiapan editor clip ini/.test(stage) || j.status === 'queued';
  $('t-status').textContent = 'Menunggu clip selesai diproses…';
  // Kerangka editor (preview, panel properti, timeline) dibuat sekali; modal tunggu diperbarui tiap polling
  if (!$('main').querySelector('.sk')) $('main').innerHTML = skeletonHtml();
  const items = project?.items || [];
  const ready = items.filter(it => pjobs[it.job_id]?.status === 'done').length;
  let w = $('wait');
  if (!w) {
    w = document.createElement('div');
    w.className = 'wait';
    w.id = 'wait';
    document.body.appendChild(w);
  }
  w.innerHTML = `<div class="card prep" role="dialog" aria-modal="true" aria-labelledby="w-title">
    <h2 id="w-title">Clip masih diproses</h2>
    <p class="prep-sub">${esc(d.item.title)}</p>
    <div class="w-info"><span class="spin"></span><span>Tunggu hingga minimal 1 clip selesai diproses. Editor terbuka otomatis begitu clip ini siap.
      ${items.length ? `<br><b>${ready} dari ${items.length} clip</b> sudah selesai.` : ''}</span></div>
    <ol class="steps">${steps.map(s => `<li class="${s.state}"><span class="dot">${s.state === 'done' ? '✓' : ''}</span>
      <span>${s.label}</span><small>${esc(s.info)}</small></li>`).join('')}</ol>
    <div class="progress"><i style="width:${Math.max(4, Math.min(95, pct))}%"></i></div>
    <div class="note">${queued && !waitingSrc ? 'Menunggu giliran — clip ini didahulukan.' : waitingSrc
      ? 'Video sumber diunduh sekali per video. Setelah itu semua clip & editor jauh lebih cepat.'
      : 'Hampir selesai. Subtitle & deteksi wajah akan menyusul di dalam editor.'}</div>
    <div class="w-foot"><a class="btn" href="studio.php?id=${encodeURIComponent(PID)}">${ic('arrow-left')} Kembali ke Studio</a></div>
  </div>`;
}

// Tampilan editor kosong (tidak bisa diklik) di belakang modal tunggu, mengikuti tata letak editor asli
function skeletonHtml() {
  const bars = n => Array.from({ length: n }, () => '<div class="sk-bar"></div>').join('');
  return `<div class="ed sk" aria-hidden="true">
      <div class="card stage"><div class="phone"><span class="spin"></span></div>
        <div class="transport"><span></span><div class="tp-btns">
          <button class="btn sm ghost tp-skip" disabled>${ic('skip-back')}</button>
          <button class="btn sm ghost tp-skip" disabled>${ic('play')}</button>
          <button class="btn sm ghost tp-skip" disabled>${ic('skip-forward')}</button></div><span></span></div></div>
      <div class="props">${bars(8)}</div>
    </div>
    <div class="card tlbar sk" aria-hidden="true"><div class="sk-trk">${bars(4)}</div></div>`;
}

/* ---------- Kerangka UI ---------- */

function buildUI() {
  $('main').innerHTML = `
    <div class="pending" id="pending" hidden><span class="spin"></span><span><b>Subtitle & deteksi wajah sedang dibuat.</b>
      Sambil menunggu, kamu sudah bisa memutar video, trim, dan mengatur gaya. <span id="pending-stage"></span></span></div>
    <div class="ed">
      <div class="card stage" id="stage">
        <div class="pv-view" id="pv-view">
        <div class="phone" id="phone">
          <canvas id="cv" width="540" height="960"></canvas>
          <div class="ovl" id="ovl"><div class="mlayer" id="mlayer"></div><div class="tlayer" id="tlayer"></div><div class="hookbox" id="hook"></div><div class="sub" id="sub"></div><canvas class="kcv" id="kcv"></canvas><div class="kbox" id="kbox" hidden></div>
            <div class="guides" id="guides"><div class="safe"></div><i class="gl v l"></i><i class="gl v r"></i><i class="gl v cx"></i><i class="gl h t"></i><i class="gl h b"></i><i class="gl h cy"></i></div>
            <div class="mixbox" id="mixbox" hidden><i data-mc="tl"></i><i data-mc="tr"></i><i data-mc="bl"></i><i data-mc="br"></i></div>
            <div class="selbox" id="selbox" hidden><i></i><i></i><i></i><i></i>
              <div class="seltool"><button data-st="edit" title="Edit teks langsung di preview (klik dua kali)">${ic('pencil')} Edit</button><span class="sep"></span><button class="del" data-st="del" title="Hapus (Delete)">${ic('trash')} Hapus</button></div></div></div>
          <!-- Opening di atas semua lapisan (video, media, teks, subtitle), di luar .ovl supaya tidak ikut ter-zoom -->
          <div class="opfx" id="opfx" hidden><i class="t"></i><i class="b"></i><i class="f"></i></div>
          <div class="loading-stage" id="vload"><div class="vload-text"><span class="spin"></span>Memuat video…</div></div>
          <div class="busy" id="busy"><span class="ring"></span><b id="busy-title"></b><small id="busy-sub"></small><div class="bar2"><i></i></div></div>
        </div>
        </div>
        <div class="transport">
          <span class="time" id="time"></span>
          <div class="pv-zoom">
            <button class="btn sm ghost" id="pz-out" aria-label="Perkecil preview">−</button>
            <button class="btn sm ghost" id="pz-val" title="Kembali ke ukuran pas">Pas</button>
            <button class="btn sm ghost" id="pz-in" aria-label="Perbesar preview">+</button>
            <button class="btn sm ghost" id="pz-full" title="Layar penuh (&#96; atau F)" aria-label="Layar penuh">${ic('maximize')}</button>
          </div>
          <div class="tp-btns">
            <button class="btn sm ghost tp-skip" id="to-start" title="Ke awal clip">${ic('skip-back')}</button>
            <button class="btn sm ghost tp-skip" id="play" title="Putar / jeda (Spasi)">${ic('play')}</button>
            <button class="btn sm ghost tp-skip" id="to-end" title="Ke akhir clip">${ic('skip-forward')}</button>
          </div>
        </div>
      </div>
      <div class="props" id="props"></div>
    </div>
    <div class="card tlbar">
      <div class="tl-tools">
        <button class="tl-tool" id="tool-cut" title="Potong item terpilih di posisi playhead (⌘/Ctrl + Shift + D)" disabled>${ic('scissors')}</button>
        <button class="tl-tool" id="tool-range" title="Reset rentang putar ke seluruh durasi" disabled>${ic('range')}</button>
        <span class="sep"></span>
        <button class="tl-tool" id="tool-undo" title="Urungkan (⌘/Ctrl + Z)" disabled>${ic('undo')}</button>
        <button class="tl-tool" id="tool-redo" title="Ulangi (⌘/Ctrl + Shift + Z)" disabled>${ic('redo')}</button>
        <span class="tl-hint">Pilih item, taruh playhead, lalu potong</span>
        <span class="sep"></span>
        <div class="tl-zoom">
          <button class="tl-tool" id="zoom-out" aria-label="Perkecil timeline">−</button>
          <input type="range" id="tl-zoom" min="50" max="300" step="5" value="${zoomPct()}" aria-label="Skala timeline">
          <button class="tl-tool" id="zoom-in" aria-label="Perbesar timeline">+</button>
          <span class="zv" id="zoom-val">${zoomPct()}%</span>
        </div>
      </div>
      <div class="scrub" id="tl-scroll" title="Klik/geser untuk pindah posisi · tarik ujung bar untuk trim">
        <div class="tl-gut" id="tl-gut">${TRACKS.map(([k, icon, name]) => `<div class="trk t-${k}" data-trk="${k}"><span class="ti" title="${name}">${ic(icon)}</span>
          ${k !== 'music' && k !== 'sfx' ? `<button class="tc" data-tc="hide" aria-label="Sembunyikan ${name}"></button>` : ''}<button class="tc" data-tc="lock" aria-label="Kunci ${name}"></button>${['video', 'music', 'sfx'].includes(k) ? `<button class="tc" data-tc="mute" aria-label="Bisukan ${name}"></button>` : ''}</div>`).join('')}</div>
        <div class="tl-view" id="tl-view"><div class="sc-inner" id="tl"></div></div>
      </div>
    </div>`;
  cv = $('cv');
  ctx = cv.getContext('2d');
  vid.onloadeddata = () => { $('vload')?.remove(); seek(t); };
  vid.onseeked = () => draw();
  if (vid.readyState >= 2) $('vload')?.remove();
  $('play').onclick = togglePlay;
  $('to-start').onclick = () => { pause(); preF().length ? seekV(0) : seek(E.trim[0]); };
  $('to-end').onclick = () => {
    pause();
    const f = postF().at(-1);
    if (f) setFoot(E.footage.indexOf(f), f.dur - 0.05); else seek(E.trim[1] - 0.05);
  };
  // Klik subtitle di preview = edit baris itu
  $('sub').onclick = e => { e.stopPropagation(); if (justMoved()) return; const i = activeLine(); if (i >= 0 && !E.pending && !inlineEd) { pause(); if (sel.type !== 'line' || sel.i !== i) select({ type: 'line', i }); } };
  $('sub').ondblclick = () => { const i = activeLine(); if (i >= 0 && !E.pending) { pause(); select({ type: 'line', i }); startInlineEdit(); } };
  $('hook').onclick = e => { e.stopPropagation(); if (justMoved()) return; if (!inlineEd && sel.type !== 'hook') { pause(); select({ type: 'hook' }); } };
  $('hook').ondblclick = () => { pause(); if (sel.type !== 'hook') select({ type: 'hook' }); startInlineEdit(); };
  bindTextDrag($('sub'), 'line');
  bindTextDrag($('hook'), 'hook');
  bindMediaDrag();
  bindTextLayer();
  // Tarik titik sudut kotak seleksi untuk mengubah ukuran (media & grafik: lebar; teks, hook, subtitle: ukuran huruf)
  $('selbox').addEventListener('pointerdown', e => {
    const h = e.target.closest('#selbox > i');
    if (!h) return;
    e.preventDefault();
    e.stopPropagation();
    const tg = resizeTarget();
    if (!tg) return;
    pause();
    const r = $('selbox').getBoundingClientRect(), cx = r.left + r.width / 2, cy = r.top + r.height / 2;
    const d0 = Math.max(8, Math.hypot(e.clientX - cx, e.clientY - cy)), v0 = tg.get();
    h.setPointerCapture(e.pointerId);
    document.body.classList.add('dragging');
    const move = ev => {
      tg.set(Math.max(tg.min, Math.min(tg.max, v0 * Math.hypot(ev.clientX - cx, ev.clientY - cy) / d0)));
      changed();
      draw();
    };
    const up = () => {
      h.removeEventListener('pointermove', move);
      h.removeEventListener('pointerup', up);
      h.removeEventListener('pointercancel', up);
      document.body.classList.remove('dragging');
      if (sel.type === 'media' && isFullGfx(E.media[sel.i])) scheduleGfx(E.media[sel.i], true);
      renderProps();
      renderTimeline();
    };
    h.addEventListener('pointermove', move);
    h.addEventListener('pointerup', up);
    h.addEventListener('pointercancel', up);
  });
  $('selbox').addEventListener('click', e => {
    const b = e.target.closest('[data-st]');
    if (!b) return;
    e.stopPropagation();
    if (b.dataset.st === 'edit') { if (sel.type === 'media' && E.media[sel.i]?.gfx) startGfxEdit(sel.i); else startInlineEdit(); }
    else deleteSelectedText();
  });
  // Klik di mana pun selain item (di preview, timeline, atau area kosong lain) melepas seleksi.
  // Klik item lain = pindah seleksi (ditangani handler item itu sendiri).
  // Dikecualikan: alat untuk mengedit item terpilih (panel properti, menu kiri, toolbar, penggaris & playhead, modal).
  if (!window.deselectBound) {
    window.deselectBound = true;
    const ITEMS = '#sub, #hook, .mimg, .tbox, #kbox, #selbox, .sc-lbl, .sc-media, .sc-text, .sc-fx, .sc-music, .sc-foot, .sc-tr, .sc-op, #film, [data-drag], [data-pr]';
    // #tl: timeline menangani klik area kosongnya sendiri (lihat bindTimeline)
    const KEEP = '#tl, #props, .reels, .lp-tabs, .lp-body, .tl-tools, .transport, .ruler, .playhead, .trk, .tipbox, .gfx-ed, .sty, .modal, [role=dialog], .toast, header';
    document.addEventListener('click', e => {
      if (!E || inlineEd || !e.target.isConnected || !DESELECTABLE.includes(sel.type)) return;
      if (performance.now() - tlTapAt < 300) return;
      if (e.target.closest(ITEMS) || e.target.closest(KEEP)) return;
      select({ type: sel.type === 'line' && sel.detail ? 'lines' : 'clip' });
    });
  }
  new ResizeObserver(() => { renderTimeline(); updatePhoneScale(); }).observe($('tl-scroll'));
  bindMixBox();
  new ResizeObserver(updatePhoneScale).observe($('phone'));
  bindTimeline();
  bindTimelineDrop();
  bindPvZoom();
  bindKineticDrag();
  bindTrackCtl();
  bindZoom();
  $('tool-cut').onclick = cutAtPlayhead;
  $('tool-range').onclick = resetRange;
  $('tool-undo').onclick = undo;
  $('tool-redo').onclick = redo;
  paintHistory();
  paintRangeBtn();
  bindProps();
  renderProps();
  renderTimeline();
  if (!looping) { looping = true; loop(); }
}

function setDirty(v) {
  dirty = v;
  $('t-status').textContent = v ? 'Ada perubahan yang belum disimpan' : 'Semua perubahan tersimpan';
  $('t-status').className = v ? 'dirty' : '';
  $('btn-save').disabled = !v;
  $('btn-render').disabled = !E;
  $('btn-style').disabled = !E;
}
const changed = () => { setDirty(true); noteHistory(); };

/* ---------- Undo / redo: snapshot resep edit; perubahan beruntun (geser, ketik) dikelompokkan jadi satu langkah ---------- */
const hist = { undo: [], redo: [], committed: null, timer: null, open: false };
const snapshot = () => JSON.stringify(payload());
function resetHistory() {
  Object.assign(hist, { undo: [], redo: [], committed: snapshot(), open: false });
  clearTimeout(hist.timer);
  paintHistory();
}
// Dipanggil setiap ada perubahan: langkah pertama sebuah kelompok menyimpan keadaan sebelumnya
function noteHistory() {
  if (!hist.committed || hist.applying) return;
  if (!hist.open) {
    hist.undo.push(hist.committed);
    if (hist.undo.length > 100) hist.undo.shift();
    hist.redo = [];
    hist.open = true;
  }
  clearTimeout(hist.timer);
  hist.timer = setTimeout(closeHistory, 450);
  paintHistory();
}
function closeHistory() {
  clearTimeout(hist.timer);
  if (!hist.open) return;
  hist.open = false;
  hist.committed = snapshot();
}
function applySnapshot(json) {
  const st = JSON.parse(json);
  hist.applying = true;
  pause();
  Object.assign(E, st);
  E._klayout = null;
  // Data sementara grafik (preview data URL) tidak ikut snapshot; gambar memakai file tersimpan
  sel = { type: 'clip' };
  renderProps();
  renderTimeline();
  applyVolume();
  draw();
  setDirty(true);
  hist.applying = false;
}
function undo() {
  if (!E) return;
  closeHistory();
  if (!hist.undo.length) return toast('Tidak ada yang bisa dibatalkan');
  hist.redo.push(hist.committed);
  hist.committed = hist.undo.pop();
  applySnapshot(hist.committed);
  paintHistory();
}
function redo() {
  if (!E) return;
  closeHistory();
  if (!hist.redo.length) return toast('Tidak ada yang bisa diulang');
  hist.undo.push(hist.committed);
  hist.committed = hist.redo.pop();
  applySnapshot(hist.committed);
  paintHistory();
}
function paintHistory() {
  if ($('tool-undo')) $('tool-undo').disabled = !hist.undo.length;
  if ($('tool-redo')) $('tool-redo').disabled = !hist.redo.length;
}

/* ---------- Preview ---------- */

// Zoom preview: 1 = pas dengan area; selain itu tinggi preview = tinggi pas × zoom (area bisa di-scroll)
let pvZoom = 1, pvBase = 0;
function setPvZoom(z) {
  const ph = $('phone');
  if (!ph) return;
  z = Math.round(Math.max(0.5, Math.min(3, z)) * 100) / 100;
  if (Math.abs(z - 1) < 0.01) z = 1;
  if (pvZoom === 1) pvBase = ph.getBoundingClientRect().height;
  pvZoom = z;
  ph.classList.toggle('zoomed', z !== 1);
  ph.style.setProperty('--ph', Math.round(pvBase * z) + 'px');
  $('pz-val').textContent = z === 1 ? 'Pas' : Math.round(z * 100) + '%';
  updatePhoneScale();
  draw();
}
// Preview layar penuh (tombol di kontrol zoom / tombol F, keluar dengan Esc)
const fsEl = () => document.fullscreenElement || document.webkitFullscreenElement;
function togglePvFullscreen() {
  const st = $('stage');
  if (!st) return;
  if (fsEl()) return (document.exitFullscreen || document.webkitExitFullscreen).call(document);
  setPvZoom(1);
  const req = st.requestFullscreen || st.webkitRequestFullscreen;
  if (!req) return toast('Browser ini tidak mendukung layar penuh');
  Promise.resolve(req.call(st)).catch(() => toast('Layar penuh tidak bisa dibuka'));
}
function syncPvFullscreen() {
  const b = $('pz-full');
  if (!b) return;
  const on = fsEl() === $('stage');
  b.innerHTML = ic(on ? 'minimize' : 'maximize');
  b.title = on ? 'Keluar layar penuh (` atau Esc)' : 'Layar penuh (` atau F)';
  b.setAttribute('aria-label', on ? 'Keluar layar penuh' : 'Layar penuh');
  updatePhoneScale();
  draw();
}
document.addEventListener('fullscreenchange', syncPvFullscreen);
document.addEventListener('webkitfullscreenchange', syncPvFullscreen);
function bindPvZoom() {
  $('pz-full').onclick = togglePvFullscreen;
  $('pz-in').onclick = () => setPvZoom(pvZoom * 1.25);
  $('pz-out').onclick = () => setPvZoom(pvZoom / 1.25);
  $('pz-val').onclick = () => setPvZoom(1);
  $('pv-view').addEventListener('wheel', e => {
    if (!e.ctrlKey && !e.metaKey) return;
    e.preventDefault();
    setPvZoom(pvZoom * (e.deltaY > 0 ? 1 / 1.1 : 1.1));
  }, { passive: false });
}

function updatePhoneScale() {
  const w = $('phone')?.clientWidth;
  if (!w) return;
  $('phone').style.setProperty('--k', w / 1080);
  // Resolusi canvas = ukuran tampil × devicePixelRatio (layar Retina 2×), maks. 1080×1920, supaya preview tidak buram
  const cw = Math.min(1080, Math.round(w * (devicePixelRatio || 1) / 2) * 2);
  if (cv && Math.abs(cv.width - cw) > 2) {
    cv.width = cw;
    cv.height = Math.round(cw * 16 / 9);
    ctx.imageSmoothingQuality = 'high'; // ukuran canvas baru mengatur ulang state context
    draw();
  }
}

// Sama dengan speaker_crop() di clip.php: wajah di tengah, tinggi wajah ±24% layar
function speakerCrop(seg, W, H, a = 9 / 16) {
  const minH = Math.min(H, Math.max(H * 0.5, 640));
  let ch = seg && seg.fh > 0 ? seg.fh * H / 0.24 : H;
  ch = Math.max(minH, Math.min(H, ch));
  let cw = ch * a;
  if (cw > W) { cw = W; ch = Math.min(H, W / a); }
  const cx = seg ? seg.cx : 0.5, cy = seg ? seg.cy : 0.42;
  const x = Math.max(0, Math.min(W - cw, cx * W - cw / 2));
  const y = seg && seg.fh > 0 ? Math.max(0, Math.min(H - ch, cy * H - ch * 0.36)) : (H - ch) / 2;
  return [x / W, y / H, cw / W, ch / H];
}

/* Kamera halus "ikuti pembicara": sama dengan camera_runs() / camera_expr() di clip.php.
   Satu run = satu shot kamera sumber: ukuran crop tetap, kamera bergeser ease-in-out ke pembicara berikutnya.
   Antar run (cut di video sumber) kamera langsung pindah. */
// Sama dengan clip.php: perpindahan jauh (> CAM_JUMP) = cut langsung, perpindahan kecil = geser halus
const CAM_PAN = 0.9, CAM_DEADZONE = 0.02, CAM_HOLD = 1.0, CAM_JUMP = 0.18, CAM_SNAP = 0.6;
// Sama dengan snap_segments() di clip.php: batas pergantian pembicara ditarik ke cut kamera sumber terdekat (≤ CAM_SNAP dtk)
function snapSegs(segs, cuts) {
  segs = segs.map(x => ({ ...x }));
  if (!cuts) return segs;
  for (let i = 1; i < segs.length; i++) {
    const st = segs[i].s;
    let best = null;
    for (const c of cuts) if (Math.abs(c - st) <= CAM_SNAP && c > segs[i - 1].s + 0.05 && c < segs[i].e - 0.05 && (best === null || Math.abs(c - st) < Math.abs(best - st))) best = c;
    if (best !== null) { segs[i].s = best; segs[i - 1].e = best; }
  }
  return segs;
}
let camCache = { key: null, runs: [] };
function cameraRuns(a) {
  const W = E.src.w, H = E.src.h;
  const key = [E.segments, E.shots, a, W, H];
  if (camCache.key && key.every((v, k) => v === camCache.key[k])) return camCache.runs;
  const cuts = E.shots?.length ? E.shots.map(s => s.s) : null;
  const segs = snapSegs(E.segments || [], cuts);
  const runs = [];
  let prev = null;
  segs.forEach((seg, i) => {
    const c = speakerCrop(seg, W, H, a), ch = c[3] * H;
    let hard = i === 0;
    if (!hard) hard = cuts ? cuts.some(t => Math.abs(t - seg.s) < 0.15) : Math.abs(ch - prev) / Math.max(1, prev) > 0.25;
    if (hard) runs.push({ s: seg.s, e: seg.e, segs: [seg], ch });
    else { const r = runs[runs.length - 1]; r.segs.push(seg); r.e = seg.e; r.ch = Math.max(r.ch, ch); }
    prev = ch;
  });
  for (const r of runs) {
    let ch = r.ch, cw = ch * a;
    if (cw > W) { cw = W; ch = Math.min(H, W / a); }
    r.cw = Math.floor(cw / 2) * 2; r.ch = Math.floor(ch / 2) * 2;
    let keys = [];
    r.segs.forEach((seg, k) => {
      const x = Math.max(0, Math.min(W - r.cw, seg.cx * W - r.cw / 2));
      const y = seg.fh > 0 ? Math.max(0, Math.min(H - r.ch, seg.cy * H - r.ch * 0.36)) : (H - r.ch) / 2;
      const last = keys[keys.length - 1];
      if (k > 0 && Math.abs(x - last[1]) < W * CAM_DEADZONE && Math.abs(y - last[2]) < H * CAM_DEADZONE) return;
      keys.push([k === 0 ? r.s : seg.s, x, y]);
    });
    keys = keys.filter((kv, k) => k === 0 || ((keys[k + 1]?.[0] ?? r.e) - kv[0]) >= CAM_HOLD);
    // Awal shot yang singkat juga tidak dipakai (sama dengan camera_runs)
    while (keys.length > 1 && keys[1][0] - keys[0][0] < CAM_HOLD) { keys[0] = [keys[0][0], keys[1][1], keys[1][2]]; keys.splice(1, 1); }
    r.keys = keys;
  }
  camCache = { key, runs };
  return runs;
}
// Area crop kamera (pecahan 0..1) pada waktu sumber `time`; null kalau belum ada segmen
function camAt(time, a = 9 / 16) {
  const runs = cameraRuns(a);
  if (!runs.length) return null;
  const r = runs.find((x, k) => time < x.e || k === runs.length - 1) || runs[0];
  const smooth = p => p * p * p * (p * (6 * p - 15) + 10);  // smootherstep
  const W0 = E.src.w, H0 = E.src.h;
  let x = r.keys[0][1], y = r.keys[0][2];
  for (let k = 1; k < r.keys.length; k++) {
    const jump = Math.abs(r.keys[k][1] - r.keys[k - 1][1]) > W0 * CAM_JUMP || Math.abs(r.keys[k][2] - r.keys[k - 1][2]) > H0 * CAM_JUMP;
    const p = jump ? (time >= r.keys[k][0] ? 1 : 0) : smooth(Math.max(0, Math.min(1, (time - r.keys[k][0]) / CAM_PAN)));
    x += (r.keys[k][1] - r.keys[k - 1][1]) * p;
    y += (r.keys[k][2] - r.keys[k - 1][2]) * p;
  }
  const W = E.src.w, H = E.src.h;
  return [x / W, y / H, r.cw / W, r.ch / H];
}

// Sama dengan half_crop() di clip.php: satu panel split screen (bawaan 9:8), tinggi wajah ±32%
function halfCrop(p, W, H, a = 9 / 8) {
  const minH = Math.min(H, Math.max(H * 0.35, 400));
  let ch = p.fh > 0 ? p.fh * H / 0.32 : H;
  ch = Math.max(minH, Math.min(H, ch));
  let cw = ch * a;
  if (cw > W) { cw = W; ch = Math.min(H, W / a); }
  const x = Math.max(0, Math.min(W - cw, p.cx * W - cw / 2));
  const y = Math.max(0, Math.min(H - ch, p.cy * H - ch * 0.45));
  return [x / W, y / H, cw / W, ch / H];
}

// Sama dengan gaming_crops() di clip.php: [game, webcam] dalam koordinat 0..1.
// Game = crop tengah rasio 1080 x top (bawaan 70%), webcam = sekitar wajah streamer rasio 1080 x sisanya
function gamingCrops(cam, W, H, top = 1344) {
  const ga = 1080 / top;
  let gh = H, gw = gh * ga;
  if (gw > W) { gw = W; gh = W / ga; }
  const ca = 1080 / (1920 - top);
  let ch = Math.max(cam.fh * H / 0.35, H * 0.12), cw = ch * ca;
  if (cw > W) { cw = W; ch = W / ca; }
  if (ch > H) { ch = H; cw = H * ca; }
  const x = Math.max(0, Math.min(W - cw, cam.cx * W - cw / 2));
  const y = Math.max(0, Math.min(H - ch, cam.cy * H - ch * 0.45));
  return [[(W - gw) / 2 / W, (H - gh) / 2 / H, gw / W, gh / H], [x / W, y / H, cw / W, ch / H]];
}

// Bagian split screen otomatis — sama dengan split_parts() di editor_lib.php:
// shot 2 orang (min. 0,5 dtk), dimulai lebih awal sebesar toleransi tanpa melewati bagian split sebelumnya
function splitParts() {
  if (!autoSplitOn()) return [];
  const parts = (E.shots || []).filter(s => s.people.length >= 2 && s.e - s.s >= 0.5).map(s => ({
    s: s.s, e: s.e, pp: [...s.people].sort((a, b) => b.fh - a.fh).slice(0, 2).sort((a, b) => a.cx - b.cx),
  })).sort((a, b) => a.s - b.s);
  let prevEnd = 0;
  for (const p of parts) { p.s = Math.max(prevEnd, p.s - (E.split.early || 0), 0); prevEnd = p.e; }
  return parts;
}

// Pasangan orang (atas, bawah) kalau waktu ini masuk bagian split, selain itu null
function splitPair(time) {
  const p = splitParts().find(x => time >= x.s && time < x.e);
  return p ? p.pp : null;
}

function segmentAt(time) {
  const segs = E.segments;
  return segs.find(s => time >= s.s && time < s.e) || segs[segs.length - 1] || null;
}

function draw() {
  if (!ctx) return;
  paintOpening(); // juga saat frame video belum siap, supaya lapisan opening selalu sesuai posisi putar
  const tr = trState();
  $('phone')?.classList.toggle('foot', !!fp || !!tr);
  if (fp) {
    // Footage / intro: efek, media, grafik & teks yang menempel ke footage ikut tampil
    drawFootage();
    drawEffects(cv.width, cv.height);
    drawOverlay();
  } else {
    if (vid.readyState < 2) return;
    if (isHidden('video')) { ctx.fillStyle = '#000'; ctx.fillRect(0, 0, cv.width, cv.height); }
    else paintMain(ctx, vid, frameTime(), cv.width, cv.height);
    drawEffects(cv.width, cv.height);
    drawFrameMask(ctx, cv.width, cv.height);
    drawOverlay();
  }
  if (tr) composeTransition(tr);
}

// Gambar area sumber r (pecahan 0..1) ke panel tanpa gepeng: area dipersempit di tengah sampai serasio panel (sama dengan fit_panel di clip.php)
function drawPanel(g, v, r, dx, dy, dw, dh) {
  const VW = v.videoWidth, VH = v.videoHeight;
  let sx = r[0] * VW, sy = r[1] * VH, sw = r[2] * VW, sh = r[3] * VH;
  const a = dw / dh;
  if (sw / sh > a) { const w = sh * a; sx += (sw - w) / 2; sw = w; } else { const h = sw / a; sy += (sh - h) / 2; sh = h; }
  g.drawImage(v, sx, sy, sw, sh, dx, dy, dw, dh);
}
// Frame clip (framing / split / blur) dari video v pada waktu sumber time ke context g
function paintMain(g, v, time, CW, CH) {
  const VW = v.videoWidth, VH = v.videoHeight;
  g.clearRect(0, 0, CW, CH);
  // Layout bingkai (bukan Full): area video sesuai rasio di tengah layar hitam, mengikuti pembicara kalau framing-nya begitu
  const G = frameGeom(CW, CH);
  if (!G.full && !splitPair(time)) {
    const r = (E.layout === 'speaker' && E.follow && camAt(time, G.a)) || speakerCrop(null, E.src.w, E.src.h, G.a);
    const src = [r[0] * VW, r[1] * VH, r[2] * VW, r[3] * VH];
    if (G.blur) {
      // Background blur: area video yang sama diperbesar memenuhi layar lalu diblur (sama dengan render)
      // (digambar sedikit melebihi layar supaya tepi blur tidak menggelap)
      const bw = CW * CH / G.h, px = Math.round(16 * CW / 540), m = px * 2;
      g.filter = `blur(${px}px) brightness(0.85)`;
      g.drawImage(v, ...src, (CW - bw) / 2 - m, -m, bw + 2 * m, CH + 2 * m);
      g.filter = 'none';
      g.save();
      g.beginPath();
      g.roundRect(G.x, G.y, G.w, G.h, G.r);
      g.clip();
      g.drawImage(v, ...src, 0, G.y, CW, G.h);
      g.restore();
    } else {
      g.fillStyle = '#000';
      g.fillRect(0, 0, CW, CH);
      g.drawImage(v, ...src, 0, G.y, CW, G.h);
    }
    return;
  }
  const T = splitTop(), top = CH * T / 1920;
  if (E.layout === 'gaming' && E.cam) {
    gamingCrops(E.cam, E.src.w, E.src.h, T).forEach((r, k) => drawPanel(g, v, r, 0, k ? top : 0, CW, k ? CH - top : top));
  } else if (splitPair(time)) {
    splitPair(time).forEach((p, k) => {
      drawPanel(g, v, halfCrop(p, E.src.w, E.src.h, 1080 / (k ? 1920 - T : T)), 0, k ? top : 0, CW, k ? CH - top : top);
    });
  } else {
    const r = (E.layout === 'speaker' && E.follow && camAt(time)) || speakerCrop(null, E.src.w, E.src.h);
    g.drawImage(v, r[0] * VW, r[1] * VH, r[2] * VW, r[3] * VH, 0, 0, CW, CH);
  }
}

function lineEnd(i) {
  const l = E.lines[i], next = E.lines[i + 1];
  let end = l.e;
  const ns = next ? next.s : Infinity;
  if (ns - end < 0.35) end = ns;
  return Math.min(end + 0.15, ns);
}

// Item tampil di waktu playhead? Item yang berakhir di ujung clip tetap tampil di frame terakhir
// (playhead tepat di akhir trim), sama seperti hasil render.
const inSpan = (s, e) => t >= s && (t < e || (e >= E.trim[1] - 0.01 && t <= E.trim[1] + 0.001));

function activeLine() {
  for (let i = 0; i < E.lines.length; i++) {
    if (t >= E.lines[i].s && t < lineEnd(i)) return i;
    if (E.lines[i].s > t) break;
  }
  return -1;
}

/* Highlight & efek subtitle (mengikuti waktu ucapan). Sama dengan sub_hl_cfg() / sub_fx_word() di subtitles.php.
   Highlight = kata yang sedang diucapkan disorot (warna teks atau latar kotak, font & skala sendiri).
   Efek: ketik (huruf muncul selama kata diucapkan) · kata per kata (animasi masuk fade / pop / glow) · blur menajam saat muncul.
   Hanya transparansi, tinggi huruf, garis tepi & blur yang dianimasikan, jadi kata tidak bergeser dalam baris. */
const SUB_FX = [['none', 'Tidak ada'], ['type', 'Ketik'], ['word', 'Kata per kata']];
const SUB_WANIMS = [['fade', 'Fade'], ['pop', 'Pop'], ['glow', 'Glow']];
const SUB_BLUR = 6, SUB_BLUR_MS = 220;
// Smoother: semua animasi teks 1,8x lebih lama + easing melambat di akhir (sama dengan ass_t di subtitles.php)
const SMOOTH_K = 1.8, SMOOTH_ACC = 0.5;
// Progres tag \t(a,b) ASS pada detik p sejak awal event (a, b dalam ms)
const tProg = (p, a, b, sm) => {
  const k = sm ? SMOOTH_K : 1, q = Math.max(0, Math.min(1, (p * 1000 - a * k) / ((b - a) * k)));
  return sm ? q ** SMOOTH_ACC : q;
};
const blurDone = sm => SUB_BLUR_MS * (sm ? SMOOTH_K : 1) / 1000;
// Padding kotak highlight [samping, atas-bawah] dari ukuran huruf (sama dengan sub_box_pad)
const SUB_BOX_PAD = size => [Math.round(size * 0.16), Math.round(size * 0.02)];
// Lengkapi / konversi pengaturan subtitle lama (efek "sorot per kata" = highlight aktif)
function normSubStyle(st) {
  const fx = st.fx ?? 'karaoke';
  Object.assign(st, {
    hl: 'hl' in st ? !!st.hl : fx !== 'none', fx: ['type', 'word'].includes(fx) ? fx : 'none',
    hl_style: st.hl_style === 'box' ? 'box' : 'color', hl_font: FONTS.some(f => f.k === st.hl_font) ? st.hl_font : '',
    hl_tc: /^#[0-9a-f]{6}$/i.test(st.hl_tc || '') ? st.hl_tc : '#111111', hl_pop: st.hl_pop !== false,
    wanim: SUB_WANIMS.some(w => w[0] === st.wanim) ? st.wanim : 'fade', blur: !!st.blur, smooth: !!st.smooth,
  });
}
const lerp = (a, b, p) => a + (b - a) * Math.max(0, Math.min(1, p));
const mixHex = (a, b, p) => '#' + [1, 3, 5].map(i => Math.round(lerp(parseInt(a.substr(i, 2), 16), parseInt(b.substr(i, 2), 16), p)).toString(16).padStart(2, '0')).join('');
function subFxHtml(words, active, nextS, st) {
  const k = $('phone').clientWidth / 1080, hideRest = st.fx !== 'none', sm = st.smooth;
  const box = st.hl && st.hl_style === 'box', S = st.hl && st.hl_pop ? 1.1 : 1;
  return words.map((w, j) => {
    const txt = displayWord(w.w);
    if (j > active && hideRest) return `<span style="opacity:0">${esc(txt)}</span>`;
    if (j !== active || (!st.hl && st.fx === 'none')) return esc(txt);
    const css = [`font-size:${S}em`], p = t - w.s;
    // Garis tepi akhir kata aktif: tanpa garis (kotak), atau border biasa
    const edgeC = box ? st.hl_tc : st.stroke, edgeW = box ? 0 : st.stroke_w;
    if (st.hl) {
      css.push(`color:${rgba(box ? st.hl_tc : st.highlight, st.opacity)}`);
      if (st.hl_font) css.push(`font-family:${fontFamily(st.hl_font)}`);
    }
    if (box) {
      // Kotak sungguhan di belakang kata aktif (sama dengan lapisan SubBox saat render)
      const [px, py] = SUB_BOX_PAD(st.size);
      css.push(`background:${st.highlight}`, `padding:${py * k}px ${px * k}px`, 'text-shadow:none', '-webkit-box-decoration-break:clone', 'box-decoration-break:clone');
    }
    let stroke = box ? [0, edgeC] : null, block = false;
    if (st.fx === 'word') {
      css.push(`opacity:${tProg(p, 0, st.wanim === 'fade' ? 160 : 90, sm)}`);
      if (st.wanim === 'pop') {
        block = true;
        css.push(`transform:scaleY(${p * 1000 < 90 * (sm ? SMOOTH_K : 1) ? lerp(0.4, 1.15, tProg(p, 0, 90, sm)) : lerp(1.15, 1, tProg(p, 90, 170, sm))})`);
      }
      if (st.wanim === 'glow' && !box) stroke = [lerp(18, edgeW, tProg(p, 0, 260, sm)), mixHex('#FFFFFF', edgeC, tProg(p, 0, 260, sm))];
    }
    if (st.blur && st.fx !== 'none' && p < blurDone(sm)) { block = true; css.push(`filter:blur(${lerp(SUB_BLUR, 0, tProg(p, 0, SUB_BLUR_MS, sm)) * k}px)`); }
    if (stroke) css.push(`-webkit-text-stroke:${stroke[0] * 2 * k}px ${stroke[1]}`);
    if (block) css.push('display:inline-block;line-height:1;transform-origin:50% 85%');
    if (st.fx === 'type') {
      // Kata terakhir potongan diperpanjang sampai potongan berikutnya, sama dengan render
      const e = j === words.length - 1 && nextS != null ? Math.max(w.e, nextS) : w.e, ch = Array.from(txt);
      const vis = ch.filter((_, q) => p >= q / ch.length * Math.max(0, e - w.s)).length;
      return `<i style='${css.join(';')}'>${esc(ch.slice(0, vis).join(''))}<span style="opacity:0">${esc(ch.slice(vis).join(''))}</span></i>`;
    }
    return `<i style='${css.join(';')}'>${esc(txt)}</i>`; // kutip tunggal: nama font memakai kutip ganda
  }).join(' ');
}

const displayWord = w => {
  w = w.replace(/[.,;:…]+["”]?$/u, '');
  return E.style.uppercase ? w.toUpperCase() : w;
};

function drawOverlay() {
  const inClip = !fp && t >= E.trim[0] - 0.01 && t <= E.trim[1];
  const st = E.style;
  const sub = $('sub');
  const kinetic = E.caption === 'kinetic';
  drawKinetic(kinetic && E.subtitles && inClip);
  const i = E.subtitles && inClip && !kinetic ? activeLine() : -1;
  const editingSub = inlineEd?.kind === 'line';
  if (editingSub) {
    sub.hidden = false;
  } else if (i >= 0) {
    const l = E.lines[i];
    let active = 0;
    l.words.forEach((w, k) => { if (w.s <= t) active = k; });
    // Kata per tampilan: hanya potongan N kata yang berisi kata aktif yang ditampilkan (sama dengan render)
    const n = st.chunk || 0, c0 = n ? Math.floor(active / n) * n : 0;
    const shown = n ? l.words.slice(c0, c0 + n) : l.words;
    active -= c0;
    sub.innerHTML = '<span class="sbox">' + subFxHtml(shown, active, n && c0 + n < l.words.length ? l.words[c0 + n].s : null, st) + '</span>';
    const pb = t - shown[0].s; // blur menajam saat potongan baru muncul
    sub.firstChild.style.filter = st.blur && pb >= 0 && pb < blurDone(st.smooth) ? `blur(${lerp(SUB_BLUR, 0, tProg(pb, 0, SUB_BLUR_MS, st.smooth)) * $('phone').clientWidth / 1080}px)` : '';
    applyTextStyle(sub, st);
    sub.style.color = rgba(st.color, st.opacity);
    sub.style.webkitTextStroke = st.stroke_w > 0 ? `calc(${st.stroke_w * 2}px * var(--k)) ${st.stroke}` : '0';
    sub.style.fontSize = `calc(${st.size}px * var(--k))`;
    sub.style.bottom = (1 - st.pos) * 100 + '%';
    sub.style.transform = `translateX(calc(${anchorShift(st)}px * var(--k)))`;
    sub.hidden = false;
    sub.classList.toggle('click', !E.pending);
    sub.title = E.pending ? '' : 'Klik untuk mengedit baris ini';
  } else {
    sub.hidden = true;
  }
  drawMedia(inClip);
  drawTexts(inClip);
  const hk = $('hook');
  const editingHook = inlineEd?.kind === 'hook';
  const showHook = editingHook || (inClip && E.hook.enabled && E.hook.text.trim() && t - E.trim[0] < E.hook.duration);
  hk.hidden = !showHook;
  hk.classList.add('click');
  if (showHook) {
    const H = E.hook;
    if (!editingHook) hk.innerHTML = `<span>${esc(H.text)}</span>`;
    applyTextStyle(hk, H);
    hk.style.setProperty('--hs', H.size);
    hk.style.setProperty('--hc', rgba(H.color, H.opacity));
    hk.style.setProperty('--hb', rgba(H.bg, H.bg_opacity));
    hk.style.margin = { left: '0 auto 0 0', right: '0 0 0 auto' }[H.align] || '0 auto';
    hk.style.top = `calc(${hookTop()}px * var(--k))`;
    const hEnd = Math.min(H.duration, Math.max(0.5, E.trim[1] - E.trim[0] - 0.1));
    const A = editingHook ? animAt(null) : animAt(H.anim || HOOK_ANIM, 0, hEnd, t - E.trim[0]);
    hk.style.transform = `translateX(calc(${anchorShift(H)}px * var(--k))) ${animCss(A)}`;
    hk.style.transformOrigin = `${{ left: 'left', right: 'right' }[H.align] || 'center'} top`;
    hk.style.opacity = A.a;
  }
  placeSelbox(i, showHook);
}

/* ---------- Media: pustaka (panel kiri), timeline, dan preview ---------- */
// Clip lama yang memakai fitur "Overlay / lower third": jadikan item media sepanjang clip, posisinya sama persis.
// Render tetap sama walau belum disimpan (server masih punya overlay lama); begitu disimpan, overlay lama dimatikan.
function migrateOverlay(ed) {
  const ov = ed.overlay;
  if (!ov?.enabled || !ov.file) return;
  ov.enabled = false;
  const img = new Image();
  img.onload = () => {
    const w = 1080 * ov.size, h = w * img.naturalHeight / img.naturalWidth;
    const top = (ov.pos === 'top' ? 115 : ov.pos === 'center' ? (1920 - h) / 2 : 1920 - h - 115) + (ov.offset || 0);
    ed.media.unshift({ id: 'ov' + Math.random().toString(36).slice(2, 8), file: ov.file, name: 'Overlay', s: ed.trim[0], e: ed.trim[1],
      x: 0.5, y: Math.round((top + h / 2) / 1920 * 1e4) / 1e4, w: ov.size });
    if (ed === E) { renderTimeline(); draw(); }
  };
  img.src = 'previews/overlays/' + ov.file;
}

let mediaLib = [];

async function loadMediaLib() {
  try { mediaLib = (await api('media_list', null, '&id=' + PID)).media; } catch { mediaLib = []; }
  renderMediaLib();
}

function renderMediaLib() {
  $('media-info').textContent = mediaLib.length || '';
  $('media-grid').innerHTML = mediaLib.length ? mediaLib.map(m => `<div class="mtile" data-mf="${m.file}" title="${esc(m.name)}" draggable="true">
      <img src="previews/overlays/${m.kind === 'video' ? m.thumb : m.file}" alt="" loading="lazy" draggable="false">${m.kind === 'video' ? `<span class="mdur">${fmtTs(m.dur)}</span>` : ''}<span class="mname">${esc(m.name)}</span>
      <span class="madd">${m.kind === 'video' ? 'Seret ke timeline' : '+ Ke timeline'}</span><button class="mdel" data-mdel="${m.file}" title="Hapus dari pustaka">${ic('x')}</button></div>`).join('')
    : `<div class="media-empty" id="media-empty">${ic('image')}<span><b>Belum ada media</b><br>Klik <b>+</b> di atas atau seret gambar / video ke sini.</span></div>`;
}

async function uploadMedia(files) {
  files = [...files].filter(f => /^(image\/(png|jpeg|webp)|video\/(mp4|quicktime|webm|x-m4v))$/.test(f.type));
  if (!files.length) return toast('Format media harus PNG, JPG, WEBP, MP4, MOV, atau WEBM.');
  const big = files.find(f => f.size > UPLOAD_MAX);
  if (big) return toast(`${big.name} terlalu besar (maksimal ${Math.round(UPLOAD_MAX / 1048576)} MB).`);
  for (const [n, f] of files.entries()) {
    toast(`Mengunggah ${files.length > 1 ? `${n + 1}/${files.length}: ` : ''}${f.name}…`);
    try {
      const fd = new FormData();
      fd.append('file', f);
      fd.append('id', PID);
      const res = await fetch('api.php?action=media_upload', { method: 'POST', body: fd });
      const d = await res.json().catch(() => ({ error: 'Respons server tidak valid.' }));
      if (!res.ok || d.error) throw new Error(d.error || 'Media gagal diunggah.');
      mediaLib = d.media;
      renderMediaLib();
    } catch (err) { toast(err.message); return; }
  }
  toast(files.length > 1 ? `${files.length} media ditambahkan` : 'Media ditambahkan');
}

// Klik di menu Media: sepanjang clip. Seret ke timeline: mulai di titik jatuhnya sampai akhir clip.
function addMediaToTimeline(lib, at = null) {
  if (!E || !$('cv')) return toast('Tunggu editor selesai dimuat.');
  if (lib.kind === 'video') return toast('Video dipakai sebagai footage: seret ke track video di timeline.');
  const [t0, t1] = E.trim;
  const s = at == null ? t0 : Math.max(t0, Math.min(t1 - 0.5, at));
  const wide = lib.w >= lib.h;
  E.media.push({ id: Math.random().toString(36).slice(2, 10), file: lib.file, name: lib.name, s: round3(s), e: round3(t1),
    x: 0.5, y: 0.5, w: wide ? 0.7 : 0.45 });
  pause();
  changed();
  select({ type: 'media', i: E.media.length - 1 });
}

/* ---------- Musik: library bersama (semua proyek) & track musik di timeline ---------- */
let musicLib = [], musicQ = '';
const musicUrl = file => 'previews/music/' + file;

async function loadMusicLib() {
  try { musicLib = (await api('music_list')).music; } catch { musicLib = []; }
  renderMusicLib();
}

function renderMusicLib() {
  $('music-info').textContent = musicLib.length || '';
  const q = musicQ.trim().toLowerCase();
  const list = q ? musicLib.filter(m => m.name.toLowerCase().includes(q)) : musicLib;
  const used = new Set((E?.music || []).map(m => m.file));
  $('music-list').innerHTML = !musicLib.length
    ? `<div class="mu-empty" id="music-empty">${ic('music')}<b>Library musik masih kosong</b><br>Klik <b>+</b> di atas atau seret file MP3, M4A, atau WAV ke sini.</div>`
    : !list.length ? `<div class="txt-empty">Tidak ada musik yang cocok dengan "${esc(musicQ)}".</div>`
    : list.map(m => `<div class="mu-row${muPrev.file === m.file ? ' play' : ''}" data-mu-f="${m.file}">
        <button class="mu-pl" data-mu-play title="Dengarkan">${ic(muPrev.file === m.file ? 'pause' : 'play')}</button>
        <div class="mu-tx"><div class="mu-nm" title="${esc(m.name)}">${esc(m.name)}</div>
          <div class="mu-meta">${fmtDur(m.dur)}${m.from === 'download' ? ' · unduhan' : ''}${used.has(m.file) ? ' · <b>dipakai</b>' : ''}</div></div>
        <div class="mu-act"><button class="opt" data-mu-ren title="Ganti nama">${ic('pencil')}</button>
          <button class="opt mu-del" data-mu-del title="Hapus dari library">${ic('trash')}</button>
          <button class="mu-add" data-mu-add title="Tambah ke timeline">${ic('plus')}</button></div>
      </div>`).join('');
}

async function uploadMusic(files) {
  files = [...files].filter(f => /\.(mp3|m4a|aac|wav|ogg|flac)$/i.test(f.name));
  if (!files.length) return toast('Format musik harus MP3, M4A, AAC, WAV, OGG, atau FLAC.');
  const big = files.find(f => f.size > UPLOAD_MAX);
  if (big) return toast(`${big.name} terlalu besar (maksimal ${Math.round(UPLOAD_MAX / 1048576)} MB).`);
  for (const [n, f] of files.entries()) {
    toast(`Mengunggah ${files.length > 1 ? `${n + 1}/${files.length}: ` : ''}${f.name}…`);
    try {
      const fd = new FormData();
      fd.append('file', f);
      const res = await fetch('api.php?action=music_upload', { method: 'POST', body: fd });
      const d = await res.json().catch(() => ({ error: 'Respons server tidak valid.' }));
      if (!res.ok || d.error) throw new Error(d.error || 'Musik gagal diunggah.');
      musicLib = d.music;
      renderMusicLib();
    } catch (err) { toast(err.message); return; }
  }
  toast(files.length > 1 ? `${files.length} musik masuk library` : 'Musik masuk library');
}

// Tambah lagu ke track musik: dari awal clip, sepanjang clip (atau sampai lagu habis)
function addMusic(lib) {
  if (!E || !$('cv')) return toast('Tunggu editor selesai dimuat.');
  const [t0, t1] = E.trim;
  const s = t0;
  E.music.push({ id: Math.random().toString(36).slice(2, 10), file: lib.file, name: lib.name, dur: lib.dur,
    s: round3(s), e: round3(Math.min(t1, s + lib.dur)), off: 0, vol: 40, fin: 0, fout: 1.5 });
  stopMusicPreview();
  pause();
  changed();
  select({ type: 'music', i: E.music.length - 1 });
  renderMusicLib();
  toast('Musik ditambahkan ke timeline');
}

function removeMusic(i) {
  E.music.splice(i, 1);
  changed();
  select({ type: 'clip' });
  renderMusicLib();
}

// Dengarkan lagu di library (satu lagu sekaligus; berhenti saat editor diputar)
const muPrev = { file: '', el: new Audio() };
muPrev.el.onended = () => stopMusicPreview();
function stopMusicPreview() {
  if (!muPrev.file) return;
  muPrev.el.pause();
  muPrev.file = '';
  renderMusicLib();
}
function toggleMusicPreview(file) {
  if (muPrev.file === file) return stopMusicPreview();
  pause();
  muPrev.file = file;
  muPrev.el.src = musicUrl(file);
  muPrev.el.volume = 0.8;
  muPrev.el.play().catch(() => { toast('Musik tidak bisa diputar di browser ini.'); stopMusicPreview(); });
  renderMusicLib();
}

// Pemutaran musik di preview editor: tiap potongan musik punya elemen audio sendiri, disinkronkan ke playhead tiap frame
const musicEls = new Map();
let musicResync = false;
function syncMusic() {
  if (!E) return;
  const on = playing && !musicMuted(), live = new Set();
  for (const m of E.music) {
    let a = musicEls.get(m.id);
    if (!a || a.dataset.f !== m.file) {
      a?.pause();
      a = new Audio(musicUrl(m.file));
      a.preload = 'auto';
      a.dataset.f = m.file;
      musicEls.set(m.id, a);
    }
    live.add(m.id);
    // Waktu potongan musik: detik video asli (musik di clip) atau detik dari awal footage (musik yang menempel ke footage / intro)
    const mt = itemT(m);
    if (!on || mt === null || mt < m.s || mt >= m.e || m.off + mt - m.s >= m.dur) { if (!a.paused) a.pause(); continue; }
    const want = m.off + mt - m.s;
    if (a.paused || musicResync || Math.abs(a.currentTime - want) > 0.3) a.currentTime = want;
    const fi = m.fin > 0 ? Math.min(1, (mt - m.s) / m.fin) : 1, fo = m.fout > 0 ? Math.min(1, (m.e - mt) / m.fout) : 1;
    a.volume = Math.max(0, Math.min(1, musicVol(m, mt) / 100 * fi * fo));
    if (a.paused) a.play().catch(() => {});
  }
  musicResync = false;
  for (const [id, a] of musicEls) if (!live.has(id)) { a.pause(); musicEls.delete(id); }
}

$('music-add').onclick = () => $('music-input').click();
$('music-input').onchange = e => { uploadMusic(e.target.files); e.target.value = ''; };
$('music-q').oninput = e => { musicQ = e.target.value; renderMusicLib(); };
$('music-list').addEventListener('click', async e => {
  if (e.target.closest('#music-empty')) return $('music-input').click();
  const row = e.target.closest('[data-mu-f]');
  if (!row) return;
  const lib = musicLib.find(m => m.file === row.dataset.muF);
  if (!lib) return;
  if (e.target.closest('[data-mu-play]')) return toggleMusicPreview(lib.file);
  if (e.target.closest('[data-mu-add]')) return addMusic(lib);
  try {
    if (e.target.closest('[data-mu-ren]')) {
      const name = prompt('Nama musik', lib.name);
      if (name === null || !name.trim() || name.trim() === lib.name) return;
      musicLib = (await api('music_rename', { file: lib.file, name: name.trim() })).music;
      // Nama di timeline clip ini ikut diperbarui
      E?.music.forEach(m => { if (m.file === lib.file) m.name = name.trim(); });
      if (E) { changed(); renderTimeline(); renderProps(); }
    } else if (e.target.closest('[data-mu-del]')) {
      if (!confirm(`Hapus "${lib.name}" dari library musik? Clip yang sudah memakainya tetap aman.`)) return;
      if (muPrev.file === lib.file) stopMusicPreview();
      musicLib = (await api('music_delete', { file: lib.file })).music;
    } else return toggleMusicPreview(lib.file);
    renderMusicLib();
  } catch (err) { toast(err.message); }
});
['dragenter', 'dragover'].forEach(ev => $('lp-music').addEventListener(ev, e => { e.preventDefault(); $('music-list').classList.add('drop'); }));
['dragleave', 'drop'].forEach(ev => $('lp-music').addEventListener(ev, e => { e.preventDefault(); $('music-list').classList.remove('drop'); }));
$('lp-music').addEventListener('drop', e => uploadMusic(e.dataTransfer.files));
loadMusicLib();

function removeMedia(i) {
  E.media.splice(i, 1);
  changed();
  select({ type: 'clip' });
}

// Gambar media yang aktif di waktu sekarang; elemen dipakai ulang per id supaya tidak berkedip
function drawMedia(inClip) {
  const L = $('mlayer');
  if (!L) return;
  const keep = new Set();
  E.media.forEach((m, i) => {
    keep.add(m.id);
    let el = L.querySelector(`[data-mid="${m.id}"]`);
    if (!el) {
      el = document.createElement('img');
      el.className = 'mimg'; el.alt = ''; el.draggable = false; el.dataset.mid = m.id;
      el.onload = () => draw();
      L.appendChild(el);
    }
    const src = m._src || (m.file ? 'previews/overlays/' + m.file : '');
    if (el.dataset.f !== src) { el.src = src; el.dataset.f = src; }
    el.dataset.mi = i;
    el.hidden = isHidden('media') || !itemOn(m, inClip);
    el.style.left = `calc(${m.x * 1080}px * var(--k))`;
    el.style.top = `calc(${m.y * 1920}px * var(--k))`;
    el.style.width = `calc(${m.w * 1080}px * var(--k))`;
    const A = animAt(m.anim, m.s, m.e, itemT(m) ?? t);
    el.style.transform = `translate(-50%, -50%) ${animCss(A)}`;
    el.style.opacity = A.a * (m.opacity ?? 100) / 100;
    el.style.zIndex = i;
  });
  L.querySelectorAll('.mimg').forEach(el => { if (!keep.has(el.dataset.mid)) el.remove(); });
}

// Geser gambar media di preview: magnet ke garis tengah & batas area aman, tidak bisa keluar layar
function bindMediaDrag() {
  $('mlayer').addEventListener('pointerdown', e => {
    const el = e.target.closest('.mimg');
    if (!el || e.button !== 0) return;
    e.stopPropagation();
    const i = +el.dataset.mi, m = E.media[i];
    const k = $('phone').clientWidth / 1080, r = el.getBoundingClientRect();
    const w = r.width / k, h = r.height / k, x0 = m.x * 1080, y0 = m.y * 1920, sx = e.clientX, sy = e.clientY;
    let moving = false;
    el.setPointerCapture(e.pointerId);
    pause();
    if (sel.type !== 'media' || sel.i !== i) select({ type: 'media', i });
    const move = ev => {
      const dx = (ev.clientX - sx) / k, dy = (ev.clientY - sy) / k;
      if (!moving) {
        if (Math.hypot(dx, dy) < 4 / k) return;
        moving = true;
        document.body.classList.add('moving');
        $('guides').classList.add('on');
      }
      let cx = x0 + dx, cy = y0 + dy;
      const hit = {};
      if (Math.abs(cx - 540) < SNAP) { cx = 540; hit.cx = 1; }
      if (Math.abs(cy - 960) < SNAP) { cy = 960; hit.cy = 1; }
      if (Math.abs(cx - w / 2 - SAFE.l) < SNAP) { cx = SAFE.l + w / 2; hit.l = 1; }
      if (Math.abs(cx + w / 2 - SAFE.r) < SNAP) { cx = SAFE.r - w / 2; hit.r = 1; }
      if (Math.abs(cy - h / 2 - SAFE.t) < SNAP) { cy = SAFE.t + h / 2; hit.t = 1; }
      if (Math.abs(cy + h / 2 - SAFE.b) < SNAP) { cy = SAFE.b - h / 2; hit.b = 1; }
      cx = Math.max(0, Math.min(1080, cx)); cy = Math.max(0, Math.min(1920, cy));
      $('guides').querySelectorAll('.gl').forEach(g => g.classList.toggle('show', !!hit[g.classList[2]]));
      m.x = Math.round(cx / 1080 * 1e4) / 1e4;
      m.y = Math.round(cy / 1920 * 1e4) / 1e4;
      draw();
    };
    const up = () => {
      el.removeEventListener('pointermove', move);
      el.removeEventListener('pointerup', up);
      el.removeEventListener('pointercancel', up);
      if (!moving) return;
      movedAt = performance.now();
      document.body.classList.remove('moving');
      $('guides').classList.remove('on');
      $('guides').querySelectorAll('.gl').forEach(g => g.classList.remove('show'));
      if (isFullGfx(m)) scheduleGfx(m, true); // pita banner menyesuaikan posisi baru sampai bawah layar
      changed();
    };
    el.addEventListener('pointermove', move);
    el.addEventListener('pointerup', up);
    el.addEventListener('pointercancel', up);
  });
  $('mlayer').addEventListener('click', e => { if (e.target.closest('.mimg')) e.stopPropagation(); });
  // Klik dua kali grafik template = ketik teksnya langsung di layar
  $('mlayer').addEventListener('dblclick', e => {
    const el = e.target.closest('.mimg');
    if (el && E.media[+el.dataset.mi]?.gfx && !isLocked('media')) startGfxEdit(+el.dataset.mi);
  });
}

// Rail kiri: 'clips' = Galeri clip (bawaan), selain itu isi menu (Media, Musik, …) di panel kiri. Panel kanan selalu properti.
let sideMode = 'clips';
function setSide(mode) {
  sideMode = mode;
  document.body.classList.toggle('side-menu', mode !== 'clips');
  document.querySelectorAll('.lp-tab').forEach(x => x.classList.toggle('on', x.dataset.lp === mode));
  document.querySelectorAll('#clips .lp-pane').forEach(p => { p.hidden = p.id !== 'lp-' + mode; });
}
document.querySelector('.lp-tabs').addEventListener('click', e => {
  const b = e.target.closest('[data-lp]');
  if (!b) return;
  const lp = b.dataset.lp;
  setSide(lp);
  if (!E) return;
  if (lp === 'intro') { renderIntro(); selectIntroPanel(); }
  else if (sel.type === 'intro') select({ type: 'clip' });
  if (lp === 'sfx') loadSfxLib();
});
$('media-add').onclick = () => $('media-input').click();
$('media-input').onchange = e => { uploadMedia(e.target.files); e.target.value = ''; };
$('media-grid').addEventListener('click', async e => {
  const del = e.target.closest('[data-mdel]');
  if (del) {
    try { mediaLib = (await api('media_delete', { id: PID, file: del.dataset.mdel })).media; renderMediaLib(); } catch (err) { toast(err.message); }
    return;
  }
  if (e.target.closest('#media-empty')) return $('media-input').click();
  const tile = e.target.closest('[data-mf]');
  if (tile) addMediaToTimeline(mediaLib.find(m => m.file === tile.dataset.mf));
});
// Seret media dari pustaka ke timeline: track media = overlay, track video = footage di awal / akhir
let dragMedia = null;
$('media-grid').addEventListener('dragstart', e => {
  const tile = e.target.closest('[data-mf]');
  dragMedia = tile ? mediaLib.find(m => m.file === tile.dataset.mf) : null;
  if (!dragMedia) return e.preventDefault();
  e.dataTransfer.effectAllowed = 'copy';
  e.dataTransfer.setData('text/plain', dragMedia.name || dragMedia.file);
});
document.addEventListener('dragend', () => { dragMedia = null; showDrop(null); });
['dragenter', 'dragover'].forEach(ev => $('lp-media').addEventListener(ev, e => { if (dragMedia) return; e.preventDefault(); $('media-grid').classList.add('drop'); }));
['dragleave', 'drop'].forEach(ev => $('lp-media').addEventListener(ev, e => { if (dragMedia) return; e.preventDefault(); $('media-grid').classList.remove('drop'); }));
$('lp-media').addEventListener('drop', e => { if (!dragMedia) uploadMedia(e.dataTransfer.files); });
loadMediaLib();

/* ---------- Efek transisi overlay (light leak, flash, fade hitam) ---------- */
const trackArr = tr => tr === 'text' ? E.texts : tr === 'effect' ? E.effects : tr === 'music' ? E.music : E.media;
// Kurva intensitas — sama dengan effect_env_expr() di editor_lib.php
// Kurva intensitas efek (sama dengan effect_env_expr() di editor_lib.php)
const fxEnv = (kind, p) => {
  p = Math.min(1, Math.max(0, p));
  if (['leak', 'sweep', 'anamorphic', 'glow'].includes(kind)) return Math.sin(Math.PI * p);
  if (kind === 'flash') return p < 0.3 ? p / 0.3 : (1 - p) / 0.7;
  if (kind === 'zoom') return p < 0.3 ? Math.sin(p / 0.3 * Math.PI / 2) : Math.cos((p - 0.3) / 0.7 * Math.PI / 2);
  if (kind === 'shake') return 1 - p;
  return Math.min(1, 2.5 * Math.min(p, 1 - p));
};
let fxCanvas = null;
const fxBuf = document.createElement('canvas');

// Cahaya sweep / anamorphic per titik (x, y dalam satuan lebar layar) → [bagian berwarna, bagian putih]; sama dengan effect_light_terms()
function lightTerms(pre, P) {
  if (pre.kind === 'sweep') {
    const c = Math.cos(pre.angle), s = Math.sin(pre.angle), us = [0, c, 16 / 9 * s, c + 16 / 9 * s];
    const u0 = Math.min(...us) - 0.3, u1 = Math.max(...us) + 0.3, pos = u0 + (u1 - u0) * P;
    return (x, y) => { const q = (x * c + y * s - pos) ** 2; return [Math.exp(-q / 0.01) + 0.35 * Math.exp(-q / 0.1225), 0.6 * Math.exp(-q / 0.0016)]; };
  }
  const cx = 0.22 + 0.56 * P, gc = 0.78 - 0.56 * P;
  return (x, y) => {
    const dx = x - cx, dy = y - 0.7467, r2 = dx * dx + dy * dy, gx = x - gc;
    const streak = Math.exp(-dy * dy / 0.0002) * Math.exp(-dx * dx / 0.64);
    const ghost = Math.exp(-(gx * gx + (dy - 0.06) ** 2) / 0.0012) + 0.6 * Math.exp(-(gx * gx * 2.2 + (dy - 0.12) ** 2) / 0.004);
    return [streak + 0.35 * Math.exp(-r2 / 0.09) + 0.45 * ghost, Math.exp(-r2 / 0.0025) + 0.25 * streak];
  };
}

// Salin isi canvas preview ke buffer (untuk efek yang mengubah gambar video itu sendiri)
function copyCv(CW, CH) {
  fxBuf.width = CW; fxBuf.height = CH;
  fxBuf.getContext('2d').drawImage(cv, 0, 0);
}

// Gambar efek aktif di atas video pada canvas preview (sebelum media/teks, seperti hasil render).
// Urutan sama dengan render: gerak kamera → glow → cahaya (screen) → gelap
function drawEffects(CW, CH) {
  if (!E.effects?.length || isHidden('fx')) return;
  const inClip = !fp && t >= E.trim[0] && t <= E.trim[1];
  const rank = { zoom: 0, shake: 0, grade: 0.5, glow: 1, dip: 3, vignette: 3 };
  const act = E.effects.filter(fx => EFFECTS[fx.type] && fx.intensity && itemOn(fx, inClip))
    .sort((a, b) => (rank[EFFECTS[a.type].kind] ?? 2) - (rank[EFFECTS[b.type].kind] ?? 2));
  for (const fx of act) {
    const ft = itemT(fx);
    const pre = EFFECTS[fx.type], p = (ft - fx.s) / (fx.e - fx.s), k = fx.intensity / 100, env = Math.max(0, fxEnv(pre.kind, p)), a = env * k;
    if (pre.kind === 'zoom' || pre.kind === 'shake') {
      const z = pre.kind === 'zoom' ? 1 + 0.18 * k * env : 1 + 0.06 * env, tl = ft - fx.s;
      const amp = pre.kind === 'shake' ? 30 * k * env * CW / 1080 : 0;
      copyCv(CW, CH);
      ctx.drawImage(fxBuf, (CW - CW * z) / 2 - amp * Math.sin(2 * Math.PI * 13 * tl), (CH - CH * z) / 2 - amp * Math.cos(2 * Math.PI * 11 * tl), CW * z, CH * z);
      continue;
    }
    if (pre.kind === 'grade') {
      copyCv(CW, CH);
      ctx.save();
      ctx.filter = gradeCss(pre, k);
      ctx.drawImage(fxBuf, 0, 0);
      ctx.restore();
      if (pre.tint) {
        ctx.save();
        ctx.globalCompositeOperation = 'soft-light';
        ctx.fillStyle = `rgba(${pre.tint.slice(0, 3).join(',')},${Math.min(1, pre.tint[3] * k)})`;
        ctx.fillRect(0, 0, CW, CH);
        ctx.restore();
      }
      continue;
    }
    if (pre.kind === 'glow') {
      copyCv(CW, CH);
      ctx.save();
      ctx.globalCompositeOperation = 'screen';
      ctx.globalAlpha = Math.min(1, 0.85 * a);
      ctx.filter = 'blur(13px)';
      ctx.drawImage(fxBuf, 0, 0);
      ctx.restore();
      continue;
    }
    if (pre.kind === 'dip') { ctx.fillStyle = `rgba(0,0,0,${a})`; ctx.fillRect(0, 0, CW, CH); continue; }
    if (pre.kind === 'vignette') {
      const g = ctx.createRadialGradient(CW / 2, CH / 2, CH * 0.22, CW / 2, CH / 2, CH * 0.62);
      g.addColorStop(0, 'rgba(0,0,0,0)');
      g.addColorStop(1, `rgba(0,0,0,${Math.min(1, 0.92 * a)})`);
      ctx.fillStyle = g; ctx.fillRect(0, 0, CW, CH);
      continue;
    }
    if (pre.kind === 'flash') {
      const [R, G, B] = pre.color || [255, 255, 255];
      ctx.save(); ctx.globalCompositeOperation = 'screen';
      ctx.fillStyle = `rgba(${R},${G},${B},${a})`; ctx.fillRect(0, 0, CW, CH);
      ctx.restore();
      continue;
    }
    // Cahaya: digambar di frame kecil lalu dicampur ke video dengan mode screen
    fxCanvas = fxCanvas || Object.assign(document.createElement('canvas'), { width: 135, height: 240 });
    const g = fxCanvas.getContext('2d'), W = fxCanvas.width, H = fxCanvas.height;
    if (pre.kind === 'leak') {
      // Gumpalan gaussian dijumlahkan (lighter)
      g.globalCompositeOperation = 'source-over';
      g.fillStyle = '#000'; g.fillRect(0, 0, W, H);
      g.globalCompositeOperation = 'lighter';
      for (const [x0, y0, x1, y1, r, R, G, B] of pre.blobs) {
        const cx = (x0 + (x1 - x0) * p) * W, cy = (y0 + (y1 - y0) * p) * H, rad = r * W;
        const gr = g.createRadialGradient(cx, cy, 0, cx, cy, rad * 2);
        [[0, 1], [.25, .78], [.5, .37], [.75, .105], [1, 0]].forEach(([o, v]) => gr.addColorStop(o, `rgba(${R},${G},${B},${v * a})`));
        g.fillStyle = gr; g.fillRect(0, 0, W, H);
      }
      g.globalCompositeOperation = 'source-over';
    } else {
      const f = lightTerms(pre, Math.min(1, p)), [R, G, B] = pre.color, img = g.createImageData(W, H), px = img.data;
      for (let j = 0, o = 0; j < H; j++) for (let i = 0; i < W; i++, o += 4) {
        const [c, w] = f((i + 0.5) / W, (j + 0.5) / W);
        px[o] = 255 * a * (c * R / 255 + w); px[o + 1] = 255 * a * (c * G / 255 + w); px[o + 2] = 255 * a * (c * B / 255 + w); px[o + 3] = 255;
      }
      g.putImageData(img, 0, 0);
    }
    ctx.globalCompositeOperation = 'screen';
    ctx.drawImage(fxCanvas, 0, 0, CW, CH);
    ctx.globalCompositeOperation = 'source-over';
  }
}

// Contoh tampilan efek untuk kartu di panel kiri
function fxSwatch(type) {
  const pre = EFFECTS[type], [R, G, B] = pre.color || [255, 255, 255], c = a => `rgba(${R},${G},${B},${a})`;
  switch (pre.kind) {
    case 'flash': return `background:radial-gradient(circle at 50% 50%, #fff 0, ${c(.6)} 45%, #2a2d33 100%)`;
    case 'dip': return 'background:linear-gradient(90deg, #3a3f47, #000 50%, #3a3f47)';
    case 'sweep': return `background:linear-gradient(${Math.round(90 + pre.angle * 57.3)}deg, #15171b 28%, ${c(.5)} 44%, #fff 50%, ${c(.5)} 56%, #15171b 72%)`;
    case 'anamorphic': return `background:radial-gradient(ellipse 70% 5% at 50% 45%, #fff 0, ${c(.95)} 35%, transparent 100%),`
      + `radial-gradient(circle at 50% 45%, ${c(.55)} 0, transparent 38%), radial-gradient(circle at 70% 52%, ${c(.5)} 0, transparent 7%), #0d0f13`;
    case 'glow': return 'background:radial-gradient(circle at 50% 55%, #f6efff 0, rgba(205,185,255,.45) 32%, #15171b 75%)';
    case 'zoom': return 'background:repeating-radial-gradient(circle at 50% 50%, #15171b 0 9%, #4a505a 10% 12%, #15171b 13% 18%)';
    case 'shake': return 'background:repeating-linear-gradient(100deg, #2a2d33 0 5px, #15171b 5px 11px)';
    case 'vignette': return 'background:radial-gradient(ellipse at center, #6a707a 0, #2a2d33 45%, #000 100%)';
    case 'grade': return 'background:linear-gradient(180deg, #7fb2e5 0%, #c9dcef 46%, #e7b07a 47%, #4c3a2c 100%);filter:' + gradeCss(pre);
  }
  return 'background:' + pre.blobs.map(([x0, y0, x1, y1, r, R, G, B]) =>
    `radial-gradient(circle at ${Math.round(((x0 + x1) / 2) * 100)}% ${Math.round(((y0 + y1) / 2) * 100)}%, rgba(${R},${G},${B},.95) 0, rgba(${R},${G},${B},0) ${Math.round(r * 130)}%)`).join(',') + ', #15171b';
}

let fxPreset = null;
// Filter (grading): CSS filter + lapisan warna soft light untuk contoh kartu & preview
const gradeCss = (pre, k = 1) => `brightness(${(1 - (1 - (pre.mul ?? 1)) * k) * (1 + pre.eq[0] * k * 1.6)}) contrast(${1 + (pre.eq[1] - 1) * k}) saturate(${1 + (pre.eq[2] - 1) * k})`;
function renderFxList() {
  const tile = ([k, pre]) => pre.kind === 'grade'
    ? `<button class="fxt ${fxPreset === k ? 'on' : ''}" data-fx="${k}"><span class="sw3 flt" style="filter:${gradeCss(pre)}">${pre.tint ? `<i style="background:rgba(${pre.tint.slice(0, 3).join(',')},${pre.tint[3] * 1.6})"></i>` : ''}</span><span>${esc(pre.label)}</span><small>Sepanjang clip</small></button>`
    : `<button class="fxt ${fxPreset === k ? 'on' : ''}" data-fx="${k}"><span class="sw3" style="${fxSwatch(k)}"></span><span>${esc(pre.label)}</span><small>${pre.dur} dtk</small></button>`;
  document.querySelectorAll('[data-fxtab]').forEach(b => b.classList.toggle('on', b.dataset.fxtab === fxTab));
  $('fx-grid').innerHTML = Object.entries(EFFECTS).filter(([, p]) => (p.kind === 'grade') === (fxTab === 'grade')).map(tile).join('');
}
// Tab menu: Efek (transisi & cahaya) atau Filter (color grading); diingat browser
let fxTab = 'fx';
try { if (localStorage.getItem('ed-fxtab') === 'grade') fxTab = 'grade'; } catch {}
$('fx-tabs').addEventListener('click', e => {
  const b = e.target.closest('[data-fxtab]');
  if (!b || b.dataset.fxtab === fxTab) return;
  fxTab = b.dataset.fxtab;
  try { localStorage.setItem('ed-fxtab', fxTab); } catch {}
  fxPreset = null;
  $('fx-add').disabled = true;
  renderFxList();
});

function addEffect(type) {
  if (!E || !$('cv')) return toast('Tunggu editor selesai dimuat.');
  const [t0, t1] = E.trim, pre = EFFECTS[type];
  // Filter langsung sepanjang clip; efek mulai dari playhead sesuai durasi bawaannya
  const s = pre.kind === 'grade' ? t0 : Math.max(t0, Math.min(t1 - 0.3, t));
  const e = pre.kind === 'grade' ? t1 : Math.min(t1, s + pre.dur);
  E.effects.push({ id: Math.random().toString(36).slice(2, 10), type, s: round3(s), e: round3(e), intensity: pre.kind === 'grade' ? (pre.k ?? 80) : 100 });
  pause();
  changed();
  select({ type: 'effect', i: E.effects.length - 1 });
}

function removeEffect(i) {
  E.effects.splice(i, 1);
  changed();
  select({ type: 'clip' });
}

$('fx-grid').addEventListener('click', e => {
  const b = e.target.closest('[data-fx]');
  if (!b) return;
  fxPreset = fxPreset === b.dataset.fx ? null : b.dataset.fx;
  renderFxList();
  $('fx-add').disabled = !fxPreset;
});
$('fx-add').onclick = () => { if (fxPreset) addEffect(fxPreset); };
renderFxList();

/* ---------- Potong (cut) item terpilih di posisi playhead ---------- */
let tlTapAt = 0;
// Segmen video = potongan trim yang dibatasi titik potong
function videoSegments() {
  const pts = [E.trim[0], ...E.cuts.filter(c => c > E.trim[0] && c < E.trim[1]), E.trim[1]];
  return pts.slice(0, -1).map((a, i) => [a, pts[i + 1]]);
}
const segAt = time => videoSegments().find(([a, b]) => time >= a && time < b) || videoSegments().at(-1);
const gapAt = time => (E.gaps || []).find(([a, b]) => time >= a && time < b - 0.01) || null;

function videoMarks() {
  const cuts = E.cuts.filter(c => c > E.trim[0] && c < E.trim[1]).map(c => `<span class="sc-cut" style="left:${x(c)}px"></span>`).join('');
  const gaps = E.gaps.map(([a, b]) => `<span class="sc-gap" style="left:${x(a)}px;width:${x(b) - x(a)}px"></span>`).join('');
  let seg = '';
  if (sel.type === 'video') { const [a, b] = segAt(sel.at); seg = `<span class="sc-seg" style="left:${x(a)}px;width:${x(b) - x(a)}px"></span>`; }
  return gaps + cuts + seg;
}

// Kata yang titik tengahnya sesudah playhead masuk ke potongan kanan
const splitIndex = (l, time) => { const k = l.words.findIndex(w => (w.s + w.e) / 2 >= time); return k < 0 ? l.words.length : k; };

// Bisa dipotong kalau playhead berada di dalam item terpilih (tidak terlalu dekat ujungnya)
function cutTarget() {
  if (!E) return null;
  const m = 0.1;
  if (sel.type === 'line' && E.lines[sel.i]) {
    const l = E.lines[sel.i], k = splitIndex(l, t);
    return t > l.s + m && t < l.e - m && k > 0 && k < l.words.length ? 'line' : null;
  }
  if (['media', 'text', 'effect', 'music'].includes(sel.type) && trackArr(sel.type)[sel.i]) {
    const it = trackArr(sel.type)[sel.i], lt = itemT(it);
    return lt !== null && lt > it.s + m && lt < it.e - m ? sel.type : null;
  }
  if (sel.type === 'video') {
    return t > E.trim[0] + m && t < E.trim[1] - m && !E.cuts.some(c => Math.abs(c - t) < m) ? 'video' : null;
  }
  return null;
}
function updateCutBtn() { const b = $('tool-cut'); if (b) b.disabled = !cutTarget(); }

function cutAtPlayhead() {
  const kind = cutTarget();
  if (!kind) return toast(sel.type === 'clip' || sel.type === 'lines' ? 'Pilih item di timeline dulu, lalu taruh playhead di tengahnya.' : 'Taruh playhead di tengah item yang dipilih.');
  pause();
  const at = round3(t);
  if (kind === 'line') {
    const l = E.lines[sel.i], k = splitIndex(l, at);
    const a = l.words.slice(0, k), b = l.words.slice(k);
    E.lines.splice(sel.i, 1, { s: a[0].s, e: a.at(-1).e, words: a }, { s: b[0].s, e: b.at(-1).e, words: b });
    sel = { type: 'line', i: sel.i + 1 };
  } else if (kind === 'media' || kind === 'text' || kind === 'effect' || kind === 'music') {
    const arr = trackArr(kind), it = arr[sel.i], at = round3(itemT(it)); // waktu potong dalam satuan item
    const copy = { ...it, id: Math.random().toString(36).slice(2, 10), s: at };
    if (kind === 'music') copy.off = round3(it.off + at - it.s); // potongan kedua melanjutkan lagu dari titik potong
    if (it.gfx) copy.gfx = { ...it.gfx };
    if (it.anim) copy.anim = { ...it.anim };
    it.e = at;
    arr.splice(sel.i + 1, 0, copy);
    sel = { type: kind, i: sel.i + 1 };
  } else {
    E.cuts = [...E.cuts, at].sort((p, q) => p - q);
    sel = { type: 'video', at: at + 0.001 };
  }
  changed();
  select(sel);
  toast('Dipotong di ' + fmtTs(at - E.base[0]));
}

// Hapus / pulihkan segmen video terpilih (bagian yang dihapus dilewati saat diputar & dibuang saat render)
function toggleSegment() {
  const [a, b] = segAt(sel.at);
  if (gapAt((a + b) / 2)) {
    E.gaps = E.gaps.filter(([p, q]) => !(p < b && q > a));
  } else {
    const kept = videoSegments().filter(([p, q]) => !gapAt((p + q) / 2));
    if (kept.length <= 1) return toast(E.cuts.length ? 'Minimal satu segmen video harus tersisa.' : 'Potong video dulu dengan tombol gunting, lalu hapus segmennya.');
    E.gaps = [...E.gaps, [a, b]].sort((p, q) => p[0] - q[0]);
  }
  changed();
  select({ type: 'video', at: sel.at });
}

/* ---------- Teks tambahan: preset (panel kiri), timeline, preview ---------- */
const TEXT_PRESETS = {
  title: { text: 'Judul di sini', font: 'montserrat', size: 96, bold: true, color: '#FFFFFF', stroke: '#000000', stroke_w: 8, bg: '#000000', bg_opacity: 0 },
  body: { text: 'Tulis teks di sini', font: 'inter', size: 60, bold: false, color: '#FFFFFF', stroke: '#000000', stroke_w: 5, bg: '#000000', bg_opacity: 0 },
  box: { text: 'Teks kotak', font: 'inter', size: 56, bold: true, color: '#111111', stroke: '#000000', stroke_w: 0, bg: '#FFFFFF', bg_opacity: 100 },
};

function addText(key) {
  if (!E || !$('cv')) return toast('Tunggu editor selesai dimuat.');
  // Teks baru langsung sepanjang clip; durasinya bisa dipendekkan dengan menarik ujungnya di timeline
  const [t0, t1] = E.trim, pre = TEXT_PRESETS[key];
  E.texts.push({ id: Math.random().toString(36).slice(2, 10), s: round3(t0), e: round3(t1), x: 0.5,
    y: Math.round((960 - pre.size * 0.7) / 1920 * 1e4) / 1e4, align: 'center', italic: false, underline: false, spacing: 0, opacity: 100, ...pre });
  pause();
  changed();
  select({ type: 'text', i: E.texts.length - 1 });
  setTimeout(startInlineEdit, 30); // langsung bisa diketik
}

function removeText(i) {
  E.texts.splice(i, 1);
  changed();
  select({ type: 'clip' });
}

function renderTextList() {
  if (!E) return;
  $('text-info').textContent = E.texts.length || '';
  if (!$('text-list')) return;
  $('text-list').innerHTML = E.texts.length ? E.texts.map((c, i) => `<div class="txt-item ${sel.type === 'text' && sel.i === i ? 'on' : ''}" data-tl="${i}">
      <span class="sw2"></span><span class="tt">${esc(c.text)}</span><small>${fmtTs(c.s - E.base[0])}</small></div>`).join('')
    : '<div class="txt-empty">Belum ada teks tambahan. Pilih salah satu gaya di atas untuk menambahkan.</div>';
}

// Teks tambahan yang aktif di waktu sekarang; elemen dipakai ulang per id
function drawTexts(inClip) {
  const L = $('tlayer');
  if (!L) return;
  const keep = new Set();
  E.texts.forEach((c, i) => {
    keep.add(c.id);
    let el = L.querySelector(`[data-tid="${c.id}"]`);
    if (!el) {
      el = document.createElement('div');
      el.className = 'tbox'; el.dataset.tid = c.id;
      el.appendChild(document.createElement('span'));
      L.appendChild(el);
    }
    el.dataset.ti = i;
    const editing = inlineEd?.kind === 'text' && inlineEd.i === i;
    el.hidden = !editing && (isHidden('text') || !itemOn(c, inClip));
    if (el.hidden) return;
    const sp = el.firstChild, boxed = c.bg_opacity > 0;
    const fx = !editing && c.anim?.text && c.anim.text !== 'none' ? c.anim.text : null;
    if (fx) {
      const key = fx + '|' + c.text;
      if (sp.dataset.fx !== key) { sp.innerHTML = textFxHtml(c.text, fx); sp.dataset.fx = key; }
      paintTextFx(sp, c, itemT(c) ?? t);
    } else if (!editing && (sp.dataset.fx || sp.textContent !== c.text)) {
      delete sp.dataset.fx;
      sp.textContent = c.text;
    }
    applyTextStyle(el, c);
    el.style.lineHeight = boxed ? '1.5' : '1.2';
    el.style.margin = { left: '0 auto 0 0', right: '0 0 0 auto' }[c.align] || '0 auto';
    el.style.top = `calc(${c.y * 1920}px * var(--k))`;
    const A = editing ? animAt(null) : animAt(c.anim, c.s, c.e, itemT(c) ?? t);
    el.style.transform = `translateX(calc(${anchorShift(c)}px * var(--k))) ${animCss(A)}`;
    el.style.transformOrigin = `${{ left: 'left', right: 'right' }[c.align] || 'center'} top`;
    el.style.opacity = A.a;
    const pb = (itemT(c) ?? t) - c.s; // blur menajam saat teks muncul (sama dengan text_fx_body)
    el.style.filter = !editing && c.anim?.blur && pb >= 0 && pb < blurDone(c.anim.smooth) ? `blur(${lerp(SUB_BLUR, 0, tProg(pb, 0, SUB_BLUR_MS, c.anim.smooth)) * $('phone').clientWidth / 1080}px)` : '';
    el.style.zIndex = i;
    sp.style.fontSize = `calc(${c.size}px * var(--k))`;
    sp.style.color = rgba(c.color, c.opacity);
    sp.style.background = boxed ? rgba(c.bg, c.bg_opacity) : 'none';
    sp.style.padding = boxed ? `calc(${c.size * 0.147}px * var(--k)) calc(${c.size * 0.382}px * var(--k))` : '0';
    sp.style.webkitTextStroke = !boxed && c.stroke_w > 0 ? `calc(${c.stroke_w * 2}px * var(--k)) ${c.stroke}` : '0';
  });
  L.querySelectorAll('.tbox').forEach(el => { if (!keep.has(el.dataset.tid)) el.remove(); });
}

function bindTextLayer() {
  const L = $('tlayer');
  L.addEventListener('pointerdown', e => {
    const el = e.target.closest('.tbox');
    if (el) textDragStart(e, el, 'text', +el.dataset.ti);
  });
  L.addEventListener('click', e => {
    const el = e.target.closest('.tbox');
    if (!el) return;
    e.stopPropagation();
    if (justMoved() || inlineEd) return;
    const i = +el.dataset.ti;
    pause();
    if (sel.type !== 'text' || sel.i !== i) select({ type: 'text', i });
  });
  L.addEventListener('dblclick', e => {
    const el = e.target.closest('.tbox');
    if (!el) return;
    const i = +el.dataset.ti;
    if (sel.type !== 'text' || sel.i !== i) select({ type: 'text', i });
    startInlineEdit();
  });
}

// Pilih gaya dulu (klik lagi untuk batal), teks baru ditambahkan lewat tombol +
let textPreset = null;
document.querySelector('.txt-presets').addEventListener('click', e => {
  const b = e.target.closest('[data-tpre]');
  if (!b) return;
  textPreset = textPreset === b.dataset.tpre ? null : b.dataset.tpre;
  document.querySelectorAll('.tpre').forEach(x => x.classList.toggle('on', x.dataset.tpre === textPreset));
  $('text-add').disabled = !textPreset;
});
$('text-add').onclick = () => { if (textPreset) addText(textPreset); };
$('text-list')?.addEventListener('click', e => {
  const it = e.target.closest('[data-tl]');
  if (!it || !E) return;
  const i = +it.dataset.tl;
  pause();
  seek(E.texts[i].s + 0.01);
  select({ type: 'text', i });
});

/* ---------- Gaya teks di preview (sama dengan ASS di subtitles.php) ---------- */
const rgba = (hex, op = 100) => { const n = parseInt(hex.slice(1), 16); return `rgba(${n >> 16},${n >> 8 & 255},${n & 255},${op / 100})`; };
// Ketebalan asli terpasang untuk font itu (selain itu 0 = font bawaan)
const fontWeightOf = c => (FONTS.find(f => f.k === (c.font || 'arial-black'))?.w || []).includes(c.weight) ? c.weight : 0;
function applyTextStyle(el, c) {
  const w = fontWeightOf(c);
  el.style.fontFamily = w ? `"${FONTS.find(f => f.k === c.font).wf}", ${fontFamily(c.font)}` : fontFamily(c.font);
  el.style.fontWeight = w || (c.bold ? '700' : '400');
  el.style.fontStyle = c.italic ? 'italic' : 'normal';
  el.style.textDecoration = c.underline ? 'underline' : 'none';
  el.style.letterSpacing = `calc(${c.spacing ?? 0}px * var(--k))`;
  el.style.textAlign = c.align || 'center';
}
// x = titik jangkar sesuai perataan (kiri: tepi kiri, tengah: titik tengah, kanan: tepi kanan), dalam pecahan lebar 1080.
// Tanpa geser, teks rata kiri menempel di tepi kiri area teks (70px), rata kanan di tepi kanan (1010px).
const anchorX = c => c.x ?? ({ left: 70, right: 1010 }[c.align] ?? 540) / 1080;
const anchorShift = c => anchorX(c) * 1080 - ({ left: 70, right: 1010 }[c.align] ?? 540);

/* ---------- Geser posisi subtitle & hook di preview ---------- */
// Area aman (koordinat video 1080x1920) seperti panduan story Instagram; teks tidak bisa keluar dari sini
const SAFE = { l: 64, r: 1016, t: 192, b: 1728 }, SNAP = 22;
const hookTop = () => E.hook.y != null ? E.hook.y * 1920 : (frameGeom().blur ? 330 : 230);
let movedAt = 0;
const justMoved = () => performance.now() - movedAt < 250;

function bindTextDrag(el, kind) {
  el.addEventListener('pointerdown', e => textDragStart(e, el, kind));
}
// kind: 'line' (subtitle), 'hook', 'text' (teks tambahan ke-idx)
function textDragStart(e, el, kind, idx) {
  {
    if (e.button !== 0 || inlineEd || E.pending && kind === 'line') return;
    if (kind === 'line' && activeLine() < 0) return;
    const k = $('phone').clientWidth / 1080;
    const box = (kind === 'line' ? el.querySelector('.sbox') : el.querySelector('span')) || el;
    const r = box.getBoundingClientRect(), o = $('ovl').getBoundingClientRect();
    // Ukuran & posisi awal teks dalam koordinat video
    const w = r.width / k, h = r.height / k;
    const cx0 = (r.left - o.left) / k + w / 2;
    const top0 = (r.top - o.top) / k;
    // Selisih antara kotak teks yang terlihat dan elemen yang diposisikan (subtitle: tepi bawah, hook: tepi atas)
    const er = el.getBoundingClientRect();
    const off = kind === 'line' ? (er.bottom - r.bottom) / k : (er.top - r.top) / k;
    const sx = e.clientX, sy = e.clientY;
    let moving = false;
    const lineI = activeLine();
    el.setPointerCapture(e.pointerId);
    const move = ev => {
      const dx = (ev.clientX - sx) / k, dy = (ev.clientY - sy) / k;
      if (!moving) {
        if (Math.hypot(dx, dy) < 4 / k) return;
        moving = true;
        pause();
        if (kind === 'line' && (sel.type !== 'line' || sel.i !== lineI)) select({ type: 'line', i: lineI });
        if (kind === 'hook' && sel.type !== 'hook') select({ type: 'hook' });
        if (kind === 'text' && (sel.type !== 'text' || sel.i !== idx)) select({ type: 'text', i: idx });
        document.body.classList.add('moving');
        $('guides').classList.add('on');
      }
      let cx = cx0 + dx, top = top0 + dy;
      const hit = {};
      // Magnet ke garis tengah (vertikal & horizontal)
      if (Math.abs(cx - 540) < SNAP) { cx = 540; hit.cx = 1; }
      if (Math.abs(top + h / 2 - 960) < SNAP) { top = 960 - h / 2; hit.cy = 1; }
      // Mentok di batas area aman
      if (cx - w / 2 <= SAFE.l) { cx = SAFE.l + w / 2; hit.l = 1; }
      if (cx + w / 2 >= SAFE.r) { cx = SAFE.r - w / 2; hit.r = 1; }
      if (top <= SAFE.t) { top = SAFE.t; hit.t = 1; }
      if (top + h >= SAFE.b) { top = SAFE.b - h; hit.b = 1; }
      if (w >= SAFE.r - SAFE.l) { cx = 540; hit.cx = 1; }
      $('guides').querySelectorAll('.gl').forEach(g => g.classList.toggle('show', !!hit[g.classList[2]]));
      const al = (kind === 'line' ? E.style : kind === 'text' ? E.texts[idx] : E.hook).align || 'center';
      const ax = al === 'left' ? cx - w / 2 : al === 'right' ? cx + w / 2 : cx;
      if (kind === 'line') {
        E.style.x = Math.round(ax / 1080 * 1e4) / 1e4;
        E.style.pos = Math.round((top + h + off) / 1920 * 1e4) / 1e4;
      } else {
        const c = kind === 'text' ? E.texts[idx] : E.hook;
        c.x = Math.round(ax / 1080 * 1e4) / 1e4;
        c.y = Math.round((top + off) / 1920 * 1e4) / 1e4;
      }
      draw();
    };
    const up = () => {
      el.removeEventListener('pointermove', move);
      el.removeEventListener('pointerup', up);
      el.removeEventListener('pointercancel', up);
      if (!moving) return;
      movedAt = performance.now();
      document.body.classList.remove('moving');
      $('guides').classList.remove('on');
      $('guides').querySelectorAll('.gl').forEach(g => g.classList.remove('show'));
      changed();
      renderProps();
    };
    el.addEventListener('pointermove', move);
    el.addEventListener('pointerup', up);
    el.addEventListener('pointercancel', up);
  }
}

/* ---------- Seleksi & edit teks langsung di preview ---------- */
let inlineEd = null; // { kind: 'line' | 'hook', i, el }

// Bingkai seleksi mengikuti teks yang dipilih (subtitle baris aktif atau hook) selama teks itu tampil
// Nilai yang diubah saat kotak seleksi diperbesar / diperkecil
function resizeTarget() {
  if (sel.type === 'media' && E.media[sel.i] && !isLocked('media')) {
    const m = E.media[sel.i];
    return { get: () => m.w, set: v => { m.w = Math.round(v * 1e4) / 1e4; }, min: 0.05, max: 1.5 };
  }
  if (sel.type === 'text' && E.texts[sel.i] && !isLocked('text')) {
    const c = E.texts[sel.i];
    return { get: () => c.size, set: v => { c.size = Math.round(v); }, min: 20, max: 200 };
  }
  if (sel.type === 'hook') return { get: () => E.hook.size, set: v => { E.hook.size = Math.round(v); }, min: 30, max: 140 };
  if (sel.type === 'line' && !isLocked('sub')) return { get: () => E.style.size, set: v => { E.style.size = Math.round(v); }, min: 40, max: 180 };
  return null;
}

function placeSelbox(activeIdx, hookShown) {
  const box = $('selbox');
  if (!box) return;
  let el = null;
  if (inlineEd) el = inlineEd.el;
  else if (sel.type === 'line' && sel.i === activeIdx && !$('sub').hidden) el = $('sub').querySelector('.sbox');
  else if (sel.type === 'hook' && hookShown) el = $('hook');
  else if (sel.type === 'media') el = $('mlayer').querySelector(`.mimg[data-mi="${sel.i}"]:not([hidden])`);
  else if (sel.type === 'text') el = $('tlayer').querySelector(`.tbox[data-ti="${sel.i}"]:not([hidden]) span`);
  box.classList.toggle('media', sel.type === 'media');
  box.classList.toggle('gfx', sel.type === 'media' && !!E.media[sel.i]?.gfx);
  if (!el) { box.hidden = true; return; }
  const o = $('ovl').getBoundingClientRect(), r = el.getBoundingClientRect();
  const pad = 6;
  box.hidden = false;
  box.style.left = (r.left - o.left - pad) + 'px';
  box.style.top = (r.top - o.top - pad) + 'px';
  box.style.width = (r.width + pad * 2) + 'px';
  box.style.height = (r.height + pad * 2) + 'px';
  box.classList.toggle('below', r.top - o.top < 54);
  box.classList.toggle('editing', !!inlineEd);
}

function startInlineEdit() {
  if (inlineEd) return;
  let el, text, kind;
  if (sel.type === 'line' && E.lines[sel.i]) {
    kind = 'line';
    if (activeLine() !== sel.i) seek(E.lines[sel.i].s + 0.01);
    el = $('sub');
    text = E.lines[sel.i].words.map(w => w.w).join(' ');
    el.innerHTML = `<span class="sbox">${esc(text)}</span>`;
    el = el.querySelector('.sbox');
  } else if (sel.type === 'text' && E.texts[sel.i]) {
    kind = 'text';
    const c = E.texts[sel.i];
    if (t < c.s || t >= c.e) seek(c.s + 0.01);
    draw();
    el = $('tlayer').querySelector(`.tbox[data-ti="${sel.i}"] span`);
    if (!el) return;
    text = c.text;
    el.textContent = text;
  } else if (sel.type === 'hook') {
    kind = 'hook';
    el = $('hook').querySelector('span') || $('hook').appendChild(document.createElement('span'));
    text = E.hook.text;
    el.textContent = text;
  } else return;
  pause();
  inlineEd = { kind, i: sel.i, el, orig: text };
  if (kind !== 'text') $(kind === 'line' ? 'sub' : 'hook').hidden = false;
  try { el.contentEditable = 'plaintext-only'; } catch { el.contentEditable = 'true'; }
  if (el.contentEditable !== 'plaintext-only') el.contentEditable = 'true';
  el.focus();
  const rg = document.createRange(); rg.selectNodeContents(el);
  const s = getSelection(); s.removeAllRanges(); s.addRange(rg);
  el.onkeydown = e => {
    e.stopPropagation();
    if (e.key === 'Enter') { e.preventDefault(); endInlineEdit(true); }
    if (e.key === 'Escape') { e.preventDefault(); endInlineEdit(false); }
  };
  el.oninput = () => {
    const lim = kind === 'hook' ? 120 : kind === 'text' ? 200 : 0;
    if (lim && el.textContent.length > lim) { el.textContent = el.textContent.slice(0, lim); }
    placeSelbox(-1, true);
  };
  el.onblur = () => endInlineEdit(true);
  placeSelbox(-1, true);
}

function endInlineEdit(save) {
  const ed = inlineEd;
  if (!ed) return;
  inlineEd = null;
  ed.el.onblur = ed.el.onkeydown = ed.el.oninput = null;
  ed.el.removeAttribute('contenteditable');
  const text = ed.el.textContent.replace(/\s+/g, ' ').trim();
  if (save && text !== ed.orig) {
    if (ed.kind === 'line') {
      if (text) setLineText(ed.i, text);
      else { sel = { type: 'line', i: ed.i }; return lineAction('delete'); }
    } else if (ed.kind === 'text') {
      if (!text) return removeText(ed.i);
      E.texts[ed.i].text = text.slice(0, 200);
    } else {
      E.hook.text = text.slice(0, 120);
      if (!text) E.hook.enabled = false;
    }
    changed();
    renderProps();
    renderTimeline();
  }
  draw();
}

function deleteSelectedText() {
  if (sel.type === 'line' && E.lines[sel.i]) return lineAction('delete');
  if (sel.type === 'media' && E.media[sel.i]) return removeMedia(sel.i);
  if (sel.type === 'text' && E.texts[sel.i]) return removeText(sel.i);
  if (sel.type === 'video') return toggleSegment();
  if (sel.type === 'effect' && E.effects[sel.i]) return removeEffect(sel.i);
  if (sel.type === 'music' && E.music[sel.i]) return removeMusic(sel.i);
  if (sel.type === 'sfx' && E.sfx[sel.i]) return removeSfx(sel.i);
  if (sel.type === 'footage' && E.footage[sel.i]) return removeFootage(sel.i);
  if (sel.type === 'transition' && E.transitions[sel.id]) return removeTransition(sel.id);
  if (sel.type === 'opening' && E.opening) return setOpening('');
  if (sel.type === 'hook') {
    E.hook.text = '';
    E.hook.enabled = false;
    changed();
    select({ type: 'clip' });
    draw();
    toast('Teks hook dihapus');
  }
}

/* ---------- Pemutaran ---------- */

function seek(time) {
  if (fp) { fp = null; fvid.pause(); if (playing) vid.play(); }
  t = Math.max(E.base[0], Math.min(E.base[1], time));
  vid.currentTime = t - E.pad[0];
  updateTime();
  draw();
}

// Volume preview: sampai 100% cukup lewat vid.volume; di atasnya pakai GainNode (Web Audio) supaya terdengar sama dengan hasil render
let audioCtx = null;

// Volume tetap per clip / per musik (fitur keyframe volume sudah dihapus)
const clipVol = () => E.volume ?? 100;
const musicVol = m => m.vol;

function applyVolume() {
  if (!E || !vid) return;
  fvid.muted = isMuted();
  const v = isMuted() ? 0 : clipVol(t) / 100;
  if (v <= 1 && !vid._gain) { vid.volume = v; return; }
  try {
    if (!vid._gain) {
      audioCtx = audioCtx || new (window.AudioContext || window.webkitAudioContext)();
      const g = audioCtx.createGain();
      audioCtx.createMediaElementSource(vid).connect(g).connect(audioCtx.destination);
      vid._gain = g;
    }
    if (audioCtx.state === 'suspended') audioCtx.resume();
    vid.volume = 1;
    vid._gain.gain.value = v;
  } catch { vid.volume = Math.min(1, v); }
}

function togglePlay() { playing ? pause() : play(); }

// Urutan putar: footage awal → clip (dalam trim, bagian terhapus dilewati) → footage akhir
function play() {
  fitRange();
  if (prange.on && (curV() < prange.a || curV() >= prange.b - 0.05)) seekV(prange.a);
  else if (curV() >= totalDur() - 0.06) seekV(0);
  if (!fp && t < E.trim[0]) seek(E.trim[0]);
  if (!fp && t >= E.trim[1] - 0.05) { const f = postF()[0]; if (f) setFoot(E.footage.indexOf(f), 0); else seek(E.trim[0]); }
  playing = true;
  lastTick = performance.now();
  stopMusicPreview();
  applyVolume();
  if (!fp) vid.play();
  else if (E.footage[fp.i].kind === 'video') fvid.play().catch(() => {});
  $('play').innerHTML = ic('pause');
}

function pause() {
  playing = false;
  vid.pause();
  fvid.pause();
  if ($('play')) $('play').innerHTML = ic('play');
}

function updateTime() {
  $('time').textContent = `${fmtTs(curV())} / ${fmtTs(totalDur())}`;
  const ph = $('playhead');
  if (ph) ph.style.left = curV() * pps + 'px';
  // Saat diputar dalam keadaan diperbesar, timeline ikut bergeser mengikuti playhead
  const v = $('tl-view');
  if (v && playing && v.scrollWidth > v.clientWidth) {
    const px = curV() * pps;
    if (px > v.scrollLeft + v.clientWidth - 40 || px < v.scrollLeft) v.scrollLeft = px - 40;
  }
  updateCutBtn();
}

/* ---------- Rentang putar: play hanya di antara dua penanda di penggaris waktu (posisi virtual) ---------- */
// Bawaan: mengikuti seluruh durasi timeline. Begitu digeser / diset lewat shortcut, rentang jadi "custom".
const prange = { on: true, a: 0, b: 0, custom: false };
function fitRange() {
  const total = totalDur();
  if (!prange.custom) { prange.a = 0; prange.b = total; return; }
  prange.b = Math.min(total, prange.b);
  prange.a = Math.max(0, Math.min(prange.a, prange.b - 0.1));
}
function paintRangeBtn() {
  const b = $('tool-range');
  if (!b) return;
  b.classList.toggle('on', prange.custom);
  b.disabled = !prange.custom;
}
// Kembalikan rentang ke seluruh durasi
function resetRange() {
  prange.custom = false;
  paintRangeBtn();
  renderTimeline();
}
// ⌘/Ctrl + B (Begin): awal rentang ke playhead · ⌘/Ctrl + E (End): akhir rentang ke playhead
function setRangeEdge(edge) {
  if (!E) return;
  fitRange();
  const v = Math.max(0, Math.min(totalDur(), curV()));
  prange.custom = true;
  if (edge === 'a') { prange.a = v; if (prange.b - v < 0.1) prange.b = totalDur(); }
  else { prange.b = v; if (v - prange.a < 0.1) prange.a = 0; }
  const short = prange.b - prange.a < 0.1;
  if (short) prange.custom = false;
  paintRangeBtn();
  renderTimeline();
  if (short) return toast('Rentang terlalu pendek');
  toast(edge === 'a' ? `Awal rentang putar: ${fmtTs(prange.a)}` : `Akhir rentang putar: ${fmtTs(prange.b)}`);
}

/* ---------- Footage tambahan (awal / akhir clip) ---------- */
// Waktu "virtual" timeline: footage awal, lalu rentang video (base), lalu footage akhir
const preF = () => E.footage.filter(f => f.pos === 'pre');
const postF = () => E.footage.filter(f => f.pos !== 'pre');
const sumDur = list => list.reduce((a, f) => a + f.dur, 0);
const preDur = () => sumDur(preF());
const baseDur = () => E.base[1] - E.base[0];
const totalDur = () => preDur() + baseDur() + sumDur(postF());
function footStart(f) {
  let v = f.pos === 'pre' ? 0 : preDur() + baseDur();
  for (const g of f.pos === 'pre' ? preF() : postF()) { if (g === f) break; v += g.dur; }
  return v;
}
const curV = () => fp && E.footage[fp.i] ? footStart(E.footage[fp.i]) + fp.at : preDur() + t - E.base[0];
const footUrl = f => 'previews/overlays/' + f.file;
const footThumb = f => 'previews/overlays/' + (f.kind === 'video' ? f.file.replace(/\.\w+$/, '.thumb.jpg') : f.file);

/* ---------- Item yang menempel ke footage / intro (media, grafik, teks, efek, musik) ----------
   Item biasa memakai detik video asli (s/e) dan hanya ada di rentang clip. Item yang digeser ke bagian footage punya ref = id footage
   dan s/e = detik dari awal footage itu di video akhir, jadi bisa mulai di detik 0 video & berlanjut melewati sambungan ke clip. */
// Video akhir: footage awal → bagian clip yang dipertahankan → footage akhir
const keptBefore = tm => (tm - E.trim[0]) - (E.gaps || []).reduce((a, [p, q]) => a + Math.max(0, Math.min(q, tm) - Math.max(p, E.trim[0])), 0);
const outTotal = () => preDur() + keptDur() + sumDur(postF());
function segStartO(id) {
  if (id === 'main') return preDur();
  const f = E.footage.find(g => g.id === id);
  if (!f) return null;
  return f.pos === 'pre' ? footStart(f) : preDur() + keptDur() + footStart(f) - preDur() - baseDur();
}
// Detik video akhir di posisi putar sekarang (null = di luar video akhir, mis. bagian clip di luar trim)
const outNow = () => fp && E.footage[fp.i] ? segStartO(E.footage[fp.i].id) + fp.at
  : t < E.trim[0] - 0.01 || t > E.trim[1] + 0.01 ? null : preDur() + keptBefore(Math.max(E.trim[0], Math.min(E.trim[1], t)));
// Timeline editor (virtual: footage + seluruh rentang clip termasuk bagian di luar trim) ↔ video akhir
function vOfOut(o) {
  const P = preDur(), K = keptDur();
  if (o <= P) return o;
  if (o <= P + K) return P + E.trim[0] - E.base[0] + (o - P);
  return P + baseDur() + (o - P - K);
}
function outOfV(v) {
  const P = preDur(), B = baseDur();
  if (v < P) return v;
  if (v <= P + B) return P + keptBefore(Math.max(E.trim[0], Math.min(E.trim[1], E.base[0] + v - P)));
  return P + keptDur() + (v - P - B);
}
// Rentang item di timeline editor (detik virtual)
function itemV(m) {
  if (!m.ref) return [preDur() + Math.max(m.s, E.base[0]) - E.base[0], preDur() + Math.min(m.e, E.base[1]) - E.base[0]];
  const o = segStartO(m.ref);
  return o === null ? [0, 0] : [vOfOut(o + m.s), vOfOut(o + m.e)];
}
// Waktu item (satuan s/e item) di posisi putar sekarang; null = item ini tidak sedang berjalan di bagian ini
function itemT(m) {
  if (!m.ref) return fp ? null : t;
  const o = outNow(), b = segStartO(m.ref);
  return o === null || b === null ? null : o - b;
}
const itemOn = (m, inClip) => { if (!m.ref) return inClip && inSpan(m.s, m.e); const lt = itemT(m); return lt !== null && lt >= m.s && lt < m.e; };
// Posisi timeline v → tempelan: di rentang clip = detik video asli, di footage = ref + detik dari awal footage itu
function itemAnchor(v) {
  const P = preDur(), B = baseDur();
  if (v >= P && v <= P + B) return { ref: null, s: E.base[0] + v - P };
  const a = sfxAnchor(v);
  return { ref: a.ref, s: a.at };
}
// Batas waktu item yang menempel ke footage (dalam satuan item): seluruh video akhir
const refRange = m => { const b = segStartO(m.ref) ?? 0; return [-b, outTotal() - b]; };
const refNoteHtml = `<p class="muted small" style="margin:10px 0 0">Item ini ada di bagian intro / footage. Geser bloknya di timeline untuk mengatur posisi & durasi.</p>`;

// Pindah ke posisi virtual v (detik dari awal timeline)
function seekV(v) {
  const P = preDur(), B = baseDur();
  const list = v < P ? preF() : v > P + B ? postF() : [];
  if (list.length) {
    let s = v < P ? 0 : P + B;
    for (const f of list) {
      if (v < s + f.dur || f === list.at(-1)) return setFoot(E.footage.indexOf(f), Math.max(0, Math.min(f.dur - 0.02, v - s)));
      s += f.dur;
    }
  }
  seek(E.base[0] + v - P);
}

const footImgs = {};
function footEl(f) {
  if (f.kind === 'video') {
    if (fvid.dataset.f !== f.file) { fvid.dataset.f = f.file; fvid.src = footUrl(f); }
    return fvid;
  }
  let im = footImgs[f.file];
  if (!im) { im = footImgs[f.file] = new Image(); im.onload = () => draw(); im.src = footUrl(f); }
  return im;
}

function setFoot(i, at) {
  const f = E.footage[i];
  if (!f) return;
  if (!fp) vid.pause();
  else if (fp.i !== i) fvid.pause();
  fp = { i, at };
  const el = footEl(f);
  if (f.kind === 'video') {
    if (el.readyState >= 1) el.currentTime = at;
    if (playing) el.play().catch(() => {});
  }
  updateTime();
  draw();
}

// Footage dipaskan utuh di tengah layar 9:16, sisa layar diisi versi blur-nya (sama dengan hasil render)
function drawFootage() {
  const f = E.footage[fp.i];
  ctx.fillStyle = '#000';
  ctx.fillRect(0, 0, cv.width, cv.height);
  if (f && !isHidden('video')) fitBlur(ctx, footEl(f), cv.width, cv.height);
}

// Footage dipaskan utuh di tengah layar 9:16, sisa layar diisi versi blur-nya (sama dengan hasil render)
function fitBlur(g, el, CW, CH) {
  const w = el.videoWidth ?? el.naturalWidth, h = el.videoHeight ?? el.naturalHeight;
  if (!w || !h || (el.tagName === 'VIDEO' && el.readyState < 2)) return;
  const sc = Math.max(CW / w, CH / h), sf = Math.min(CW / w, CH / h);
  g.filter = 'blur(16px)';
  g.drawImage(el, (CW - w * sc) / 2, (CH - h * sc) / 2, w * sc, h * sc);
  g.filter = 'none';
  g.drawImage(el, (CW - w * sf) / 2, (CH - h * sf) / 2, w * sf, h * sf);
}

/* ---------- Transisi di sambungan footage / clip ---------- */
// Urutan putar: footage awal, 'main' (clip), footage akhir. Transisi disimpan per item tujuan (id footage / 'main')
const seqItems = () => [...preF(), 'main', ...postF()];
const itemId = it => it === 'main' ? 'main' : it.id;
const keptDur = () => E.trim[1] - E.trim[0] - (E.gaps || []).reduce((a, [p, q]) => a + Math.max(0, Math.min(q, E.trim[1]) - Math.max(p, E.trim[0])), 0);
const itemLen = it => it === 'main' ? keptDur() : it.dur;
const junctions = () => { const s = seqItems(); return s.slice(1).map((b, k) => ({ a: s[k], b, id: itemId(b) })); };
const trDur = j => Math.min(E.transitions[j.id].dur, itemLen(j.a), itemLen(j.b));
const junctionX = j => (j.b === 'main' ? preDur() : j.a === 'main' ? preDur() + baseDur() : footStart(j.b)) * pps;
let trPreset = 'fade';

// Transisi yang sedang berjalan di posisi putar: sisi "live" diputar biasa, sisi lain memakai frame diamnya
// (sama dengan render: kedua sisi diperpanjang setengah durasi transisi dengan frame diam)
function trState() {
  if (!E?.footage.length) return null;
  const s = seqItems(), cur = fp ? E.footage[fp.i] : 'main', k = s.indexOf(cur);
  if (k < 0 || (!fp && (t < E.trim[0] || t > E.trim[1]))) return null;
  const el = fp ? fp.at : t - E.trim[0], rem = fp ? cur.dur - fp.at : E.trim[1] - t;
  const out = s[k + 1] && { a: cur, b: s[k + 1], id: itemId(s[k + 1]) };
  if (out && E.transitions[out.id]) { const d = trDur(out); if (rem < d / 2) return { j: out, p: (d / 2 - rem) / d, liveIsA: true }; }
  const inn = k > 0 && { a: s[k - 1], b: cur, id: itemId(cur) };
  if (inn && E.transitions[inn.id]) { const d = trDur(inn); if (el < d / 2) return { j: inn, p: 0.5 + el / d, liveIsA: false }; }
  return null;
}

// Frame diam (awal / akhir) sebuah item, digambar seperti di preview; disimpan di cache, dibuat di latar belakang
const trFrames = new Map();
function staticFrame(it, which) {
  let key, make;
  if (it === 'main') {
    const time = which === 'first' ? E.trim[0] + 0.02 : E.trim[1] - 0.05;
    key = `main:${IID}:${time}:${E.layout}:${E.split.enabled}`;
    make = c => grabFrame(meta.proxy, time - E.pad[0], v => { const g2 = c.getContext('2d'); paintMain(g2, v, time, c.width, c.height); drawFrameMask(g2, c.width, c.height); });
  } else if (it.kind === 'image') {
    const im = footEl(it);
    if (!im.naturalWidth) return null;
    key = 'img:' + it.file;
    make = async c => fitBlur(c.getContext('2d'), im, c.width, c.height);
  } else {
    const time = which === 'first' ? 0.02 : Math.max(0, it.dur - 0.05);
    key = `vid:${it.file}:${time}`;
    make = c => grabFrame(footUrl(it), time, v => fitBlur(c.getContext('2d'), v, c.width, c.height));
  }
  const hit = trFrames.get(key);
  if (hit) return hit.ready ? hit.c : null;
  const c = document.createElement('canvas');
  c.width = cv.width; c.height = cv.height;
  const rec = { c, ready: false };
  trFrames.set(key, rec);
  make(c).then(() => { rec.ready = true; draw(); }).catch(() => trFrames.delete(key));
  return null;
}
async function grabFrame(url, time, paint) {
  const v = document.createElement('video');
  v.muted = true; v.preload = 'auto'; v.playsInline = true; v.src = url;
  try {
    await once(v, 'loadeddata');
    v.currentTime = Math.max(0, time);
    await once(v, 'seeked');
    if (!v.videoWidth) throw new Error('frame');
    paint(v);
  } finally { v.removeAttribute('src'); v.load(); }
}

// Campur frame live (di canvas) dengan frame diam sesuai jenis transisi; p 0..1 dari A ke B
const trBuf = document.createElement('canvas');
function composeTransition({ j, p, liveIsA }) {
  const other = staticFrame(liveIsA ? j.b : j.a, liveIsA ? 'first' : 'last');
  if (!other) return;
  const CW = cv.width, CH = cv.height;
  trBuf.width = CW; trBuf.height = CH;
  trBuf.getContext('2d').drawImage(cv, 0, 0);
  const A = liveIsA ? trBuf : other, B = liveIsA ? other : trBuf, g = ctx;
  g.save();
  g.clearRect(0, 0, CW, CH);
  switch (E.transitions[j.id].type) {
    case 'fadeblack': case 'fadewhite':
      g.drawImage(p < 0.5 ? A : B, 0, 0);
      g.fillStyle = E.transitions[j.id].type === 'fadeblack' ? '#000' : '#fff';
      g.globalAlpha = p < 0.5 ? p * 2 : (1 - p) * 2;
      g.fillRect(0, 0, CW, CH);
      break;
    case 'slideleft': g.drawImage(A, -p * CW, 0); g.drawImage(B, (1 - p) * CW, 0); break;
    case 'slideright': g.drawImage(A, p * CW, 0); g.drawImage(B, -(1 - p) * CW, 0); break;
    case 'wipeleft':
      g.drawImage(A, 0, 0);
      g.beginPath(); g.rect((1 - p) * CW, 0, p * CW, CH); g.clip();
      g.drawImage(B, 0, 0);
      break;
    case 'circleopen':
      g.drawImage(A, 0, 0);
      g.beginPath(); g.arc(CW / 2, CH / 2, p * Math.hypot(CW, CH) / 2, 0, Math.PI * 2); g.clip();
      g.drawImage(B, 0, 0);
      break;
    case 'paper': drawPaperTr(g, A, B, p, CW, CH); break;
    case 'zoomin': {
      g.drawImage(B, 0, 0);
      const z = 1 + p;
      g.globalAlpha = 1 - p;
      g.drawImage(A, (CW - CW * z) / 2, (CH - CH * z) / 2, CW * z, CH * z);
      break;
    }
    default:
      g.drawImage(A, 0, 0);
      g.globalAlpha = p;
      g.drawImage(B, 0, 0);
  }
  g.restore();
}

// Tambah transisi di sambungan menuju item id, lalu buka menu Transisi untuk memilih template
function addTransition(id) {
  E.transitions[id] = { type: trPreset, dur: trPreset === 'paper' ? PAPER_DUR : 0.5 };
  changed();
  select({ type: 'transition', id });
  openTrTab();
  previewTransition(id);
}
// Buka menu Transisi di panel kiri tanpa melepas pilihan transisi
function openTrTab(tab = 'join') {
  tlTapAt = performance.now();
  trTab = tab;
  paintTrTab();
  document.querySelector('[data-lp="tr"]')?.click();
}
function removeTransition(id) {
  delete E.transitions[id];
  changed();
  select({ type: 'clip' });
  draw();
}
// Taruh playhead sedikit sebelum transisi dimulai (autoplay = langsung putar)
function previewTransition(id, autoplay) {
  const j = junctions().find(x => x.id === id);
  if (!j) return;
  pause();
  const back = trDur(j) / 2 + 0.6;
  if (j.a === 'main') seek(Math.max(E.trim[0], E.trim[1] - back));
  else setFoot(E.footage.indexOf(j.a), Math.max(0, j.a.dur - back));
  if (autoplay) play();
}

/* ---------- Opening: animasi di awal video. Rumus sama dengan opening_graph() di editor_lib.php ---------- */
const OP_EASE = {
  inout: p => p * p * (3 - 2 * p), out2: p => 1 - (1 - p) ** 2, out3: p => 1 - (1 - p) ** 3, out4: p => 1 - (1 - p) ** 4,
};
const OP_CSS_EASE = { inout: 'ease-in-out', out2: 'cubic-bezier(.33,1,.68,1)', out3: 'cubic-bezier(.22,1,.36,1)', out4: 'cubic-bezier(.16,1,.3,1)' };
const OP_CATS = [['cine', 'Cinematic'], ['zoom', 'Zoom out'], ['fade', 'Lainnya']];
let trTab = 'open';
// Detik di video hasil akhir (0 = awal video, termasuk footage awal)
const outTime = () => fp && E.footage[fp.i] ? footStart(E.footage[fp.i]) + fp.at : preDur() + t - E.trim[0];
// Keadaan opening di detik v: { z: skala zoom, c: bagian tertutup tiap sisi (0..0,5), f: gelap fade } atau null
function openingAt(v) {
  const op = E?.opening, pr = op && OPENINGS[op.type];
  if (!pr || v < 0 || v >= op.dur) return null;
  const p = Math.max(0, Math.min(1, v / op.dur)), ease = OP_EASE[pr.ease] || OP_EASE.inout, L = OP_LETTERBOX;
  const st = { z: 1, c: 0, f: 0 };
  if (pr.zoom) st.z = pr.zoom - (pr.zoom - 1) * ease(p);
  if (pr.bars === 'letter') st.c = p < 0.45 ? 0.5 - (0.5 - L) * ease(p / 0.45) : p < 0.75 ? L : L * (1 - ease((p - 0.75) / 0.25));
  else if (pr.bars) st.c = 0.5 * (1 - ease(p));
  if (pr.fade) st.f = 1 - p;
  return st;
}
function paintOpening() {
  const box = $('opfx');
  if (!box || !E) return;
  const st = openingAt(outTime());
  const z = st && st.z !== 1 ? `scale(${st.z})` : '';
  if (cv.style.transform !== z) { cv.style.transform = z; $('ovl').style.transform = z; }
  box.hidden = !st || (!st.c && !st.f);
  if (box.hidden) return;
  box.querySelector('.t').style.height = box.querySelector('.b').style.height = st.c * 100 + '%';
  box.querySelector('.f').style.opacity = st.f;
}
function opThumb(k) {
  const pr = OPENINGS[k];
  if (!pr) return '<span class="ops none"><i class="v"></i></span>';
  const cls = [pr.bars === 'letter' ? 'bars letter' : pr.bars ? 'bars' : '', pr.zoom ? 'zoom' : '', pr.fade ? 'fade' : ''].join(' ');
  return `<span class="ops ${cls}" style="--od:${Math.max(1.6, pr.dur + 1)}s;--oe:${OP_CSS_EASE[pr.ease] || 'ease-in-out'};--oz:${pr.zoom || 1}"><i class="v"></i><i class="t"></i><i class="b"></i><i class="f"></i></span>`;
}
function renderOpList() {
  const cur = E?.opening?.type || '';
  $('op-grid').innerHTML = `<button class="fxt ${cur ? '' : 'on'}" data-opk="">${opThumb('')}<span>Tanpa opening</span></button>`
    + OP_CATS.map(([c, name]) => `<div class="fx-sec">${name}</div>` + Object.entries(OPENINGS).filter(([, pr]) => pr.cat === c)
      .map(([k, pr]) => `<button class="fxt ${cur === k ? 'on' : ''}" data-opk="${k}">${opThumb(k)}<span>${esc(pr.label)}</span><small>${pr.dur.toFixed(1)} dtk</small></button>`).join('')).join('');
}
function paintTrTab() {
  document.querySelectorAll('[data-trtab]').forEach(b => b.classList.toggle('on', b.dataset.trtab === trTab));
  $('tr-grid').hidden = trTab !== 'join';
  $('op-grid').hidden = trTab !== 'open';
  if (trTab === 'open') renderOpList();
}
// Putar opening dari awal video
function playOpening() {
  pause();
  seekV(0);
  if (!preF().length) seek(E.trim[0]);
  play();
}
function setOpening(k) {
  if (!E) return;
  if (!k) { E.opening = null; changed(); select({ type: 'clip' }); renderOpList(); return draw(); }
  if (E.opening?.type !== k) E.opening = { type: k, dur: OPENINGS[k].dur };
  changed();
  select({ type: 'opening' });
  renderOpList();
  playOpening();
}
/* ---------- Intro video: screenshot clip (dibingkai seperti preview) + grafik hook + suara AI yang membacakannya.
   Hasilnya footage gambar paling depan + transisi kertas robek ke bagian berikutnya. Saat render, footage_concat()
   memakai suara (vo) sebagai audio footage ini & merakit transisi kertasnya. ---------- */
const INTRO_POS = [['Atas', 0.24], ['Tengah', 0.5], ['Bawah', 0.72]];
const INTRO_TPL = Object.keys(GFX).filter(k => !GFX[k].full); // banner selebar layar tidak dipakai di intro
const introFoot = () => E?.footage.find(f => f.intro);
const voUrl = file => `api.php?action=hook_voice_file&id=${PID}&iid=${IID}&f=${encodeURIComponent(file)}`;
// Teks hook bawaan: grafik hook di awal clip, kalau tidak ada hook dari AI
const hookTextDefault = () => (E.media.find(m => /^hook/.test(m.gfx?.type || '') && m.s <= E.trim[0] + 0.1)?.gfx.text || E.hook.text || '').trim();
const introKey = c => JSON.stringify([round3(c.t), c.y, c.w, c.voice, c.style || 'auto', c.script || '', c.gfx.type, c.gfx.text, c.gfx.label || '', c.gfx.c1, c.gfx.c2, c.gfx.c3]);

function introDefaults() {
  const f = introFoot();
  if (f) return { style: 'auto', script: '', ...JSON.parse(JSON.stringify(f.intro)) };
  const voice = defaultVoice();
  const pre = GFX.hook_red, inClip = t >= E.trim[0] && t < E.trim[1] - 0.2;
  return { t: round3(inClip ? t : E.trim[0] + (E.trim[1] - E.trim[0]) * 0.25), y: 0.72, w: 0.87, voice, style: 'auto', script: '',
    gfx: { type: 'hook_red', label: '', text: hookTextDefault() || pre.text, c1: pre.c1, c2: pre.c2, c3: pre.c3 } };
}
// Pilihan suara dikelompokkan pria / wanita; suara tersimpan yang tidak ada di daftar tetap ditampilkan
function voiceOptions(cur) {
  const opt = v => `<option value="${esc(v.id)}" ${v.id === cur ? 'selected' : ''}>${esc(v.name)}${v.desc ? ' — ' + esc(v.desc.split(' · ')[1]) : ''}</option>`;
  return (voiceInfo(cur) ? '' : `<option value="${esc(cur)}" selected>${esc(voiceName(cur))}</option>`)
    + ['Pria', 'Wanita'].map(g => `<optgroup label="${g}">${ttsVoices.filter(v => v.gender === g).map(opt).join('')}</optgroup>`).join('');
}
function openIntro() {
  itCfg = null;
  document.querySelector('[data-lp="intro"]')?.click();
}

function renderIntro() {
  if (!E || !$('it-body')) return;
  itCfg = itCfg || introDefaults();
  const f = introFoot(), c = itCfg, g = c.gfx;
  const changedNote = f && introKey(c) !== introKey(f.intro);
  $('it-body').innerHTML = `
    <div class="it-acts"><button class="btn sm primary" data-it="gen" ${itBusy ? 'disabled' : ''}>${ic(f ? 'refresh' : 'sparkles')} ${f ? 'Perbarui intro' : 'Generate intro'}</button>
      ${f ? `<button class="btn sm ghost" data-it="del" title="Hapus intro dari timeline">${ic('trash')}</button>` : '<span></span>'}</div>
    ${changedNote ? '<p class="it-note" style="color:var(--warn)">Ada perubahan yang belum dipasang. Klik Perbarui intro.</p>' : ''}
    <div class="it-prev"><canvas id="it-cv" width="360" height="640"></canvas><span class="ld" id="it-ld">Memuat screenshot…</span></div>
    <button type="button" class="lnk-btn it-gfx-link" data-it="gfx">${ic('gfx')} Template, warna & posisi grafik di panel kanan</button>
    <div><div class="it-lbl"><span>Screenshot dari clip</span><span>${fmtTs(c.t - E.base[0])}</span></div>
      <button class="btn sm" data-it="here" style="width:100%;justify-content:center">${ic('range')} Pakai posisi playhead</button></div>
    <div><div class="it-lbl"><span>Teks hook (dibacakan AI)</span><span id="it-cnt">${g.text.length}/200</span></div>
      <textarea class="input it-text" id="it-text" maxlength="200" spellcheck="false">${esc(g.text)}</textarea></div>
    <div><div class="it-lbl"><span>Suara AI</span><span>Gemini</span></div>
      <div class="it-row"><select class="input" id="it-voice">${voiceOptions(c.voice)}</select>
      <button class="btn sm" data-it="listen" title="Dengar teks hook dengan suara ini">${ic('volume')}</button></div></div>
    <div><div class="it-lbl"><span>Gaya baca</span></div><select class="input" id="it-style">${Object.entries(TTS_STYLES).map(([k, n]) =>
      `<option value="${k}" ${(c.style || 'auto') === k ? 'selected' : ''}>${esc(n)}</option>`).join('')}</select></div>
    <div><div class="it-lbl"><span>Naskah yang dibacakan</span>${c.script ? '<button type="button" class="lnk-btn" data-it="auto-script">Kembali ke otomatis</button>' : '<span>dirapikan AI</span>'}</div>
      ${c.script ? `<textarea class="input it-text" id="it-script" maxlength="300" spellcheck="false">${esc(c.script)}</textarea>
        <p class="it-note" style="margin-top:4px">Tanda baca mengatur intonasi: koma = jeda napas, "..." = jeda dramatis, ? = nada bertanya, ! = penekanan.</p>`
        : `<div class="it-script"><span id="it-script-auto">${itScript.key === scriptKey(c) ? esc(itScript.text) : '<span class="muted">Merapikan naskah…</span>'}</span>
          <button type="button" class="lnk-btn" data-it="edit-script">Edit</button></div>`}</div>
    <p class="it-note">Intro dipasang di awal timeline sebelum clip utama, lama tampilnya mengikuti panjang suara, lalu transisi kertas robek membuka clip.
      Suara AI gratis dibatasi Gemini ±10 suara per model per hari; suara yang sudah dibuat disimpan & tidak dihitung lagi.</p>`;
  drawIntroPreview();
  if (!c.script) prepScript();
  if (introPanelOn()) renderProps();
}
// Panel kanan menampilkan setelan grafik intro: saat menu Intro dibuka (sel 'intro') atau footage intro dipilih
const introPanelOn = () => sel.type === 'intro' || (sel.type === 'footage' && !!E.footage[sel.i]?.intro);
function introPropsHtml() {
  itCfg = itCfg || introDefaults();
  const c = itCfg, g = c.gfx, pre = GFX[g.type] || GFX.hook_red, f = introFoot(), fi = f ? E.footage.indexOf(f) : -1;
  const cats = [...new Set(INTRO_TPL.map(k => GFX[k].cat))];
  const col = (k, n) => n ? `<div class="ts-c"><label>${esc(n)}</label><div class="ts-f hex"><label class="sw" style="--c:${g[k] || '#FFFFFF'}"><input type="color" data-itc="${k}" value="${(g[k] || '#ffffff').toLowerCase()}"></label>
    <input data-itch="${k}" value="${(g[k] || '#FFFFFF').slice(1)}" maxlength="7" spellcheck="false" aria-label="Kode warna"><span class="u">Hex</span></div></div>` : '';
  return `<div class="card p-card"><div class="p-sec">
    <h3><span>Grafik intro · ${esc(pre.name)}</span> <button class="back-link" data-p="back">← Kembali</button></h3>
    ${f ? `<div class="ov-file"><img class="ov-thumb" src="${footThumb(f)}" alt=""><div style="min-width:0"><b style="font-size:13px">Intro video</b>
      <div class="muted small">Gambar · awal clip · ${fmtTs(f.dur)} (mengikuti suara AI)</div></div></div>` : ''}
    <div class="p-field" style="margin-top:${f ? 12 : 0}px"><div class="p-label"><label>Template</label></div>
      ${cats.map(cat => `<div class="fx-sec">${esc(cat)}</div><div class="gfx-pick">${INTRO_TPL.filter(k => GFX[k].cat === cat).map(k => `<button type="button" class="gpk${k === g.type ? ' on' : ''}" data-itt="${k}" title="${esc(GFX[k].name)}">
        <span class="gpk-img" data-gthumb="${k}">${gfxThumbs[k] ? `<img src="${gfxThumbs[k]}" alt="">` : ''}</span><span class="gpk-nm">${esc(GFX[k].name)}</span></button>`).join('')}</div>`).join('')}</div>
    ${pre.noLabel ? '' : `<div class="p-field"><div class="p-label"><label>Label</label><span class="cnt">${(g.label || '').length}/40</span></div><input class="input p-input" id="it-label" value="${esc(g.label || '')}" maxlength="40" spellcheck="false"></div>`}
    <div class="ts-stack" style="margin-top:16px">${(pre.cn || [null, 'Kotak', 'Teks']).slice(0, 3).map((n, i) => col('c' + (i + 1), n)).join('')}</div>
    <div class="p-field"><div class="p-label"><label>Posisi</label></div><div class="it-seg">${INTRO_POS.map(([n, y]) => `<button type="button" class="${Math.abs(c.y - y) < 0.01 ? 'on' : ''}" data-ity="${y}">${n}</button>`).join('')}</div></div>
    <div class="p-row"><label>Ukuran grafik</label><input type="range" id="it-w" min="0.5" max="1" step="0.01" value="${c.w}"><span class="val" id="it-wv">${Math.round(c.w * 100)}%</span></div>
    ${f && introKey(c) !== introKey(f.intro) ? '<p class="muted small" style="margin:8px 0 0;color:var(--warn)">Ada perubahan yang belum dipasang ke timeline.</p>' : ''}
    <div class="p-actions"><button class="btn sm primary" data-it="gen" style="grid-column:1 / -1" ${itBusy ? 'disabled' : ''}>${ic(f ? 'refresh' : 'sparkles')} ${f ? 'Perbarui intro' : 'Generate intro'}</button></div>
    ${f ? `<div class="p-actions"><button class="btn sm" data-p="intro-open">${ic('pencil')} Hook & suara</button>
      <button class="btn sm danger" data-it="del">${ic('trash')} Hapus intro</button></div>` : ''}
  </div></div>`;
}
// Naskah suara dirapikan AI, disiapkan lebih dulu (di latar) supaya Dengar / Generate tidak menunggu lama
let itScript = { key: '', text: '' }, itScriptTimer = null;
const scriptKey = c => (c.style || 'auto') + '|' + c.gfx.text.trim();
function prepScript(delay = 0) {
  clearTimeout(itScriptTimer);
  itScriptTimer = setTimeout(async () => {
    const c = itCfg, key = c && scriptKey(c);
    if (!c || c.script || !c.gfx.text.trim() || itScript.key === key) return;
    try {
      const d = await api('tts_script', { id: PID, iid: IID, text: c.gfx.text.trim(), style: c.style || 'auto' });
      itScript = { key, text: d.script };
      if (itCfg && scriptKey(itCfg) === key && $('it-script-auto')) $('it-script-auto').textContent = d.script;
    } catch {}
  }, delay);
}

// Screenshot resolusi penuh dari video sumber (bukan preview 480p); paintMain() membacanya seperti elemen video
async function introFrame(time) {
  const key = Math.round(time * 100) / 100;
  if (itFrame?.t === key) return itFrame.img;
  const img = new Image();
  img.src = `api.php?action=intro_frame&id=${PID}&iid=${IID}&t=${key}`;
  await img.decode();
  img.videoWidth = img.naturalWidth;
  img.videoHeight = img.naturalHeight;
  itFrame = { t: key, img };
  return img;
}
// Gambar intro ke canvas c: frame dibingkai sama dengan clip (paintMain + bingkai), lalu grafik hook
async function composeIntro(c, cfg, img) {
  const W = c.width, H = c.height, g = c.getContext('2d');
  paintMain(g, img, cfg.t, W, H);
  drawFrameMask(g, W, H);
  if (!cfg.gfx.text.trim()) return;
  const gc = await gfxCanvas(cfg.gfx, 2);
  const gw = cfg.w * W, gh = gw * gc.height / gc.width;
  g.drawImage(gc, (W - gw) / 2, Math.max(0, Math.min(H - gh, cfg.y * H - gh / 2)), gw, gh);
}
let itPrevTimer = null;
function drawIntroPreview() {
  clearTimeout(itPrevTimer);
  itPrevTimer = setTimeout(async () => {
    const c = $('it-cv'), ld = $('it-ld');
    if (!c || !itCfg) return;
    try {
      const img = await introFrame(itCfg.t);
      await composeIntro(c, itCfg, img);
      if (ld) ld.hidden = true;
    } catch { if (ld) { ld.hidden = false; ld.textContent = 'Screenshot gagal dimuat'; } }
  }, 120);
}

async function generateIntro() {
  if (itBusy || !itCfg) return;
  const cfg = JSON.parse(JSON.stringify(itCfg)), text = cfg.gfx.text.trim();
  if (!text) return toast('Isi teks hook dulu.');
  itBusy = true;
  renderIntro();
  busy('Membuat intro…', 'Screenshot clip, grafik hook, dan suara AI yang membacakan hook.');
  try {
    const [img, vo] = await Promise.all([introFrame(cfg.t), api('hook_voice', { id: PID, iid: IID, text, voice: cfg.voice, style: cfg.style || 'auto', script: cfg.script || '' })]);
    const c = document.createElement('canvas');
    c.width = 1080; c.height = 1920;
    await composeIntro(c, cfg, img);
    const fd = new FormData();
    fd.append('file', await new Promise(r => c.toBlob(r, 'image/jpeg', 0.93)), 'intro.jpg');
    const res = await fetch('api.php?action=overlay_upload', { method: 'POST', body: fd });
    const d = await res.json().catch(() => ({ error: 'Respons server tidak valid.' }));
    if (!res.ok || d.error) throw new Error(d.error || 'Gambar intro gagal disimpan.');
    let f = introFoot();
    const isNew = !f;
    if (!f) f = { id: Math.random().toString(36).slice(2, 10), name: 'Intro video', kind: 'image', pos: 'pre' };
    // Lama tampil = panjang suara + jeda; transisi kertas (setengahnya masih di intro) dimulai tepat setelah suara selesai
    Object.assign(f, { file: d.file, dur: round3(Math.max(1, +vo.dur + 0.25)), vo: vo.file, intro: cfg });
    E.footage = [f, ...E.footage.filter(x => x !== f)]; // intro selalu paling depan
    const next = seqItems()[1];
    if (E.transitions[itemId(next)]?.type !== 'paper') E.transitions[itemId(next)] = { type: 'paper', dur: PAPER_DUR };
    if (!sfxLib.length) await loadSfxLib();
    renderTimeline(); // pps & posisi sambungan untuk SFX
    const jn = junctions().find(k => k.id === itemId(next));
    if (jn) addPaperSfx(jn);
    pause();
    changed();
    select({ type: 'footage', i: 0 });
    setFoot(0, 0);
    renderTimeline();
    toast(isNew ? `Intro ditambahkan di awal clip · ${fmtTs(f.dur)}` : 'Intro diperbarui');
  } catch (err) { toast(err.message || 'Gagal membuat intro.'); }
  finally {
    itBusy = false;
    idle();
    renderIntro();
  }
}
function deleteIntro() {
  const f = introFoot();
  if (!f) return;
  const s = seqItems(), next = s[s.indexOf(f) + 1];
  if (next && E.transitions[itemId(next)]?.type === 'paper') delete E.transitions[itemId(next)];
  removeFootage(E.footage.indexOf(f));
  if (!$('lp-intro').hidden) select({ type: 'intro' });
  renderTimeline();
  renderIntro();
  toast('Intro dihapus');
}
async function listenIntro() {
  const text = itCfg?.gfx.text.trim();
  if (!text) return toast('Isi teks hook dulu.');
  const btn = $('it-body').querySelector('[data-it="listen"]');
  if (btn) btn.disabled = true;
  try {
    const vo = await api('hook_voice', { id: PID, iid: IID, text, voice: itCfg.voice, style: itCfg.style || 'auto', script: itCfg.script || '' });
    if (!itCfg.script && $('it-script-auto')) { itScript = { key: scriptKey(itCfg), text: vo.said }; $('it-script-auto').textContent = vo.said; }
    itAudio?.pause();
    itAudio = new Audio(voUrl(vo.file));
    itAudio.dataset.f = '';
    itAudio.play().catch(() => toast('Suara tidak bisa diputar.'));
  } catch (err) { toast(err.message); }
  finally { if (btn) btn.disabled = false; }
}
// Suara intro ikut diputar saat footage intro diputar di preview (posisi disamakan dengan playhead)
function syncIntroVo() {
  const f = playing && fp ? E?.footage[fp.i] : null;
  if (!f?.vo || isMuted()) {
    if (itAudio?.dataset.f && !itAudio.paused) itAudio.pause();
    return;
  }
  if (itAudio?.dataset.f !== f.vo) { itAudio?.pause(); itAudio = new Audio(voUrl(f.vo)); itAudio.dataset.f = f.vo; }
  if (fp.at >= (itAudio.duration || Infinity) - 0.05) return;
  if (Math.abs(itAudio.currentTime - fp.at) > 0.3) itAudio.currentTime = fp.at;
  if (itAudio.paused) itAudio.play().catch(() => {});
}
// Form intro: perubahan langsung terlihat di gambar contoh; footage di timeline berubah setelah Perbarui intro.
// Hook, screenshot & suara di panel kiri (#it-body); template, warna, posisi & ukuran grafik di panel kanan (#props).
const itChanged = (full = false) => { if (full) renderIntro(); else drawIntroPreview(); };
// Panel kanan pindah ke setelan grafik intro (footage intro kalau sudah ada)
function selectIntroPanel() {
  const f = introFoot();
  select(f ? { type: 'footage', i: E.footage.indexOf(f) } : { type: 'intro' });
}
function introClick(e) {
  const b = e.target.closest('button');
  if (!b || !itCfg) return;
  const g = itCfg.gfx;
  if (b.dataset.it === 'gen') return generateIntro();
  if (b.dataset.it === 'del') return deleteIntro();
  if (b.dataset.it === 'listen') return listenIntro();
  if (b.dataset.it === 'gfx') return selectIntroPanel();
  if (b.dataset.it === 'here') {
    itCfg.t = round3(Math.max(E.trim[0], Math.min(E.trim[1] - 0.1, fp ? E.trim[0] : t)));
    return itChanged(true);
  }
  if (b.dataset.itt) {
    const pre = GFX[b.dataset.itt];
    Object.assign(g, { type: b.dataset.itt, c1: pre.c1, c2: pre.c2, c3: pre.c3, label: pre.noLabel ? '' : (g.label || pre.label) });
    return itChanged(true);
  }
  if (b.dataset.ity) { itCfg.y = +b.dataset.ity; return itChanged(true); }
  // Naskah: edit sendiri (mulai dari naskah AI) atau kembali dirapikan otomatis
  if (b.dataset.it === 'edit-script') { itCfg.script = itScript.key === scriptKey(itCfg) ? itScript.text : itCfg.gfx.text.trim(); renderIntro(); return $('it-script')?.focus(); }
  if (b.dataset.it === 'auto-script') { itCfg.script = ''; return renderIntro(); }
}
function introInput(e) {
  if (!itCfg) return;
  const el = e.target, g = itCfg.gfx;
  if (el.id === 'it-text') { g.text = el.value; $('it-cnt').textContent = `${el.value.length}/200`; if (!itCfg.script) { $('it-script-auto') && ($('it-script-auto').innerHTML = '<span class="muted">Merapikan naskah…</span>'); prepScript(1200); } }
  else if (el.id === 'it-script') { itCfg.script = el.value; return; }
  else if (el.id === 'it-label') { g.label = el.value; el.previousElementSibling?.querySelector('.cnt')?.replaceChildren(`${el.value.length}/40`); }
  else if (el.id === 'it-w') { itCfg.w = +el.value; $('it-wv').textContent = Math.round(itCfg.w * 100) + '%'; }
  else if (el.dataset.itc || el.dataset.itch) {
    const k = el.dataset.itc || el.dataset.itch, v = el.dataset.itc ? el.value : '#' + el.value.replace('#', '');
    if (!/^#[0-9a-f]{6}$/i.test(v)) return;
    g[k] = v.toUpperCase();
    const box = el.closest('.ts-f');
    box.querySelector('.sw').style.setProperty('--c', g[k]);
    if (el.dataset.itc) box.querySelector('[data-itch]').value = g[k].slice(1); else box.querySelector('[data-itc]').value = g[k].toLowerCase();
  }
  else return;
  itChanged();
}
function introChange(e) {
  if (e.target.id === 'it-voice' && itCfg) {
    itCfg.voice = e.target.value;
    try { localStorage.setItem('ed-intro-voice', itCfg.voice); } catch {}
    return renderIntro(); // catatan "belum dipasang"
  }
  if (e.target.id === 'it-style' && itCfg) { itCfg.style = e.target.value; return renderIntro(); }
  if (e.target.id === 'it-script' && itCfg) { itCfg.script = e.target.value.trim(); if (!itCfg.script) return renderIntro(); }
  if (introFoot()) renderIntro(); // tampilkan catatan "belum dipasang"
}
$('it-body').addEventListener('click', introClick);
$('it-body').addEventListener('input', introInput);
$('it-body').addEventListener('change', introChange);

/* ---------- Sound effect: pustaka (bawaan + unggahan) & track SFX. Item menempel ke clip (ref 'main', at = detik video asli)
   atau ke footage (ref = id footage, at = detik di footage), jadi bisa dipasang di intro. Render: edit_sfx_output() + mix. ---------- */
let sfxLib = [];
const sfxPrev = { file: '', el: new Audio() };
sfxPrev.el.onended = () => { sfxPrev.file = ''; renderSfxLib(); };
const sfxUrl = file => 'previews/sfx/' + encodeURIComponent(file);
const sfxMuted = () => !!E?.tracks?.sfx?.mute;
// Posisi virtual timeline (detik) sebuah SFX, dan kebalikannya: posisi → tempelan ke footage / clip
function sfxStart(x) {
  if (x.ref === 'main') return preDur() + x.at - E.base[0];
  const f = E.footage.find(g => g.id === x.ref);
  return f ? footStart(f) + x.at : 0;
}
function sfxAnchor(v) {
  const P = preDur(), B = baseDur();
  const list = v < P ? preF() : v > P + B ? postF() : [];
  let s0 = v < P ? 0 : P + B;
  for (const f of list) {
    if (v < s0 + f.dur || f === list.at(-1)) return { ref: f.id, at: round3(Math.max(0, Math.min(f.dur, v - s0))) };
    s0 += f.dur;
  }
  return { ref: 'main', at: round3(E.base[0] + Math.max(0, Math.min(B, v - P))) };
}
function sfxTimelineHtml() {
  if (!E.sfx.length) return `<div class="sc-mempty" style="--top:216px"><span class="st">${ic('volume')} SFX: tambahkan dari menu Sound effect di panel kiri</span></div>`;
  return E.sfx.map((x, i) => {
    const v = sfxStart(x);
    return `<div class="sc-sfx${sel.type === 'sfx' && sel.i === i ? ' on' : ''}" data-sx="${i}" style="left:${v * pps}px;width:${Math.max(14, x.dur * pps)}px"
      title="${esc(x.name)} · ${fmtTs(v)} · volume ${x.vol}%">${ic('volume')}<span>${esc(x.name)}</span></div>`;
  }).join('');
}

async function loadSfxLib() {
  try {
    const d = await api('sfx_list');
    sfxLib = d.sfx || [];
    sfxAiOn = !!d.ai;
    paintSfxAi();
  } catch (err) { toast(err.message); }
  renderSfxLib();
  if (E) renderTimeline(); // posisi SFX kertas memakai detik puncak dari library
}
// Buat SFX dengan AI: pilihan cepat (prompt bahasa Inggris yang sudah teruji) atau deskripsi sendiri
const SFX_AI_PRESETS = [
  ['Kertas robek', 'A sheet of paper crumpled briefly then ripped apart in one fast tear, close-up, dry studio recording, no music', 1.2, 'paper'],
  ['Whoosh transisi', 'Fast cinematic whoosh transition swoosh, clean, no music', 1, ''],
  ['Pop lucu', 'Cartoon bubble pop, short and cute', 0.5, ''],
  ['Ding', 'Bright notification ding bell, single hit', 1, ''],
  ['Tawa penonton', 'Small studio audience laughing, short burst', 3, ''],
  ['Record scratch', 'Vinyl record scratch stop sound effect', 1, ''],
  ['Drum dramatis', 'Dramatic drum hit with reverb, suspense sting', 2, ''],
];
let sfxAiPick = null;
$('sfx-chips').innerHTML = SFX_AI_PRESETS.map((p, i) => `<button type="button" data-sfxp="${i}">${esc(p[0])}</button>`).join('');
$('sfx-chips').addEventListener('click', e => {
  const b = e.target.closest('[data-sfxp]');
  if (!b) return;
  sfxAiPick = SFX_AI_PRESETS[+b.dataset.sfxp];
  $('sfx-prompt').value = sfxAiPick[1];
  // Durasi pilihan cepat yang belum ada di daftar ditambahkan supaya tidak jatuh ke "otomatis"
  const d = String(sfxAiPick[2]), sd = $('sfx-dur');
  if (![...sd.options].some(o => o.value === d)) sd.add(new Option(d.replace('.', ',') + 's', d));
  sd.value = d;
  $('sfx-chips').querySelectorAll('button').forEach(x => x.classList.toggle('on', x === b));
});
$('sfx-prompt').addEventListener('input', () => {
  if (sfxAiPick && $('sfx-prompt').value !== sfxAiPick[1]) { sfxAiPick = null; $('sfx-chips').querySelectorAll('button').forEach(x => x.classList.remove('on')); }
});
// Panel AI: dibuka/ditutup dari tombol ✨ (diingat); hanya muncul kalau API key ElevenLabs ada
let sfxAiOn = false, sfxAiOpen = false;
try { sfxAiOpen = localStorage.getItem('ed-sfx-ai') === '1'; } catch {}
function paintSfxAi() {
  $('sfx-ai-toggle').hidden = !sfxAiOn;
  $('sfx-ai').hidden = !sfxAiOn || !sfxAiOpen;
  $('sfx-ai-toggle').classList.toggle('on', sfxAiOpen);
  $('sfx-ai-toggle').setAttribute('aria-expanded', sfxAiOpen);
}
$('sfx-ai-toggle').onclick = () => {
  sfxAiOpen = !sfxAiOpen;
  try { localStorage.setItem('ed-sfx-ai', sfxAiOpen ? '1' : '0'); } catch {}
  paintSfxAi();
  if (sfxAiOpen) $('sfx-prompt').focus();
};
$('sfx-prompt').addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); $('sfx-gen').click(); } });
// Unduh file SFX ke komputer (nama file mengikuti nama SFX)
function downloadSfx(x) {
  const a = document.createElement('a');
  a.href = sfxUrl(x.file);
  a.download = (x.name || 'sound effect').replace(/[\\/:*?"<>|]+/g, ' ').trim() + '.' + x.file.split('.').pop();
  document.body.append(a);
  a.click();
  a.remove();
}
// Generate berjalan di latar (tanpa layar tunggu): library tetap bisa diputar & dipasang selama ElevenLabs bekerja
$('sfx-gen').onclick = async () => {
  const text = $('sfx-prompt').value.trim();
  if (!text) return toast('Tulis deskripsi suaranya dulu.');
  const btn = $('sfx-gen'), st = $('sfx-ai-st');
  btn.disabled = true;
  btn.innerHTML = '<span class="spin"></span>';
  st.hidden = false;
  st.textContent = 'Membuat suara… library tetap bisa dipakai.';
  try {
    const d = await api('sfx_generate', { text, dur: $('sfx-dur').value || null, name: sfxAiPick ? sfxAiPick[0] + ' (AI)' : '', tag: sfxAiPick?.[3] || '' });
    sfxLib = d.sfx;
    sfxNew = d.item.file;
    renderSfxLib();
    $('sfx-list').querySelector(`[data-sfx-f="${CSS.escape(d.item.file)}"]`)?.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    downloadSfx(d.item);
    sfxPrev.file = d.item.file;
    sfxPrev.el.src = sfxUrl(d.item.file);
    sfxPrev.el.play().catch(() => {});
    renderSfxLib();
    toast(d.item.tag === 'paper' ? 'SFX kertas robek AI siap · terunduh & dipakai untuk transisi kertas berikutnya' : `"${d.item.name}" terunduh & masuk library`);
  } catch (err) { toast(err.message); }
  finally {
    btn.disabled = false;
    btn.innerHTML = ic('sparkles');
    st.hidden = true;
  }
};
let sfxNew = '';
function renderSfxLib() {
  if (!$('sfx-list')) return;
  // Milikmu (AI & unggahan, terbaru di atas), lalu bawaan
  const mine = sfxLib.filter(x => !x.builtin).reverse(), built = sfxLib.filter(x => x.builtin);
  const row = x => `<div class="sfx-row${sfxPrev.file === x.file ? ' play' : ''}${sfxNew === x.file ? ' new' : ''}" data-sfx-f="${esc(x.file)}">
      <button data-sfx-play title="Dengarkan">${ic(sfxPrev.file === x.file ? 'pause' : 'play')}</button>
      <div style="min-width:0"><b title="${esc(x.prompt || x.name)}">${esc(x.name)}${x.ai ? '<span class="ai">AI</span>' : ''}</b><small>${(+x.dur).toFixed(2)} dtk${x.builtin ? ' · bawaan' : ''}</small></div>
      <div class="sfx-act">${x.builtin ? '' : `<button data-sfx-del title="Hapus dari library">${ic('trash')}</button>`}<button data-sfx-dl title="Unduh ke komputer">${ic('download')}</button><button data-sfx-add title="Tambah di posisi playhead">${ic('plus')}</button></div>
    </div>`;
  $('sfx-list').innerHTML = !sfxLib.length ? '<p class="hint">Memuat sound effect…</p>'
    : (mine.length ? `<div class="fx-sec">Milikmu (AI & unggahan)</div>${mine.map(row).join('')}<div class="fx-sec">Bawaan</div>` : '') + built.map(row).join('');
}
function addSfx(lib, at = null) {
  if (!E || !$('cv')) return toast('Tunggu editor selesai dimuat.');
  if (isLocked('sfx')) return toast('Track SFX sedang dikunci.');
  E.sfx.push({ id: Math.random().toString(36).slice(2, 10), file: lib.file, name: lib.name, dur: +lib.dur, ...sfxAnchor(at ?? curV()), vol: 100 });
  pause();
  changed();
  select({ type: 'sfx', i: E.sfx.length - 1 });
  toast(`SFX "${lib.name}" ditambahkan`);
}
function removeSfx(i) {
  E.sfx.splice(i, 1);
  changed();
  select({ type: 'clip' });
}
function playSfxItem(i) {
  const x = E.sfx[i];
  if (!x) return;
  sfxPrev.el.src = sfxUrl(x.file);
  sfxPrev.el.volume = Math.min(1, x.vol / 100);
  sfxPrev.el.play().catch(() => toast('Suara tidak bisa diputar.'));
}
async function uploadSfx(files) {
  files = [...files].filter(f => /\.(mp3|m4a|aac|wav|ogg|flac)$/i.test(f.name));
  if (!files.length) return toast('Format sound effect harus MP3, M4A, AAC, WAV, OGG, atau FLAC.');
  for (const f of files) {
    toast(`Mengunggah ${f.name}…`);
    try {
      const fd = new FormData();
      fd.append('file', f);
      const res = await fetch('api.php?action=sfx_upload', { method: 'POST', body: fd });
      const d = await res.json().catch(() => ({ error: 'Respons server tidak valid.' }));
      if (!res.ok || d.error) throw new Error(d.error || 'Sound effect gagal diunggah.');
      sfxLib = d.sfx;
      renderSfxLib();
    } catch (err) { toast(err.message); return; }
  }
  toast(files.length > 1 ? `${files.length} sound effect masuk library` : 'Sound effect masuk library');
}
$('sfx-add').onclick = () => $('sfx-input').click();
loadSfxLib();
$('sfx-input').onchange = e => { uploadSfx(e.target.files); e.target.value = ''; };
$('sfx-list').addEventListener('click', async e => {
  const row = e.target.closest('[data-sfx-f]'), lib = row && sfxLib.find(x => x.file === row.dataset.sfxF);
  if (!lib) return;
  if (e.target.closest('[data-sfx-play]')) {
    if (sfxPrev.file === lib.file) { sfxPrev.el.pause(); sfxPrev.file = ''; return renderSfxLib(); }
    pause();
    sfxPrev.file = lib.file;
    sfxPrev.el.src = sfxUrl(lib.file);
    sfxPrev.el.volume = 1;
    sfxPrev.el.play().catch(() => toast('Suara tidak bisa diputar.'));
    return renderSfxLib();
  }
  if (e.target.closest('[data-sfx-add]')) return addSfx(lib);
  if (e.target.closest('[data-sfx-dl]')) return downloadSfx(lib);
  if (e.target.closest('[data-sfx-del]')) {
    if (!confirm(`Hapus "${lib.name}" dari library? SFX ini di timeline clip lain ikut tidak terpakai.`)) return;
    try { sfxLib = (await api('sfx_delete', { file: lib.file })).sfx; renderSfxLib(); } catch (err) { toast(err.message); }
  }
});
// Pemutaran SFX di preview: tiap item punya elemen audio sendiri, disinkronkan ke posisi putar (juga di footage / intro)
const sfxEls = new Map();
function syncSfx() {
  if (!E) return;
  const v = curV(), on = playing && !sfxMuted(), live = new Set();
  for (const x of E.sfx) {
    let a = sfxEls.get(x.id);
    if (!a || a.dataset.f !== x.file) {
      a?.pause();
      a = new Audio(sfxUrl(x.file));
      a.preload = 'auto';
      a.dataset.f = x.file;
      sfxEls.set(x.id, a);
    }
    live.add(x.id);
    const rel = v - sfxStart(x);
    if (!on || rel < 0 || rel >= x.dur) { if (!a.paused) a.pause(); continue; }
    // Sudah selesai diputar: hanya diulang kalau posisi putar kembali ke bagian awal SFX (mis. diputar ulang / seek mundur)
    if (a.ended && rel >= (a.duration || x.dur) - 0.1) continue;
    if (a.paused || a.ended || Math.abs(a.currentTime - rel) > 0.25) a.currentTime = rel;
    a.volume = Math.min(1, x.vol / 100);
    if (a.paused) a.play().catch(() => {});
  }
  for (const [id, a] of sfxEls) if (!live.has(id)) { a.pause(); sfxEls.delete(id); }
}
// SFX kertas robek yang terikat ke transisi kertas: posisinya disamakan dengan saat kertas mulai robek
const paperSfxOf = j => E.sfx.find(x => x.tr === j.id);
// SFX kertas yang dipakai: hasil AI terbaru bertanda 'paper', kalau belum ada SFX bawaan
const paperLib = () => [...sfxLib].reverse().find(x => x.tag === 'paper' && x.ai) || sfxLib.find(x => x.file === 'paper_rip.wav') || { file: 'paper_rip.wav', name: 'Kertas robek', dur: 0.85, peak: 0.51 };
const sfxPeak = file => sfxLib.find(x => x.file === file)?.peak ?? (file === 'paper_rip.wav' ? 0.51 : 0.3);
// Puncak suara (detik peak di file) jatuh ±0,2 dtk setelah kertas mulai robek (setelah fase gumpalan)
const PAPER_BALL = 8 / 19;
const paperSfxV = (j, file) => Math.max(0, junctionX(j) / pps - trDur(j) / 2 + trDur(j) * PAPER_BALL + 0.2 - sfxPeak(file));
function addPaperSfx(j) {
  if (paperSfxOf(j)) return;
  const lib = paperLib();
  E.sfx.push({ id: Math.random().toString(36).slice(2, 10), file: lib.file, name: lib.name, dur: +lib.dur, ...sfxAnchor(paperSfxV(j, lib.file)), vol: 100, tr: j.id });
  changed();
  renderTimeline();
  if (sel.type === 'transition') renderProps();
}
function alignPaperSfx() {
  if (!pps) return;
  const js = junctions();
  for (const x of E.sfx) {
    if (!x.tr) continue;
    const j = js.find(k => k.id === x.tr);
    if (!j || E.transitions[j.id]?.type !== 'paper') { delete x.tr; continue; }
    Object.assign(x, sfxAnchor(paperSfxV(j, x.file)));
  }
}

// Pratinjau transisi kertas robek di canvas (perkiraan aset tools/paper_tear.py): gumpalan kertas, lalu lubang bergerigi membesar
function drawPaperTr(g, A, B, p, CW, CH) {
  const cx = CW / 2, cy = CH * 0.46, ball = 8 / 19;
  const rnd = i => { const x = Math.sin(i * 12.9898) * 43758.5453; return x - Math.floor(x); };
  const blob = (R, seed, jag) => {
    const P = new Path2D();
    for (let i = 0; i <= 96; i++) {
      const a = i / 96 * Math.PI * 2, r = R * (1 + 0.1 * Math.sin(3 * a + seed) + 0.05 * Math.sin(7 * a + seed * 2) + jag * (rnd(i + seed * 100) - 0.5));
      i ? P.lineTo(cx + Math.cos(a) * r, cy + Math.sin(a) * r) : P.moveTo(cx + Math.cos(a) * r, cy + Math.sin(a) * r);
    }
    P.closePath();
    return P;
  };
  g.drawImage(A, 0, 0);
  if (p < ball) {
    const r = CW * 0.17 * (0.2 + 0.8 * (1 - (1 - p / ball) ** 3));
    const gr = g.createRadialGradient(cx - r * 0.35, cy - r * 0.4, r * 0.1, cx, cy, r * 1.1);
    gr.addColorStop(0, '#ffffff'); gr.addColorStop(1, '#b9b8b2');
    g.fillStyle = gr;
    g.fill(blob(r, 1, 0.12));
    return;
  }
  const q = (p - ball) / (1 - ball), R = CW * (0.11 + 1.45 * q ** 1.8);
  const hole = blob(R, 2, 0.1);
  g.save(); g.clip(hole); g.drawImage(B, 0, 0); g.restore();
  const ring = new Path2D();
  ring.addPath(blob(R + CW * 0.08 + R * 0.42, 3, 0.18));
  ring.addPath(hole);
  g.fillStyle = '#efeee9';
  g.shadowColor = 'rgba(0, 0, 0, .35)'; g.shadowBlur = 12;
  g.fill(ring, 'evenodd');
  g.shadowBlur = 0;
}

$('tr-tabs').addEventListener('click', e => { const b = e.target.closest('[data-trtab]'); if (b) { trTab = b.dataset.trtab; paintTrTab(); } });
$('op-grid').addEventListener('click', e => { const b = e.target.closest('[data-opk]'); if (b) setOpening(b.dataset.opk); });

function renderTrList() {
  const cur = sel.type === 'transition' && E?.transitions[sel.id] ? E.transitions[sel.id].type : trPreset;
  $('tr-grid').innerHTML = Object.entries(TRANSITIONS).map(([k, pre]) => `<button class="fxt ${cur === k ? 'on' : ''}" data-trk="${k}">
      <span class="trs tr-${k}"><i class="a"></i><i class="b"></i></span><span>${esc(pre.label)}</span></button>`).join('');
}
$('tr-grid').addEventListener('click', e => {
  const b = e.target.closest('[data-trk]');
  if (!b) return;
  trPreset = b.dataset.trk;
  if (E && sel.type === 'transition' && E.transitions[sel.id]) {
    E.transitions[sel.id].type = trPreset;
    if (trPreset === 'paper') E.transitions[sel.id].dur = PAPER_DUR;
    changed();
    renderProps();
    renderTimeline();
    previewTransition(sel.id, true);
  } else renderTrList();
});
renderTrList();
paintTrTab();

// Footage berikutnya; setelah footage awal terakhir lanjut ke clip, setelah footage akhir terakhir berhenti
function nextFoot() {
  const f = E.footage[fp.i], list = f.pos === 'pre' ? preF() : postF(), nx = list[list.indexOf(f) + 1];
  if (nx) return setFoot(E.footage.indexOf(nx), 0);
  if (f.pos === 'pre') return seek(E.trim[0]);
  fp.at = f.dur;
  pause();
}

// Clip utama selesai: lanjut ke footage akhir kalau ada
function endMain() {
  const f = postF()[0];
  if (f) setFoot(E.footage.indexOf(f), 0); else pause();
}

// Masukkan footage dari pustaka media. pos 'pre' / 'post', k = urutan di dalam kelompoknya
function addFootage(lib, pos, k) {
  const f = { id: Math.random().toString(36).slice(2, 10), file: lib.file, name: lib.name, kind: lib.kind === 'video' ? 'video' : 'image',
    pos, dur: lib.kind === 'video' ? round3(lib.dur) : 5 };
  const pre = preF(), post = postF();
  (pos === 'pre' ? pre : post).splice(k, 0, f);
  E.footage = [...pre, ...post];
  pause();
  changed();
  select({ type: 'footage', i: E.footage.indexOf(f) });
  setFoot(E.footage.indexOf(f), 0);
  toast(`Footage ditambahkan di ${pos === 'pre' ? 'awal' : 'akhir'} clip · +${fmtTs(f.dur)}`);
}

function removeFootage(i) {
  const showing = fp?.i === i;
  const gone = E.footage[i]?.id;
  E.sfx = E.sfx.filter(x => x.ref !== gone); // SFX yang menempel di footage ini ikut dihapus
  E.footage.splice(i, 1);
  if (showing || fp) { fp = null; fvid.pause(); }
  changed();
  select({ type: 'clip' });
  if (showing) seek(E.trim[0]);
  updateTime();
}

function moveFootage(i) {
  const f = E.footage[i], pre = preF().filter(g => g !== f), post = postF().filter(g => g !== f);
  if (f.pos === 'pre') { f.pos = 'post'; post.push(f); } else { f.pos = 'pre'; pre.unshift(f); }
  E.footage = [...pre, ...post];
  changed();
  select({ type: 'footage', i: E.footage.indexOf(f) });
  setFoot(E.footage.indexOf(f), 0);
}

// Titik sisip footage di posisi virtual v: setengah kiri timeline = awal clip, setengah kanan = akhir clip
function footDropAt(v) {
  const P = preDur(), B = baseDur(), pos = v < P + B / 2 ? 'pre' : 'post';
  const list = pos === 'pre' ? preF() : postF();
  let s = pos === 'pre' ? 0 : P + B, k = 0;
  for (const f of list) { if (v < s + f.dur / 2) break; s += f.dur; k++; }
  return { pos, k, at: s };
}

function showDrop(d) {
  const el = $('tl-drop');
  if (!el) return;
  el.hidden = !d;
  if (!d) return;
  el.className = 'tl-drop ' + d.kind;
  el.style.left = d.left + 'px';
  el.style.width = d.kind === 'med' ? d.width + 'px' : '';
}

function bindTimelineDrop() {
  const sc = $('tl-scroll');
  // Baris tujuan dari posisi kursor: track media (atas) = overlay, track video (bawah) = footage
  const target = e => {
    const r = $('tl').getBoundingClientRect(), y = e.clientY - r.top, v = (e.clientX - r.left) / pps;
    if (y >= 20 && y < 50) return { kind: 'med', v };
    if (y >= 50 && y < 94) return { kind: 'foot', v };
    return null;
  };
  sc.addEventListener('dragover', e => {
    if (!dragMedia || !E) return;
    const tg = target(e);
    if (tg && isLocked(tg.kind === 'med' ? 'media' : 'video')) { e.dataTransfer.dropEffect = 'none'; return showDrop(null); }
    if (!tg || (tg.kind === 'med' && dragMedia.kind === 'video')) { e.dataTransfer.dropEffect = 'none'; return showDrop(null); }
    e.preventDefault();
    e.dataTransfer.dropEffect = 'copy';
    if (tg.kind === 'med') {
      const s = Math.max(E.trim[0], Math.min(E.trim[1] - 0.5, E.base[0] + tg.v - preDur()));
      showDrop({ kind: 'med', left: x(s), width: Math.max(24, (Math.min(E.trim[1], s + 3) - s) * pps) });
    } else showDrop({ kind: 'foot', left: footDropAt(tg.v).at * pps });
  });
  sc.addEventListener('dragleave', e => { if (!sc.contains(e.relatedTarget)) showDrop(null); });
  sc.addEventListener('drop', e => {
    if (!dragMedia || !E) return;
    e.preventDefault();
    const tg = target(e), lib = dragMedia;
    if (tg && isLocked(tg.kind === 'med' ? 'media' : 'video')) { dragMedia = null; return showDrop(null); }
    dragMedia = null;
    showDrop(null);
    if (!tg) return;
    if (tg.kind === 'med') return addMediaToTimeline(lib, E.base[0] + tg.v - preDur());
    const d = footDropAt(tg.v);
    addFootage(lib, d.pos, d.k);
  });
}

/*
 * Waktu persis frame yang sedang tampil (requestVideoFrameCallback). Saat diputar, vid.currentTime bisa selisih 1–2 frame
 * dari gambar di layar, sehingga di pergantian shot crop kamera sempat salah posisi (terlihat seperti kedipan / glitch).
 * Framing (kamera, split) memakai waktu frame ini supaya selalu cocok dengan gambarnya — sama seperti hasil render.
 */
let vfT = null;
function watchFrames(v) {
  if (!v?.requestVideoFrameCallback || v._vf) return;
  v._vf = true;
  const onFrame = (now, md) => {
    if (v === vid && E) {
      vfT = E.pad[0] + md.mediaTime;
      if (playing && !fp) draw();
    }
    v.requestVideoFrameCallback(onFrame);
  };
  v.requestVideoFrameCallback(onFrame);
}
// Waktu untuk framing gambar video: frame yang tampil saat diputar, posisi playhead saat diam
const frameTime = () => (playing && vfT !== null && Math.abs(vfT - t) < 0.5 ? vfT : t);

function loop() {
  const now = performance.now(), dt = Math.min(0.25, (now - lastTick) / 1000);
  lastTick = now;
  if (E && playing && fp) {
    const f = E.footage[fp.i];
    if (!f) seek(E.trim[0]);
    else {
      fp.at = f.kind === 'video' ? Math.min(f.dur, fvid.currentTime) : fp.at + dt;
      // Video yang tidak bisa diputar browser (mis. codec HEVC) dilewati di preview; render tetap memakainya
      if (fp.at >= f.dur - 0.03 || (f.kind === 'video' && (fvid.ended || fvid.error))) nextFoot();
    }
    updateTime();
    draw();
  } else if (E && playing && !vid.paused) {
    t = E.pad[0] + vid.currentTime;
    const g = gapAt(t);
    if (g) { if (g[1] >= E.trim[1] - 0.02) { t = g[0]; endMain(); } else { t = g[1]; vid.currentTime = t - E.pad[0]; } }
    if (t >= E.trim[1] && !fp) { t = E.trim[1]; endMain(); }
    updateTime();
    draw();
    if (sel.type === 'lines') markActiveLine();
  }
  // Rentang putar: berhenti di penanda akhir
  if (E && playing && prange.on && curV() >= prange.b - 0.01) pause();
  syncMusic();
  syncIntroVo();
  syncSfx();
  requestAnimationFrame(loop);
}

/* ---------- Timeline ---------- */

// Skala timeline: rentang yang terlihat = durasi total × tlScale (1 = pas, 2 = dua kali durasi, 0,33 = diperbesar 300%)
let tlScale = 1;  // bawaan 100%
try { tlScale = Math.min(2, Math.max(100 / 300, +localStorage.getItem('ed-tl-zoom') || 1)); } catch {}
const zoomPct = () => Math.round(100 / tlScale);
function setZoom(pct) {
  pct = Math.min(300, Math.max(50, Math.round(pct / 5) * 5));
  tlScale = 100 / pct;
  try { localStorage.setItem('ed-tl-zoom', tlScale); } catch {}
  const r = $('tl-zoom');
  if (r) { r.value = pct; syncRange(r); }
  if ($('zoom-val')) $('zoom-val').textContent = pct + '%';
  renderTimeline();
  // Jaga playhead tetap terlihat setelah skala berubah
  const v = $('tl-view');
  if (v) v.scrollLeft = Math.max(0, curV() * pps - v.clientWidth / 2);
}
function bindZoom() {
  syncRange($('tl-zoom'));
  $('tl-zoom').oninput = e => setZoom(+e.target.value);
  $('zoom-out').onclick = () => setZoom(zoomPct() - 10);
  $('zoom-in').onclick = () => setZoom(zoomPct() + 10);
  // Ctrl/⌘ + scroll (atau pinch trackpad) di timeline
  $('tl-scroll').addEventListener('wheel', e => {
    if (!e.ctrlKey && !e.metaKey) return;
    e.preventDefault();
    setZoom(zoomPct() + (e.deltaY > 0 ? -5 : 5));
  }, { passive: false });
}

const x = time => (preDur() + time - E.base[0]) * pps;
const timeAt = clientX => {
  const r = $('tl').getBoundingClientRect();
  return E.base[0] - preDur() + (clientX - r.left) / pps;
};
const vAt = clientX => (clientX - $('tl').getBoundingClientRect().left) / pps;

function renderTimeline() {
  const sc = $('tl-scroll');
  if (!sc || !E) return;
  const vw = Math.max(1, $('tl-view').clientWidth - 14);
  pps = vw / (totalDur() * tlScale);
  alignPaperSfx();
  fitRange();
  $('tl').style.width = (tlScale < 1 ? vw / tlScale : vw) + 'px';
  $('tl').style.setProperty('--tlw', totalDur() * pps + 'px');
  const [t0, t1] = E.trim;
  const [b0, b1] = E.base;
  const lbls = E.subtitles ? E.lines.map((l, i) => {
    if (l.e <= b0 || l.s >= b1) return '';
    const a = Math.max(l.s, b0), b = Math.min(l.e, b1), w = Math.max(3, x(b) - x(a) - 2);
    const on = sel.type === 'line' && sel.i === i, out = l.e <= t0 || l.s >= t1;
    const txt = l.words.map(w => w.w).join(' ');
    return `<span class="sc-lbl${on ? ' on' : ''}${out ? ' out' : ''}${w < 44 ? ' narrow' : ''}" data-line="${i}" style="left:${x(a)}px;width:${w}px" title="${esc(txt)}">${esc(txt)}</span>`;
  }).join('') : '';
  const bands = splitParts().map(p => [Math.max(p.s, E.base[0]), Math.min(p.e, E.base[1])]).filter(([a, b]) => b - a > 0.05)
    .map(([a, b]) => `<span class="sc-split" style="left:${x(a)}px;width:${x(b) - x(a)}px" title="Split screen"></span>`).join('');
  const W = x(b1);
  const spanX = m => { const [va, vb] = itemV(m); return `left:${va * pps}px;width:${Math.max(24, (vb - va) * pps)}px`; };
  const meds = E.media.length ? E.media.map((m, i) => {
    const on = sel.type === 'media' && sel.i === i, out = !m.ref && (m.e <= t0 || m.s >= t1);
    return `<div class="sc-media${on ? ' on' : ''}${out ? ' out' : ''}" data-mi="${i}" style="${spanX(m)}" title="${esc(m.name)} · ${fmtTs(m.s - b0)}–${fmtTs(m.e - b0)}">
      <span class="mh l" data-drag="m0"></span><i class="mth" style="${m.file ? `background-image:url('previews/overlays/${m.file}')` : ''}"></i><span class="nm">${esc(m.name || 'Gambar')}</span><span class="mh r" data-drag="m1"></span></div>`;
  }).join('') : `<div class="sc-mempty"><span class="st">${ic('image')} Media: pilih gambar di panel Media untuk menambahkannya ke timeline</span></div>`;
  if (!$('tlx')) $('tl').innerHTML = `<div class="film" id="film">${filmHtml()}</div><canvas class="wave" id="wave"></canvas><div class="tlx" id="tlx"></div><div class="tl-drop" id="tl-drop" hidden></div>`;
  drawWave();
  Object.assign($('film').style, { left: x(b0) + 'px', width: baseDur() * pps + 'px', right: 'auto' });
  const foots = E.footage.map((f, i) => {
    const on = sel.type === 'footage' && sel.i === i;
    return `<div class="sc-foot${on ? ' on' : ''}" data-fo="${i}" style="left:${footStart(f) * pps}px;width:${f.dur * pps}px;--th:url('${footThumb(f)}')">
      <span class="nm">${ic(f.kind === 'video' ? 'film' : 'image')}${esc(f.name || 'Footage')} <small>${fmtTs(f.dur)}</small></span>${f.kind === 'image' ? '<span class="fh" data-drag="fdur"></span>' : ''}</div>`;
  }).join('');
  const valid = new Set(junctions().map(j => j.id));
  Object.keys(E.transitions).forEach(id => { if (!valid.has(id)) delete E.transitions[id]; });
  const trBtns = E.footage.length ? junctions().map(j => {
    const tr = E.transitions[j.id], on = sel.type === 'transition' && sel.id === j.id;
    return `<button class="sc-tr${tr ? ' has' : ''}${on ? ' on' : ''}" data-tr="${j.id}" style="left:${junctionX(j)}px" aria-label="${tr ? esc(TRANSITIONS[tr.type].label) : 'Tambah transisi'}">${ic(tr ? 'transition' : 'plus')}</button>`;
  }).join('') : '';
  const txs = E.texts.length ? E.texts.map((c, i) => {
    const on = sel.type === 'text' && sel.i === i, out = !c.ref && (c.e <= t0 || c.s >= t1);
    return `<div class="sc-text${on ? ' on' : ''}${out ? ' out' : ''}" data-ti="${i}" style="${spanX(c)}" title="${esc(c.text)} · ${fmtTs(c.s - b0)}–${fmtTs(c.e - b0)}">
      <span class="mh l" data-drag="m0"></span>${ic('type')}<span class="nm">${esc(c.text)}</span><span class="mh r" data-drag="m1"></span></div>`;
  }).join('') : `<div class="sc-mempty" style="--top:156px"><span class="st">${ic('type')} Teks: tambahkan dari tab Teks di panel kiri</span></div>`;
  const fxs = E.effects.length ? E.effects.map((fx, i) => {
    const on = sel.type === 'effect' && sel.i === i, out = !fx.ref && (fx.e <= t0 || fx.s >= t1);
    return `<div class="sc-fx${on ? ' on' : ''}${out ? ' out' : ''}" data-fi="${i}" style="${spanX(fx)}" title="${esc(EFFECTS[fx.type].label)} · ${fmtTs(fx.s - b0)}–${fmtTs(fx.e - b0)}">
      <span class="mh l" data-drag="m0"></span>${ic('sparkles')}<span class="nm">${esc(EFFECTS[fx.type].label)}</span><span class="mh r" data-drag="m1"></span></div>`;
  }).join('') : `<div class="sc-mempty" style="--top:186px"><span class="st">${ic('sparkles')} Efek: tambahkan dari menu Efek di panel kiri</span></div>`;
  const mus = E.music.length ? E.music.map((m, i) => {
    const on = sel.type === 'music' && sel.i === i, out = !m.ref && (m.e <= t0 || m.s >= t1);
    return `<div class="sc-music${on ? ' on' : ''}${out ? ' out' : ''}" data-mu="${i}" style="${spanX(m)}" title="${esc(m.name)} · ${fmtTs(m.s - b0)}–${fmtTs(m.e - b0)} · volume ${m.vol}%">
      <span class="mh l" data-drag="m0"></span>${ic('music')}<span class="nm">${esc(m.name || 'Musik')}</span><span class="mh r" data-drag="m1"></span></div>`;
  }).join('') : `<div class="sc-mempty" style="--top:126px"><span class="st">${ic('music')} Musik: pilih lagu dari menu Musik di panel kiri</span></div>`;
  $('tlx').innerHTML = `${rulerHtml()}${meds}${txs}${fxs}${mus}
    <span class="sc-dim l" style="left:${x(b0)}px;width:${x(t0) - x(b0)}px"></span><span class="sc-dim r" style="left:${x(t1)}px;width:${Math.max(0, W - x(t1))}px"></span>
    ${videoMarks()}
    <div class="sc-clip${sel.type === 'video' ? ' on' : ''}" style="left:${x(t0)}px;width:${x(t1) - x(t0)}px">
      <span class="handle l" data-drag="trim0" title="Tarik untuk mengubah awal clip"></span><span class="handle r" data-drag="trim1" title="Tarik untuk mengubah akhir clip"></span></div>
    ${prange.on ? `<div class="prange${prange.custom ? ' custom' : ''}" data-pr="m" style="left:${prange.a * pps}px;width:${(prange.b - prange.a) * pps}px"><span class="pr-h l" data-pr="a"></span><span class="pr-h r" data-pr="b"></span></div>
      <span class="pr-line${prange.custom ? '' : ' full'}" style="left:${prange.a * pps}px"></span><span class="pr-line${prange.custom ? '' : ' full'}" style="left:${prange.b * pps}px"></span>` : ''}
    ${E.opening ? `<div class="sc-op${sel.type === 'opening' ? ' on' : ''}" style="left:${preF().length ? 0 : x(E.trim[0])}px;width:${Math.max(18, E.opening.dur * pps)}px" title="Opening: ${esc(OPENINGS[E.opening.type].label)} · ${E.opening.dur.toFixed(1)} dtk">${ic('transition')}${E.opening.dur * pps > 70 ? 'Opening' : ''}</div>` : ''}
    ${sfxTimelineHtml()}

    ${foots}${trBtns}${bands}${lbls}<div class="playhead" id="playhead" style="left:${curV() * pps}px"><span class="ph-knob"></span></div>`;
  ensureFilm();
  renderTextList();
  $('fx-info').textContent = E.effects.length || '';
  $('intro-info').textContent = introFoot() ? '1' : '';
  $('sfx-info').textContent = E.sfx.length || '';
  $('tr-info').textContent = Object.keys(E.transitions).length || '';
  renderTrackCtl();
  drawWave();
  renderTrList();
  if (cv) junctions().forEach(j => { if (E.transitions[j.id]) { staticFrame(j.a, 'last'); staticFrame(j.b, 'first'); } });
  updateCutBtn();
}

// Penggaris: label tiap beberapa detik (jarak minimal ±70px), garis kecil di antaranya
function rulerHtml() {
  const dur = totalDur() * Math.max(1, tlScale);
  const step = [1, 2, 5, 10, 15, 30, 60, 120].find(s => s * pps >= 70) || 300;
  const minor = step / 5;
  const lab = s => `${String(Math.floor(s / 60)).padStart(2, '0')}:${String(Math.floor(s % 60)).padStart(2, '0')}`;
  let h = '';
  for (let s = 0; s <= dur + 1e-6; s += minor) {
    const k = Math.round(s / minor), px = s * pps;
    if (k % 5 === 0) h += `<i class="maj" style="left:${px}px"></i><span style="left:${px}px">${lab(s)}</span>`;
    else if (minor * pps >= 6) h += `<i style="left:${px}px"></i>`;
  }
  return `<div class="ruler">${h}</div>`;
}

/* ---------- Spektrum suara: audio video preview di-decode di browser, puncak tiap 10 md ---------- */
const wave = { key: '', peaks: null, max: 1 };
async function loadWave() {
  const key = meta?.proxy;
  if (!key || wave.key === key) return;
  Object.assign(wave, { key, peaks: null });
  try {
    const buf = await (await fetch(key)).arrayBuffer();
    const ab = await new OfflineAudioContext(1, 1, 44100).decodeAudioData(buf);
    if (wave.key !== key) return;
    const ch = [...Array(ab.numberOfChannels)].map((_, i) => ab.getChannelData(i));
    const step = Math.round(ab.sampleRate / 100), n = Math.ceil(ab.length / step), peaks = new Float32Array(n);
    let max = 0;
    for (let i = 0; i < n; i++) {
      let m = 0;
      for (let j = i * step, end = Math.min(ab.length, j + step); j < end; j += 4) for (const c of ch) { const v = Math.abs(c[j]); if (v > m) m = v; }
      peaks[i] = m; if (m > max) max = m;
    }
    Object.assign(wave, { peaks, max: max || 1 });
    drawWave();
  } catch { /* video tanpa audio / tidak bisa di-decode: spektrum kosong */ }
}
function drawWave() {
  const cvw = $('wave');
  if (!cvw || !E) return;
  const [b0] = E.base, w = Math.max(1, Math.round(baseDur() * pps)), h = 18, dpr = devicePixelRatio || 1;
  Object.assign(cvw.style, { left: x(b0) + 'px', width: w + 'px' });
  if (cvw.width !== Math.round(w * dpr)) { cvw.width = Math.round(w * dpr); cvw.height = h * dpr; }
  const g = cvw.getContext('2d');
  g.setTransform(dpr, 0, 0, dpr, 0, 0);
  g.clearRect(0, 0, w, h);
  loadWave();
  if (!wave.peaks) return;
  // Amplitudo rata-rata per piksel (bukan puncak) lalu dihaluskan, supaya kurvanya tenang
  const raw = new Float32Array(w + 1);
  for (let px = 0; px <= w; px++) {
    const i0 = Math.max(0, Math.floor((b0 + px / pps - E.pad[0]) * 100)), i1 = Math.min(wave.peaks.length, Math.max(i0 + 1, Math.ceil((b0 + (px + 1) / pps - E.pad[0]) * 100)));
    let sum = 0;
    for (let i = i0; i < i1; i++) sum += wave.peaks[i];
    raw[px] = i1 > i0 ? sum / (i1 - i0) : 0;
  }
  const amp = raw.map((_, i) => {
    let s = 0, n = 0;
    for (let k = -2; k <= 2; k++) if (raw[i + k] !== undefined) { s += raw[i + k]; n++; }
    return Math.min(1, Math.sqrt(s / n / wave.max) * Math.min(2, clipVol(b0 + i / pps) / 100));
  });
  const path = () => {
    g.beginPath();
    g.moveTo(0, h);
    for (let px = 0; px <= w; px++) g.lineTo(px, h - 1 - amp[px] * (h - 3));
    g.lineTo(w, h);
    g.closePath();
  };
  const paint = (fill, line) => {
    path(); g.fillStyle = fill; g.fill();
    g.beginPath();
    for (let px = 0; px <= w; px++) g[px ? 'lineTo' : 'moveTo'](px, h - 1 - amp[px] * (h - 3));
    g.strokeStyle = line; g.lineWidth = 1; g.stroke();
  };
  // Seluruh rentang pudar, bagian yang masuk clip (dalam trim, bukan bagian terhapus) lebih terang
  paint('rgba(244, 246, 250, .08)', 'rgba(244, 246, 250, .22)');
  g.save();
  g.beginPath();
  const segs = videoSegments().filter(([p, q]) => !gapAt((p + q) / 2));
  for (const [p, q] of segs) g.rect((p - b0) * pps, 0, (q - p) * pps, h);
  g.clip();
  paint('rgba(244, 246, 250, .2)', 'rgba(244, 246, 250, .6)');
  g.restore();
}

/* ---------- Filmstrip timeline: frame clip diambil dari video proxy di browser (canvas), disimpan sebagai blob URL ---------- */
const film = { key: '', n: 0, imgs: [], token: 0, ratio: 16 / 9, timer: null };
const filmKey = () => meta?.proxy && E ? meta.proxy + '|' + E.base.join(',') : '';
const filmHtml = () => film.key && film.key === filmKey()
  ? film.imgs.map(u => u ? `<img src="${u}" alt="">` : '<span class="ph"></span>').join('')
  : '<span class="ph"></span>';
function filmCount() {
  const w = $('film')?.clientWidth || $('tl')?.clientWidth || 800;
  return Math.max(4, Math.min(48, Math.round(w / (44 * film.ratio))));
}
function ensureFilm() {
  const key = filmKey();
  if (!key) return;
  const n = filmCount();
  if (film.key === key && (film.building || Math.abs(film.n - n) <= Math.max(2, film.n * .3))) return;
  clearTimeout(film.timer);
  // Clip baru: langsung; hanya ukuran berubah: tunggu resize selesai
  film.timer = setTimeout(() => buildFilm(key, n), film.key === key ? 400 : 0);
}
const once = (v, ev) => new Promise(res => {
  const done = () => { clearTimeout(to); v.removeEventListener(ev, done); v.removeEventListener('error', done); res(); };
  const to = setTimeout(done, 5000);
  v.addEventListener(ev, done); v.addEventListener('error', done);
});
async function buildFilm(key, n) {
  const tok = ++film.token;
  const fresh = film.key !== key;
  const [b0, b1] = E.base, pad = E.pad[0], url = meta.proxy;
  const imgs = new Array(n).fill(null);
  const old = film.imgs;
  if (fresh) { old.forEach(u => u && URL.revokeObjectURL(u)); Object.assign(film, { key, n, imgs }); paintFilm(); }
  film.building = true;
  const v = document.createElement('video');
  v.muted = true; v.preload = 'auto'; v.playsInline = true; v.src = url;
  try {
    await once(v, 'loadeddata');
    if (tok !== film.token || !v.videoWidth) return;
    film.ratio = v.videoWidth / v.videoHeight;
    const c = document.createElement('canvas');
    c.height = 96; c.width = Math.round(96 * film.ratio);
    const g = c.getContext('2d');
    for (let i = 0; i < n; i++) {
      v.currentTime = Math.max(0, b0 + (i + .5) * (b1 - b0) / n - pad);
      await once(v, 'seeked');
      if (tok !== film.token) return;
      g.drawImage(v, 0, 0, c.width, c.height);
      const blob = await new Promise(r => c.toBlob(r, 'image/jpeg', .72));
      if (tok !== film.token) return;
      imgs[i] = blob ? URL.createObjectURL(blob) : null;
      if (fresh) paintFilm();
    }
    if (!fresh) { old.forEach(u => u && URL.revokeObjectURL(u)); Object.assign(film, { n, imgs }); paintFilm(); }
  } finally {
    if (tok === film.token) film.building = false;
    else if (!fresh || film.imgs !== imgs) imgs.forEach(u => u && URL.revokeObjectURL(u));
    v.removeAttribute('src'); v.load();
  }
}
function paintFilm() { const el = $('film'); if (el) el.innerHTML = filmHtml(); }

// Jenis seleksi yang dilepas saat klik di luar item
const DESELECTABLE = ['line', 'hook', 'media', 'text', 'video', 'effect', 'footage', 'transition', 'music', 'opening', 'sfx', 'intro'];
function bindTimeline() {
  const tl = $('tl');
  let drag = null;
  tl.addEventListener('pointerdown', e => {
    e.preventDefault();
    window.getSelection()?.removeAllRanges();
    const lb = e.target.closest('.sc-lbl');
    if (lb) {
      pause();
      select({ type: 'line', i: +lb.dataset.line });
      return;
    }
    if (e.target.closest('.sc-op')) {
      pause();
      select({ type: 'opening' });
      openTrTab('open');
      return;
    }
    const prh = e.target.closest('[data-pr]');
    if (prh) {
      pause();
      tl.setPointerCapture(e.pointerId);
      drag = { kind: 'pr', which: prh.dataset.pr, x0: e.clientX, a0: prange.a, b0: prange.b };
      document.body.classList.add(prh.dataset.pr === 'm' ? 'moving' : 'dragging');
      return;
    }
    const trb = e.target.closest('.sc-tr');
    if (trb) {
      const id = trb.dataset.tr;
      if (!E.transitions[id]) addTransition(id);
      else { select({ type: 'transition', id }); openTrTab(); previewTransition(id); }
      return;
    }
    const fo = e.target.closest('.sc-foot');
    if (fo) {
      const i = +fo.dataset.fo;
      pause();
      tl.setPointerCapture(e.pointerId);
      if (e.target.closest('[data-drag="fdur"]')) {
        drag = { kind: 'fdur', i, x0: e.clientX, d0: E.footage[i].dur, pps };
        document.body.classList.add('dragging');
      } else {
        drag = { kind: 'fsel', x0: e.clientX };
        seekV(vAt(e.clientX));
      }
      if (sel.type !== 'footage' || sel.i !== i) select({ type: 'footage', i });
      return;
    }
    const sx = e.target.closest('.sc-sfx');
    if (sx) {
      const i = +sx.dataset.sx;
      pause();
      tl.setPointerCapture(e.pointerId);
      drag = { kind: 'sfx', i, x0: e.clientX, v0: sfxStart(E.sfx[i]), moved: false };
      if (sel.type !== 'sfx' || sel.i !== i) select({ type: 'sfx', i });
      document.body.classList.add('moving');
      return;
    }
    const md = e.target.closest('.sc-media, .sc-text, .sc-fx, .sc-music');
    if (md) {
      const tr = md.classList.contains('sc-text') ? 'text' : md.classList.contains('sc-fx') ? 'effect' : md.classList.contains('sc-music') ? 'music' : 'media';
      const i = +(tr === 'text' ? md.dataset.ti : tr === 'effect' ? md.dataset.fi : tr === 'music' ? md.dataset.mu : md.dataset.mi), m = trackArr(tr)[i];
      const kind = e.target.closest('[data-drag]')?.dataset.drag || 'mm';
      drag = { kind, i, tr, off: timeAt(e.clientX) - m.s, offV: vAt(e.clientX) - itemV(m)[0] };
      pause();
      if (sel.type !== tr || sel.i !== i) select({ type: tr, i });
      tl.setPointerCapture(e.pointerId);
      document.body.classList.add(kind === 'mm' ? 'moving' : 'dragging');
      return;
    }
    const h = e.target.closest('[data-drag]');
    const fr = $('film').getBoundingClientRect();
    tl.setPointerCapture(e.pointerId);
    if (!h && e.clientY >= fr.top && e.clientY <= fr.bottom && !isLocked('video')) {
      // Deretan frame: ketuk = pilih video (playhead tidak pindah), geser = scrub
      drag = { kind: 'film', x0: e.clientX, at: timeAt(e.clientX) };
      return;
    }
    drag = { kind: h ? h.dataset.drag : 'seek' };
    // Klik area kosong track = lepas seleksi; penggaris & playhead hanya memindah posisi putar (seleksi tetap)
    const onRuler = e.clientY - tl.getBoundingClientRect().top < 22 || e.target.closest('.playhead');
    if (!h && !onRuler && DESELECTABLE.includes(sel.type)) select({ type: sel.type === 'line' && sel.detail ? 'lines' : 'clip' });
    if (h) { document.body.classList.add('dragging'); pause(); } else seekV(vAt(e.clientX));
  });
  // Auto-scroll: saat menyeret dekat tepi kiri/kanan timeline yang diperbesar, tampilan ikut bergeser
  let lastMove = null, autoRaf = 0;
  const autoScroll = () => {
    autoRaf = -1; // sedang berjalan: onMove() tidak menjadwalkan ulang
    const v = $('tl-view');
    if (!drag || !lastMove || !v || v.scrollWidth <= v.clientWidth) { autoRaf = 0; return; }
    const r = v.getBoundingClientRect(), xx = lastMove.clientX, edge = 48;
    let dx = xx < r.left + edge ? -(r.left + edge - xx) / 3 : xx > r.right - edge ? (xx - (r.right - edge)) / 3 : 0;
    dx = Math.max(-32, Math.min(32, dx));
    const before = v.scrollLeft;
    if (dx) v.scrollLeft = before + dx;
    if (v.scrollLeft !== before) onMove(lastMove);
    autoRaf = dx && drag ? requestAnimationFrame(autoScroll) : 0;
  };
  const onMove = e => {
    if (!drag) return;
    lastMove = e;
    if (!autoRaf) autoRaf = requestAnimationFrame(autoScroll);
    const time = timeAt(e.clientX);
    if (drag.kind === 'film' || drag.kind === 'fsel') {
      if (Math.abs(e.clientX - drag.x0) < 4) return;
      drag.kind = 'seek';
    }
    if (drag.kind === 'seek') return seekV(vAt(e.clientX));
    if (drag.kind === 'sfx') {
      const x = E.sfx[drag.i];
      if (!x || (!drag.moved && Math.abs(e.clientX - drag.x0) < 3)) return;
      drag.moved = true;
      delete x.tr; // digeser manual: tidak lagi mengikuti transisi
      Object.assign(x, sfxAnchor(Math.max(0, Math.min(totalDur() - 0.05, drag.v0 + (e.clientX - drag.x0) / pps))));
      changed();
      renderTimeline();
      return;
    }
    if (drag.kind === 'pr') {
      const dv = (e.clientX - drag.x0) / pps, total = totalDur(), len = drag.b0 - drag.a0;
      if (Math.abs(e.clientX - drag.x0) < 2 && !prange.custom) return;
      prange.custom = true;
      paintRangeBtn();
      if (drag.which === 'a') prange.a = Math.max(0, Math.min(prange.b - 0.5, drag.a0 + dv));
      else if (drag.which === 'b') prange.b = Math.min(total, Math.max(prange.a + 0.5, drag.b0 + dv));
      else { prange.a = Math.max(0, Math.min(total - len, drag.a0 + dv)); prange.b = prange.a + len; }
      return renderTimeline();
    }
    if (drag.kind === 'fdur') {
      // Durasi footage gambar: 0,5–60 dtk
      const f = E.footage[drag.i];
      f.dur = Math.round(Math.max(0.5, Math.min(60, drag.d0 + (e.clientX - drag.x0) / drag.pps)) * 10) / 10;
      changed();
      renderTimeline();
      renderProps();
      return updateTime();
    }
    if (drag.kind === 'm0' || drag.kind === 'm1' || drag.kind === 'mm') {
      const m = trackArr(drag.tr)[drag.i];
      // Magnet ke playhead & batas trim (±8px); hanya untuk item di rentang clip (detik video asli)
      const mag = v => { if (m.ref) return v; for (const p of [t, E.trim[0], E.trim[1]]) if (Math.abs(p - v) * pps < 8) return p; return v; };
      // Item di bagian footage: batas = seluruh video akhir, waktu pointer dalam satuan item
      const [b0, b1] = m.ref ? refRange(m) : E.base;
      const time = m.ref ? outOfV(vAt(e.clientX)) - segStartO(m.ref) : timeAt(e.clientX);
      const seekItem = v => m.ref ? seekV(vOfOut(segStartO(m.ref) + v)) : seek(v);
      if (drag.kind === 'mm') {
        // Geser bebas di seluruh timeline: di bagian footage / intro item menempel ke footage, di rentang clip ke detik video asli
        const d = m.e - m.s;
        const a = itemAnchor(Math.max(0, Math.min(totalDur() - 0.05, vAt(e.clientX) - drag.offV)));
        let s0 = a.s;
        if (!a.ref) {
          s0 = mag(s0);
          if (Math.abs(mag(s0 + d) - (s0 + d)) > 0) s0 = mag(s0 + d) - d;
          s0 = Math.max(E.base[0], Math.min(E.base[1] - d, s0));
        }
        if (a.ref) m.ref = a.ref; else delete m.ref;
        m.s = round3(s0); m.e = round3(s0 + d);
      } else if (drag.kind === 'm0' && drag.tr === 'music') {
        // Ujung kiri musik: isi lagu tetap di tempatnya (off ikut bergeser), tidak bisa melewati awal lagu
        const s = round3(Math.max(b0, m.s - m.off, Math.min(m.e - 0.2, mag(time))));
        m.off = round3(Math.max(0, m.off + s - m.s)); m.s = s; seekItem(m.s);
      } else if (drag.kind === 'm1' && drag.tr === 'music') { m.e = round3(Math.min(b1, m.s + m.dur - m.off, Math.max(m.s + 0.2, mag(time)))); seekItem(m.e - 0.05); }
      else if (drag.kind === 'm0') { m.s = round3(Math.max(b0, Math.min(m.e - 0.2, mag(time)))); seekItem(m.s); }
      else if (drag.kind === 'm1') { m.e = round3(Math.min(b1, Math.max(m.s + 0.2, mag(time)))); seekItem(m.e - 0.05); }
      changed();
      renderTimeline();
      renderProps();
      return draw();
    }
    if (drag.kind === 'trim0') {
      E.trim[0] = Math.max(E.base[0], Math.min(E.trim[1] - 1, snap(time, e.altKey)));
      seek(E.trim[0]);
    } else {
      E.trim[1] = Math.min(E.base[1], Math.max(E.trim[0] + 1, snap(time, e.altKey)));
      seek(E.trim[1] - 0.05);
    }
    changed();
    renderTimeline();
    if (sel.type === 'clip') renderProps();
  };
  tl.addEventListener('pointermove', onMove);
  const end = () => {
    if (drag?.kind === 'film') { tlTapAt = performance.now(); pause(); select({ type: 'video', at: drag.at }); }
    drag = null;
    lastMove = null;
    if (autoRaf > 0) cancelAnimationFrame(autoRaf);
    autoRaf = 0;
    document.body.classList.remove('dragging', 'moving');
  };
  tl.addEventListener('pointerup', end);
  tl.addEventListener('pointercancel', end);
}

// Tempelkan titik trim ke awal/akhir kata terdekat (tahan Alt untuk bebas)
function snap(time, free) {
  if (free) return time;
  let best = time, bd = 0.2;
  for (const l of E.lines) for (const w of l.words) {
    for (const p of [w.s, w.e]) if (Math.abs(p - time) < bd) { bd = Math.abs(p - time); best = p; }
  }
  return Math.round(best * 100) / 100;
}

/* ---------- Panel properti ---------- */


function select(s) {
  if (inlineEd) endInlineEdit(true);
  sel = s;

  renderProps();
  renderTimeline();
  draw();
  if (['line', 'hook', 'text'].includes(s.type)) $('props').scrollTop = 0;
}

function stepper(key, value) {
  return `<div class="stepper"><button data-nudge="${key}" data-v="-0.1">−</button>
    <input data-time="${key}" value="${fmtTs(value - E.base[0])}"><button data-nudge="${key}" data-v="0.1">+</button></div>`;
}

// Split podcast hanya di bagian yang memang menampilkan 2 orang sekaligus (sinkron): berapa detik clip ini yang terbelah
function splitInfo() {
  if (E.pending || !E.shots) return 'Mencari bagian video yang menampilkan 2 orang…';
  const [t0, t1] = E.trim, parts = splitParts().filter(p => p.e > t0 && p.s < t1);
  const sec = parts.reduce((a, p) => a + Math.min(t1, p.e) - Math.max(t0, p.s), 0);
  if (sec < 0.5) return 'Clip ini tidak punya bagian yang menampilkan 2 orang dalam satu gambar, jadi tetap layar penuh mengikuti pembicara. '
    + 'Split screen hanya muncul saat kamera menampilkan 2 orang sekaligus, supaya gerak bibir tetap sinkron.';
  return `<b>${Math.round(sec)} dari ${Math.round(t1 - t0)} detik</b> clip ini terbelah: bagian yang menampilkan 2 orang sekaligus. Bagian 1 orang tetap layar penuh mengikuti pembicara.
    <button type="button" class="lnk-btn split-go" data-split-go="${Math.max(t0, parts[0].s)}">${ic('play')} Lihat bagian split</button>`;
}

// Pilihan rasio atas:bawah (dipakai Auto split, Split game & Split mix)
const splitRatioHtml = lbl => `<div class="split-lbl">${lbl}</div>
    <div class="splits">${SPLIT_RATIOS.map(p => `<button type="button" class="spl ${E.frame.split === p ? 'on' : ''}" data-split="${p}">
      <span class="shape"><i style="flex:${p}"></i><i style="flex:${100 - p}"></i></span>${p}:${100 - p}</button>`).join('')}</div>`;
// Auto split aktif: rasio, berapa detik clip ini terbelah, toleransi
function autoSplitHtml() {
  return `<div class="split-opts">${splitRatioHtml('Atas : Bawah')}
    <p class="muted small" style="margin:8px 0 0">${splitInfo()}</p>
    <div class="p-row"><label>Toleransi<span class="tip" data-tip="Split screen muncul lebih awal sebanyak detik ini. Contoh: AI mendeteksi 2 orang di detik 12, toleransi 2 dtk → split mulai di detik 10.">?</span></label>
      <input type="range" min="0" max="5" step="0.25" id="split-lead" value="${E.split.early}">
      <span class="val">${E.split.early.toFixed(2)}s</span></div></div>`;
}
// Split game: gameplay atas, webcam streamer bawah
function splitPanelHtml() {
  return `<div class="split-opts">${splitRatioHtml('Game : Webcam')}
    <p class="muted small" style="margin:8px 0 0">${E.cam ? 'Atas: gameplay · Bawah: webcam streamer (terdeteksi otomatis).' : E.pending ? 'Mendeteksi posisi webcam…'
      : E.cam === null ? 'Webcam tidak terdeteksi: preview & hasil memakai bagian tengah video.' : 'Atas: gameplay · Bawah: webcam streamer.'}</p></div>`;
}
// Split mix: clip di atas, satu foto / video di bawah. Unggah baru = ganti media lama (tidak menumpuk di pustaka Media)
function mixPanelHtml() {
  const m = E.frame.mix, src = m && 'previews/overlays/' + (m.kind === 'video' ? m.file.replace(/\.\w+$/, '.thumb.jpg') : m.file);
  const input = '<input type="file" id="mix-file" accept="image/png,image/jpeg,image/webp,video/mp4,video/quicktime,video/webm" hidden>';
  return `<div class="split-opts">${splitRatioHtml('Clip : Media')}
    ${m ? `<div class="mix-cur"><img class="mix-th" src="${src}" alt=""><span><b title="${esc(m.name || '')}">${esc(m.name || 'Media')}</b>
        <small class="muted">${m.kind === 'video' ? 'Video · diulang sepanjang clip' : 'Foto · sepanjang clip'}</small></span></div>
      <div class="p-actions"><label class="btn sm">${ic('refresh')} Ganti${input}</label><button type="button" class="btn sm ghost" data-mix-clear>${ic('x')} Lepas</button></div>
      <div class="p-row"><label>Ukuran</label><input type="range" min="20" max="400" step="1" data-mixs value="${Math.round((m.s ?? 1) * 100)}"><span class="val">${Math.round((m.s ?? 1) * 100)}%</span></div>
      <div class="p-actions"><button type="button" class="btn sm" data-mix-fit>${ic('maximize')} Pas panel</button></div>
      <p class="mix-hint">Klik media di preview, lalu seret untuk menggeser. Tarik sudutnya (atau scroll) untuk mengubah ukuran.</p>`
    : `<label class="mix-up">${ic('upload')}<span><b>Unggah foto / video</b><small>Satu media untuk panel bawah. Video diputar tanpa suara & diulang.</small></span>${input}</label>`}</div>`;
}
// Unggah media Split mix; media sebelumnya yang belum tersimpan di mana pun langsung dihapus dari disk
let mixBusy = false;
async function uploadMix(f) {
  if (!/^(image\/(png|jpeg|webp)|video\/(mp4|quicktime|webm|x-m4v))$/.test(f.type)) return toast('Format media harus PNG, JPG, WEBP, MP4, MOV, atau WEBM.');
  if (f.size > UPLOAD_MAX) return toast(`${f.name} terlalu besar (maksimal ${Math.round(UPLOAD_MAX / 1048576)} MB).`);
  if (mixBusy) return;
  mixBusy = true;
  toast(`Mengunggah ${f.name}…`);
  try {
    const fd = new FormData();
    fd.append('file', f);
    const res = await fetch('api.php?action=mix_upload', { method: 'POST', body: fd });
    const d = await res.json().catch(() => ({ error: 'Respons server tidak valid.' }));
    if (!res.ok || d.error) throw new Error(d.error || 'Media gagal diunggah.');
    setMix({ file: d.file, kind: d.kind, name: d.name });
    toast('Media panel bawah diganti');
  } catch (err) { toast(err.message); }
  mixBusy = false;
}
function setMix(m) {
  const old = E.frame.mix?.file;
  E.frame.mix = m;
  if (!m) mixSel = false;
  if (old && old !== m?.file) api('mix_gc', { file: old }).catch(() => {});
  changed(); renderProps(); draw();
}

function renderProps() {
  renderPropsBody();
  $('props').querySelectorAll('input[type=range]').forEach(syncRange);
}
// Isi slider (bagian kiri thumb) mengikuti nilainya
function syncRange(r) {
  const min = +r.min || 0, max = +r.max || 100;
  r.style.setProperty('--p', ((+r.value - min) / (max - min) * 100) + '%');
}
document.addEventListener('input', e => { if (e.target.type === 'range') syncRange(e.target); });


// Item yang bisa dianimasikan dari pilihan sekarang: { obj, s, e, path }
function animTarget() {
  if (sel.type === 'text' && E.texts[sel.i]) { const c = E.texts[sel.i]; return { obj: c, s: c.s, e: c.e, path: `texts.${sel.i}`, def: ANIM_NONE }; }
  if (sel.type === 'media' && E.media[sel.i]) { const m = E.media[sel.i]; return { obj: m, s: m.s, e: m.e, path: `media.${sel.i}`, def: ANIM_NONE }; }
  if (sel.type === 'hook') return { obj: E.hook, s: E.trim[0], e: E.trim[0] + Math.min(E.hook.duration, Math.max(0.5, E.trim[1] - E.trim[0] - 0.1)), path: 'hook', def: HOOK_ANIM };
  return null;
}

/* Template subtitle: kombinasi font, warna, highlight & animasi siap pakai. Posisi & perataan subtitle tidak diubah. */
const SUB_TPL = {
  'viral-kuning': { n: 'Viral Kuning', d: 'Klasik clipper, tebal & sorot kuning', s: { font: 'arial-black', size: 98, color: '#FFFFFF', highlight: '#FFD400', stroke: '#000000', stroke_w: 7, uppercase: true, hl: true, hl_style: 'color', hl_pop: true } },
  'hormozi-box': { n: 'Hormozi Box', d: 'Kotak hijau mencolok per kata', s: { font: 'montserrat', weight: 900, size: 92, color: '#FFFFFF', highlight: '#39E75F', hl_tc: '#0B0B0B', stroke: '#000000', stroke_w: 6, uppercase: true, chunk: 3, hl: true, hl_style: 'box', hl_pop: true } },
  'neon-pop': { n: 'Neon Pop', d: 'Kata muncul memantul, sorot cyan', s: { font: 'anton', size: 104, color: '#FFFFFF', highlight: '#00E5FF', stroke: '#000000', stroke_w: 7, uppercase: true, spacing: 2, hl: true, fx: 'word', wanim: 'pop', smooth: true } },
  'hype-gaming': { n: 'Hype Gaming', d: 'Komik heboh dengan glow hijau', s: { font: 'bangers', size: 110, color: '#FFFFFF', highlight: '#A3FF12', stroke: '#000000', stroke_w: 8, uppercase: true, spacing: 2, hl: true, fx: 'word', wanim: 'glow' } },
  'merah-membara': { n: 'Merah Membara', d: 'Kotak merah tegas, gaya berita', s: { font: 'poppins', weight: 900, size: 90, color: '#FFFFFF', highlight: '#FF3B3B', hl_tc: '#FFFFFF', stroke: '#000000', stroke_w: 6, uppercase: true, chunk: 2, hl: true, hl_style: 'box', hl_pop: true } },
  'minimal-clean': { n: 'Minimal Clean', d: 'Bersih & kalem, muncul lembut', s: { font: 'inter', weight: 600, size: 80, color: '#FFFFFF', highlight: '#FFFFFF', stroke: '#000000', stroke_w: 4, hl: false, blur: true, smooth: true } },
  'cinematic': { n: 'Cinematic', d: 'Serif elegan, sorot emas', s: { font: 'instrument-serif', size: 104, color: '#FFFFFF', highlight: '#E8C27A', stroke: '#000000', stroke_w: 3, hl: true, hl_pop: false, fx: 'word', wanim: 'fade', blur: true, smooth: true } },
  'golden-quote': { n: 'Golden Quote', d: 'Kutipan miring bernuansa mewah', s: { font: 'playfair-display', weight: 800, size: 88, italic: true, color: '#FFFFFF', highlight: '#F5C451', stroke: '#000000', stroke_w: 4, hl: true, hl_pop: true } },
  'soft-aesthetic': { n: 'Soft Aesthetic', d: 'Tulisan tangan pastel yang lembut', s: { font: 'caveat', weight: 700, size: 108, color: '#FFFFFF', highlight: '#FFB8CF', stroke: '#3A1F2B', stroke_w: 4, hl: true, hl_pop: false, fx: 'word', wanim: 'fade', blur: true, smooth: true } },
  'diary-notes': { n: 'Diary Notes', d: 'Spidol dengan stabilo kuning', s: { font: 'permanent-marker', size: 88, color: '#FFFFFF', highlight: '#FFF176', hl_tc: '#1A1A1A', stroke: '#000000', stroke_w: 5, chunk: 2, hl: true, hl_style: 'box', hl_pop: false } },
  'romantis': { n: 'Romantis', d: 'Script manis bernuansa pink', s: { font: 'dancing-script', weight: 700, size: 100, color: '#FFFFFF', highlight: '#FF8FB1', stroke: '#2B0F1A', stroke_w: 4, hl: true, hl_pop: true, blur: true, smooth: true } },
  'mesin-ketik': { n: 'Mesin Ketik', d: 'Huruf muncul seperti diketik', s: { font: 'roboto-slab', weight: 700, size: 84, color: '#FFFFFF', highlight: '#7DD3FC', stroke: '#000000', stroke_w: 5, hl: true, hl_pop: false, fx: 'type' } },
};
// Nilai bawaan untuk field yang tidak disebut template (supaya gaya lama tidak tercampur)
const SUB_TPL_BASE = { weight: 0, bold: false, italic: false, underline: false, spacing: 1, opacity: 100, uppercase: false, chunk: 0,
  hl: true, hl_style: 'color', hl_font: '', hl_tc: '#111111', hl_pop: true, fx: 'none', wanim: 'fade', blur: false, smooth: false };
const tplActive = k => E.style.tpl === k && Object.entries(SUB_TPL[k].s).every(([f, v]) => E.style[f] === v);
function applySubTpl(k) {
  const keep = { pos: E.style.pos, x: E.style.x, align: E.style.align };
  Object.assign(E.style, SUB_TPL_BASE, SUB_TPL[k].s, keep, { tpl: k });
  changed(); renderProps(); draw();
  const l = E.lines[sel.type === 'line' ? sel.i : Math.max(0, activeLine())];
  if (l) { pause(); seek(l.s); play(); }
}
// Contoh mini tiap template: font, warna, garis tepi & cara sorot kata aktif
function tplDemo(t) {
  const s = { ...SUB_TPL_BASE, ...t.s }, f = FONTS.find(x => x.k === s.font);
  const fam = w => w && f?.w.includes(w) ? `"${f.wf}", ${fontFamily(s.font)}` : fontFamily(s.font);
  const cap = w => s.uppercase ? w.toUpperCase() : w;
  const base = `font-family:${fam(s.weight)};font-weight:${s.weight || 400};${s.italic ? 'font-style:italic;' : ''}color:${s.color};-webkit-text-stroke:${Math.min(3, s.stroke_w * .35)}px ${s.stroke};paint-order:stroke fill;font-size:${Math.round(s.size * .19)}px`;
  const hl = !s.hl ? '' : s.hl_style === 'box' ? `background:${s.highlight};color:${s.hl_tc};-webkit-text-stroke:0;padding:0 4px;border-radius:2px;`
    : `color:${s.highlight};`;
  const hf = s.hl_font ? `font-family:${fontFamily(s.hl_font)};` : '';
  return `<span class="tpd" style='${base}'>${cap('kata')} <i style='${hl}${hf}${s.hl && s.hl_pop ? 'font-size:1.1em;' : ''}'>${cap('viral')}</i></span>`;
}
function subTplHtml() {
  return `<div class="card p-card"><div class="p-sec">
    <h3><span>Template subtitle<span class="tip" data-tip="Kombinasi font, warna, highlight & animasi siap pakai. Posisi subtitle tidak berubah; semua bisa diatur lagi setelahnya.">?</span></span></h3>
    <div class="tpls">${Object.entries(SUB_TPL).map(([k, t]) => `<button type="button" class="tpl ${tplActive(k) ? 'on' : ''}" data-subtpl="${k}" title="${esc(t.d)}">
      <span class="tpl-pv">${tplDemo(t)}</span><b>${esc(t.n)}</b></button>`).join('')}</div>
  </div></div>`;
}

// Highlight kata subtitle: aktif/mati, gaya (warna teks / latar kotak), warna, font, membesar
function subHighlightHtml() {
  const st = E.style, sw = (k, on, label) => `<label class="switch"><input type="checkbox" data-k="${k}" aria-label="${label}" ${on ? 'checked' : ''}></label>`;
  return `<div class="card p-card"><div class="p-sec">
    <h3><span>Highlight kata<span class="tip" data-tip="Kata yang sedang diucapkan pembicara otomatis disorot, supaya penonton mudah mengikuti.">?</span></span>${sw('style.hl', st.hl, 'Highlight kata')}</h3>
    ${st.hl ? `<div class="framings two">${[['color', 'Warna teks'], ['box', 'Latar kotak']].map(([k, n]) =>
      `<button type="button" class="framing ${st.hl_style === k ? 'on' : ''}" data-hls="${k}">${n}</button>`).join('')}</div>
    <div class="ts-stack" style="margin-top:10px">
      <div class="ts-c"><label>${st.hl_style === 'box' ? 'Warna latar' : 'Warna'}</label>${hexField('style.highlight', st.highlight)}</div>
      ${st.hl_style === 'box' ? `<div class="ts-c"><label>Warna teks</label>${hexField('style.hl_tc', st.hl_tc)}</div>` : ''}
    </div>
    <div class="p-field"><div class="p-label"><label>Font highlight</label></div>
      <button type="button" class="ts-f fpk-btn${fpkOpen === 'hl' ? ' on' : ''}" data-fpk="hl" aria-expanded="${fpkOpen === 'hl'}" style='font-family:${fontFamily(st.hl_font || st.font)}'>
        <span>${st.hl_font ? esc(fontLabel(st.hl_font)) : 'Sama dengan subtitle'}</span>${ic('chevron-down').replace('class="i"', 'class="i chev"')}</button>
      ${fpkOpen === 'hl' ? fontListHtml('hl', { font: st.hl_font || '' }) : ''}</div>
    <div class="p-row sw-row"><label>Membesar 110%</label>${sw('style.hl_pop', st.hl_pop, 'Kata highlight membesar')}</div>`
    : '<p class="muted small" style="margin:0">Aktifkan untuk menyorot kata yang sedang diucapkan.</p>'}
  </div></div>`;
}
// Animasi subtitle: efek teks (ketik / kata per kata + animasi per kata) & blur saat muncul
function subAnimHtml() {
  const st = E.style, demo = k => k === 'type' ? '<i>A</i><i>b</i><i>c</i>' : k === 'none' ? 'Aa' : '<i>Aa</i> <i>Bb</i>';
  return `<div class="card p-card anim-card"><div class="anim-head">${ic('sparkles')} Animasi</div><div class="p-sec">
      <h3><span>Efek teks<span class="tip" data-tip="Berlaku untuk semua subtitle dan mengikuti waktu ucapan tiap kata.">?</span></span></h3>
      <div class="anims three">${SUB_FX.map(([k, n]) => `<button class="anim ${st.fx === k ? 'on' : ''}" data-sfx="${k}"><span class="tfxv tfx-${k}">${demo(k)}</span>${n}</button>`).join('')}</div>
      ${st.fx === 'word' ? `<div class="p-field"><div class="p-label"><label>Animasi masuk per kata</label></div>
        <div class="framings">${SUB_WANIMS.map(([k, n]) => `<button type="button" class="framing ${st.wanim === k ? 'on' : ''}" data-swa="${k}">${n}</button>`).join('')}</div></div>` : ''}
      <p class="muted small" style="margin:8px 0 0">${{ none: 'Satu baris tampil sekaligus.', type: 'Huruf muncul seperti diketik saat kata diucapkan.', word: 'Kata muncul satu per satu saat diucapkan.' }[st.fx]}</p>
    </div>
    <div class="p-sec"><div class="p-row sw-row" style="margin-top:0"><label>Blur saat muncul<span class="tip" data-tip="Teks muncul sedikit blur lalu menajam, memberi kesan lembut.">?</span></label>
      <label class="switch"><input type="checkbox" data-k="style.blur" aria-label="Blur saat muncul" ${st.blur ? 'checked' : ''}></label></div>
      <div class="p-row sw-row"><label>Smoother<span class="tip" data-tip="Semua animasi teks (blur, fade, pop, glow, muncul baris) jadi lebih panjang & halus, melambat di akhir.">?</span></label>
      <label class="switch"><input type="checkbox" data-k="style.smooth" aria-label="Smoother" ${st.smooth ? 'checked' : ''}></label></div></div></div>`;
}
// Bagian animasi di bawah setelan item terpilih (teks, hook, media, grafik); kosong untuk item lain
function animPanelHtml() {
  if (sel.type === 'line') return subHighlightHtml() + subAnimHtml();
  const tg = animTarget();
  if (!tg) return '';
  const a = tg.obj.anim = { ...tg.def, ...(tg.obj.anim || {}) };
  const vars = (k, ph) => {
    const v = { up: [0, 1], down: [0, -1], left: [1, 0], right: [-1, 0] }[k], sgn = ph === 'out' ? -1 : 1;
    return v ? `--x:${v[0] * sgn * 9}px;--y:${v[1] * sgn * 8}px` : k === 'zoom' ? '--s:.5' : k === 'pop' ? '--s:0' : '';
  };
  const sec = (ph, title) => `<div class="p-sec">
      <h3><span>${title}</span></h3>
      <div class="anims">${ANIMS.map(([k, n]) => `<button class="anim ${a[ph] === k ? 'on' : ''}" data-anim="${ph}" data-v="${k}">
        <span class="av ${ph}" style="${k === 'none' ? '--x:0' : vars(k, ph)}"${k === 'none' ? ' data-none' : ''}></span>${n}</button>`).join('')}</div>
      ${a[ph] !== 'none' ? `<div class="p-row"><label>Durasi</label><input type="range" min="0.1" max="1.5" step="0.05" data-k="${tg.path}.anim.d${ph}" value="${a['d' + ph]}"><span class="val">${(+a['d' + ph]).toFixed(2)}s</span></div>` : ''}
    </div>`;
  const tfx = sel.type !== 'text' ? '' : `<div class="p-sec">
      <h3><span>Efek teks<span class="tip" data-tip="Huruf atau kata muncul / disorot bergantian. Bisa digabung dengan animasi masuk & keluar.">?</span></span></h3>
      <div class="anims three">${TEXT_FX.filter(([k]) => k !== 'karaoke' || a.text === 'karaoke').map(([k, n]) => `<button class="anim ${(a.text || 'none') === k ? 'on' : ''}" data-tfx="${k}"><span class="tfxv tfx-${k}">${
        k === 'type' ? '<i>A</i><i>b</i><i>c</i>' : k === 'none' ? 'Aa' : '<i>Aa</i> <i>Bb</i>'}</span>${n}</button>`).join('')}</div>
      ${a.text === 'type' || a.text === 'word' ? `<div class="p-row"><label>Lama efek</label><input type="range" min="0.2" max="5" step="0.1" data-k="${tg.path}.anim.tdur" value="${a.tdur}"><span class="val">${(+a.tdur).toFixed(1)}s</span></div>` : ''}
      ${a.text === 'karaoke' ? `<div class="p-row"><label>Warna sorot</label><input type="color" class="tfx-hl" data-k="${tg.path}.anim.hl" value="${a.hl.toLowerCase()}" aria-label="Warna sorot"><span class="val">${a.hl}</span></div>` : ''}
      <div class="p-row sw-row"><label>Blur saat muncul</label><label class="switch"><input type="checkbox" data-k="${tg.path}.anim.blur" aria-label="Blur saat muncul" ${a.blur ? 'checked' : ''}></label></div>
      <div class="p-row sw-row"><label>Smoother<span class="tip" data-tip="Blur & animasi kata jadi lebih panjang dan halus, melambat di akhir.">?</span></label><label class="switch"><input type="checkbox" data-k="${tg.path}.anim.smooth" aria-label="Smoother" ${a.smooth ? 'checked' : ''}></label></div>
    </div>`;
  return `<div class="card p-card anim-card"><div class="anim-head">${ic('sparkles')} Animasi</div>${tfx}${sec('in', 'Animasi masuk')}${sec('out', 'Animasi keluar')}
    <div class="p-sec"><div class="p-actions" style="margin-top:0"><button class="btn sm" data-p="anim-play" style="grid-column:1 / -1">${ic('play')} Putar animasi</button></div></div></div>`;
}

// Putar item dari awal (animasi masuk) atau menjelang akhir (animasi keluar)
function playAnim(phase) {
  const tg = animTarget();
  if (!tg) return;
  const [, dout] = animDur(tg.obj.anim, tg.e - tg.s);
  pause();
  seek(phase === 'out' ? Math.max(tg.s, tg.e - dout - 0.6) : Math.max(E.trim[0], tg.s - 0.05));
  play();
  const stop = tg.e + 0.05, chk = () => { if (!playing) return; if (t >= stop) { pause(); seek(tg.e - 0.01); } else requestAnimationFrame(chk); };
  requestAnimationFrame(chk);
}

function renderPropsBody() {
  const P = $('props');
  if (introPanelOn()) {
    P.innerHTML = introPropsHtml();
    return;
  }
  if (sel.type === 'sfx' && E.sfx[sel.i]) {
    const x = E.sfx[sel.i], i = sel.i, f = x.ref !== 'main' && E.footage.find(g => g.id === x.ref);
    P.innerHTML = `<div class="card p-card">
      <div class="p-sec">
        <h3><span>Sound effect</span> <button class="back-link" data-p="back">← Kembali</button></h3>
        <div class="ov-file"><span class="mu-badge">${ic('volume')}</span>
          <div style="min-width:0"><b style="font-size:13px;display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${esc(x.name)}</b>
            <div class="muted small">${x.dur.toFixed(2)} dtk · ${f ? `di ${esc(f.name || 'footage')}` : 'di clip'} · mulai ${fmtTs(sfxStart(x))}</div></div></div>
        <div class="p-row"><label>Volume</label><input type="range" min="0" max="200" step="5" data-k="sfx.${i}.vol" value="${x.vol}"><span class="val">${x.vol}%</span></div>
        <p class="muted small" style="margin:6px 0 0">Geser blok oranye di track SFX untuk memindah posisinya, termasuk ke bagian intro / footage.</p>
        <div class="p-actions"><button class="btn sm" data-p="sfx-play">${ic('play')} Putar</button>
          <button class="btn sm danger" data-p="sfx-remove">${ic('trash')} Hapus SFX</button></div>
      </div></div>`;
    return;
  }
  if (sel.type === 'music' && E.music[sel.i]) {
    const m = E.music[sel.i], i = sel.i;
    P.innerHTML = `<div class="card p-card">
      <div class="p-sec">
        <h3><span>Musik</span> <button class="back-link" data-p="back">← Kembali</button></h3>
        <div class="ov-file"><span class="mu-badge">${ic('music')}</span>
          <div style="min-width:0"><b style="font-size:13px;display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${esc(m.name)}</b>
            <div class="muted small">Dipakai ${fmtDur(m.e - m.s)} dari lagu ${fmtDur(m.dur)}</div></div></div>
        <div class="p-row"><label>Volume</label><input type="range" min="0" max="100" step="1" data-k="music.${i}.vol" value="${m.vol}"><span class="val">${m.vol}%</span></div>
        <div class="p-row"><label>Mulai lagu</label><input type="range" min="0" max="${Math.max(0, m.dur - 1).toFixed(1)}" step="0.1" data-k="music.${i}.off" value="${m.off}"><span class="val">${fmtTs(m.off)}</span></div>
        <div class="p-row"><label>Fade in</label><input type="range" min="0" max="5" step="0.1" data-k="music.${i}.fin" value="${m.fin}"><span class="val">${(+m.fin).toFixed(1)}s</span></div>
        <div class="p-row"><label>Fade out</label><input type="range" min="0" max="5" step="0.1" data-k="music.${i}.fout" value="${m.fout}"><span class="val">${(+m.fout).toFixed(1)}s</span></div>
        <p class="muted small" style="margin:8px 0 0">Suara asli clip diatur di volume clip; musik dicampur di atasnya saat render.</p>
        <div class="p-actions"><button class="btn sm" data-p="mu-full">${ic('range')} Sepanjang clip</button>
          <button class="btn sm danger" data-p="mu-remove">${ic('trash')} Hapus musik</button></div>
      </div></div>`;
    return;
  }
  // Video terpilih: panel kanan tetap seperti tab yang sedang dibuka (tidak ada panel khusus video)
  if (sel.type === 'effect' && E.effects[sel.i]) {
    const fx = E.effects[sel.i], pre = EFFECTS[fx.type];
    P.innerHTML = `<div class="card p-card">
      <div class="p-sec">
        <h3><span>${pre.kind === 'grade' ? 'Filter' : 'Efek'}</span> <button class="back-link" data-p="back">← Kembali</button></h3>
        <div class="ov-file"><span class="fxt" style="pointer-events:none;width:96px;padding:0;border:0;background:none">${pre.kind === 'grade'
          ? `<span class="sw3 flt" style="filter:${gradeCss(pre)}">${pre.tint ? `<i style="background:rgba(${pre.tint.slice(0, 3).join(',')},${pre.tint[3] * 1.6})"></i>` : ''}</span>`
          : `<span class="sw3" style="${fxSwatch(fx.type)}"></span>`}</span>
          <div style="min-width:0"><b style="font-size:13px">${esc(pre.label)}</b><div class="muted small">${fmtDur(fx.e - fx.s)}</div></div></div>
        <div class="p-row"><label>Intensitas</label><input type="range" min="0" max="100" step="5" data-k="effects.${sel.i}.intensity" value="${fx.intensity}"><span class="val">${fx.intensity}%</span></div>
        <div class="p-actions">${pre.kind === 'grade' ? `<button class="btn sm" data-p="fx-full">${ic('range')} Sepanjang clip</button>` : ''}
          <button class="btn sm danger" data-p="fx-remove" style="${pre.kind === 'grade' ? '' : 'grid-column:1 / -1'}">${ic('trash')} Hapus ${pre.kind === 'grade' ? 'filter' : 'efek'}</button></div>
      </div></div>`;
    return;
  }
  if (sel.type === 'transition' && E.transitions[sel.id]) {
    const tr = E.transitions[sel.id], j = junctions().find(x => x.id === sel.id);
    const nm = it => it === 'main' ? 'Clip' : esc(it.name || 'Footage');
    const max = j ? Math.max(0.2, Math.min(2, itemLen(j.a), itemLen(j.b))) : 2;
    P.innerHTML = `<div class="card p-card">
      <div class="p-sec">
        <h3><span>Transisi</span> <button class="back-link" data-p="back">← Kembali</button></h3>
        <div class="ov-file"><span class="fxt on" style="pointer-events:none;width:96px;padding:0;border:0;background:none"><span class="trs tr-${tr.type}"><i class="a"></i><i class="b"></i></span></span>
          <div style="min-width:0"><b style="font-size:13px">${esc(TRANSITIONS[tr.type].label)}</b>${j ? `<div class="muted small">${nm(j.a)} → ${nm(j.b)}</div>` : ''}</div></div>
        <div class="p-row"><label>Durasi</label><input type="range" min="${tr.type === 'paper' ? 0.3 : 0.2}" max="${max.toFixed(2)}" step="0.05" data-k="transitions.${sel.id}.dur" value="${Math.min(max, tr.dur)}"><span class="val">${Math.min(max, tr.dur).toFixed(2)}s</span></div>
        ${tr.type === 'paper' && j ? `<p class="muted small" style="margin:6px 0 0">Gumpalan kertas muncul lalu robek membuka bagian berikutnya. Durasi pendek = robekan lebih cepat.</p>
          ${paperSfxOf(j) ? `<p class="muted small" style="margin:4px 0 0">SFX kertas robek terpasang dan posisinya ikut menyesuaikan durasi transisi.</p>
            ${paperSfxOf(j).file !== paperLib().file ? `<div class="p-actions"><button class="btn sm" data-p="tr-sfx-swap" style="grid-column:1 / -1">${ic('refresh')} Pakai ${esc(paperLib().name)}</button></div>` : ''}`
            : `<div class="p-actions"><button class="btn sm" data-p="tr-sfx" style="grid-column:1 / -1">${ic('volume')} Tambah SFX kertas robek</button></div>`}` : ''}
        <div class="p-actions">
          <button class="btn sm" data-p="tr-play">${ic('play')} Putar</button>
          <button class="btn sm danger" data-p="tr-remove">${ic('trash')} Hapus transisi</button>
        </div>
      </div></div>`;
    return;
  }
  if (sel.type === 'opening' && E.opening) {
    const op = E.opening, pr = OPENINGS[op.type];
    P.innerHTML = `<div class="card p-card">
      <div class="p-sec">
        <h3><span>Opening</span> <button class="back-link" data-p="back">← Kembali</button></h3>
        <div class="ov-file"><span class="fxt on" style="pointer-events:none;width:96px;padding:0;border:0;background:none">${opThumb(op.type)}</span>
          <div style="min-width:0"><b style="font-size:13px">${esc(pr.label)}</b><div class="muted small">${esc((OP_CATS.find(c => c[0] === pr.cat) || [])[1] || '')} · di awal video</div></div></div>
        <div class="p-row"><label>Durasi</label><input type="range" min="0.2" max="5" step="0.1" data-k="opening.dur" value="${op.dur}"><span class="val">${op.dur.toFixed(1)}s</span></div>
        <p class="muted small" style="margin:4px 0 0">Ganti jenis opening dari menu Transisi → Opening.</p>
        <div class="p-actions">
          <button class="btn sm" data-p="op-play">${ic('play')} Putar</button>
          <button class="btn sm danger" data-p="op-remove">${ic('trash')} Hapus opening</button>
        </div>
      </div></div>`;
    return;
  }
  if (sel.type === 'footage' && E.footage[sel.i]) {
    const f = E.footage[sel.i], pre = f.pos === 'pre';
    P.innerHTML = `<div class="card p-card">
      <div class="p-sec">
        <h3><span>Footage</span> <button class="back-link" data-p="back">← Kembali</button></h3>
        <div class="ov-file"><img class="ov-thumb" src="${footThumb(f)}" alt=""><div style="min-width:0"><b style="font-size:13px;word-break:break-all">${esc(f.name || 'Footage')}</b>
          <div class="muted small">${f.kind === 'video' ? 'Video' : 'Gambar'} · ${pre ? 'awal' : 'akhir'} clip · ${fmtTs(f.dur)}</div></div></div>
        ${f.kind === 'image' ? `<div class="p-row"><label>Durasi</label><input type="range" min="0.5" max="15" step="0.5" data-k="footage.${sel.i}.dur" value="${Math.min(15, f.dur)}"><span class="val">${f.dur.toFixed(1)}s</span></div>` : ''}
        <div class="p-actions">
          <button class="btn sm" data-p="foot-move">${ic(pre ? 'skip-forward' : 'skip-back')} Pindah ke ${pre ? 'akhir' : 'awal'}</button>
          <button class="btn sm danger" data-p="foot-remove">${ic('trash')} Hapus footage</button>
        </div>
      </div></div>`;
    return;
  }
  if (sel.type === 'media' && E.media[sel.i]?.gfx) {
    P.innerHTML = gfxPanelHtml(E.media[sel.i]) + animPanelHtml();
    return;
  }
  if (sel.type === 'media' && E.media[sel.i]) {
    const m = E.media[sel.i];
    P.innerHTML = `<div class="card p-card">
      <div class="p-sec">
        <h3><span>Media</span> <button class="back-link" data-p="back">← Kembali</button></h3>
        <div class="ov-file"><img class="ov-thumb" src="previews/overlays/${m.file}" alt=""><div style="min-width:0"><b style="font-size:13px;word-break:break-all">${esc(m.name || 'Gambar')}</b>
          <div class="muted small">${fmtDur(m.e - m.s)} tampil</div></div></div>
        ${m.ref ? refNoteHtml : `<div class="p-row"><label>Mulai</label>${stepper('ms', m.s)}</div>
        <div class="p-row"><label>Selesai</label>${stepper('me', m.e)}</div>`}
        <div class="p-row"><label>Ukuran</label><input type="range" min="0.1" max="1" step="0.01" data-k="media.${sel.i}.w" value="${m.w}"><span class="val">${Math.round(m.w * 100)}%</span></div>
        <div class="p-row"><label>Opacity</label><input type="range" min="0" max="100" step="1" data-k="media.${sel.i}.opacity" value="${m.opacity ?? 100}"><span class="val">${m.opacity ?? 100}%</span></div>
        <div class="p-actions">
          <button class="btn sm" data-p="media-center">${ic('align-center')} Ke tengah</button>
          <button class="btn sm danger" data-p="media-remove">${ic('trash')} Hapus dari timeline</button>
        </div>
      </div></div>` + animPanelHtml();
    return;
  }
  if (sel.type === 'lines') {
    P.innerHTML = `<div class="card p-card">${linesPanel()}</div>`;
    markActiveLine();
    return;
  }
  // Teks / hook terpilih: editor teks, lalu animasi di bawahnya (subtitle tidak bisa dianimasikan)
  if (textSelected()) {
    // Subtitle: template di paling atas (pilihan cepat), lalu editor teks, highlight & animasi
    P.innerHTML = (sel.type === 'line' ? subTplHtml() : '') + textEditorHtml() + animPanelHtml();
    return;
  }
  // Tidak ada item terpilih: pengaturan clip
  // Jenis clip (podcast / streaming game) tidak dipilih di sini: terdeteksi otomatis dari kategori video saat clip dibuat
  P.innerHTML = `<div class="card p-card">
    <div class="p-sec">
      <h3><span>Ikuti pembicara</span><label class="switch"><input type="checkbox" id="follow-on" aria-label="Ikuti pembicara" ${E.follow ? 'checked' : ''}></label></h3>
      <p class="muted small" style="margin:0">${E.follow ? 'Kamera otomatis mengikuti wajah orang yang sedang bicara.' : 'Mati: video memakai bagian tengah, kamera tidak bergerak.'}</p>
    </div>
    <div class="p-sec">
      <h3><span>Auto split</span><label class="switch"><input type="checkbox" id="autosplit-on" aria-label="Auto split" ${autoSplitOn() ? 'checked' : ''} ${(E.frame.ratio || 'full') === 'full' ? '' : 'disabled'}></label></h3>
      <p class="muted small" style="margin:0">${(E.frame.ratio || 'full') !== 'full' ? 'Pakai layout Full untuk memakai Auto split.'
        : autoSplitOn() ? 'Layar terbelah atas-bawah saat 2 narasumber tampil bersamaan.' : 'Layar otomatis terbelah atas-bawah di momen 2 narasumber tampil bersamaan.'}</p>
      ${autoSplitOn() ? autoSplitHtml() : ''}
    </div>
    <div class="p-sec">
      <h3><span>Layout</span></h3>
      <div class="ratios lay">${FRAME_RATIOS.map(([k, n, a]) => {
        const h = Math.round(Math.min(32, 18 / a)), w = Math.round(Math.min(18, 32 * a));
        return `<button class="ratio ${(E.frame.ratio || 'full') === k ? 'on' : ''}" data-ratio="${k}"><span class="shape"><i style="width:${w}px;height:${h}px"></i></span>${n}</button>`;
      }).join('')}<button class="ratio ${E.frame.ratio === 'split' ? 'on' : ''}" data-ratio="split" title="Gameplay di atas, webcam streamer di bawah"><span class="shape split"><i></i><i></i></span>Split game</button>
        <button class="ratio ${E.frame.ratio === 'mix' ? 'on' : ''}" data-ratio="mix" title="Clip di atas, foto / video pilihan sendiri di bawah"><span class="shape mix"><i></i><i></i></span>Split mix</button></div>
      ${E.frame.ratio === 'split' ? splitPanelHtml() : E.frame.ratio === 'mix' ? mixPanelHtml() : ''}
      <div class="p-row"><label>Sudut</label><input type="range" min="0" max="120" step="2" data-k="frame.radius" value="${E.frame.radius || 0}"><span class="val">${E.frame.radius || 0}px</span></div>
    </div>
    ${['split', 'mix'].includes(E.frame.ratio) ? '' : `<div class="p-sec">
      <h3><span>Background<span class="tip" data-tip="Isi layar di luar video saat layout bukan Full. Solid = hitam, Blur = video yang sama diperbesar & diblur.">?</span></span></h3>
      <div class="bgs">${[['solid', 'Solid'], ['blur', 'Blur']].map(([k, n]) =>
        `<button type="button" class="bgopt ${E.frame.bg === k ? 'on' : ''}" data-fbg="${k}" ${(E.frame.ratio || 'full') === 'full' ? 'disabled' : ''}><span class="sw-${k}"></span>${n}</button>`).join('')}</div>
      ${(E.frame.ratio || 'full') === 'full' ? '<p class="muted small" style="margin:8px 0 0">Pilih layout selain Full untuk memakai background.</p>' : ''}
    </div>`}
    <div class="p-sec">
      <h3><span>Gaya teks</span></h3>
      <div class="framings two">${[['subtitle', 'Subtitle'], ['kinetic', 'Teks kinetik']].map(([k, n]) =>
        `<button class="framing ${(E.caption || 'subtitle') === k ? 'on' : ''}" data-caption="${k}">${n}</button>`).join('')}</div>
      ${E.caption === 'kinetic' ? `<div class="p-actions"><button class="btn sm" data-p="kin-rebuild" style="grid-column:1 / -1">${ic('refresh')} Susun ulang dari subtitle</button></div>` : ''}
    </div>
    <div class="p-sec">
      <h3><span>Volume audio</span><span class="muted small" id="vol-val">${E.volume}%</span></h3>
      <div class="vol-row">${ic('volume')}<input class="p-range" type="range" min="0" max="200" step="5" data-k="volume" value="${E.volume}" aria-label="Volume audio">
        <button type="button" class="btn sm ghost" data-p="vol-reset" title="Kembalikan ke 100%">100%</button></div>
    </div>
    <div class="p-sec">
      <button class="btn sm ghost danger" data-p="reset">${ic('reset')} Reset ke hasil AI</button><span class="tip" data-tip="Membuang semua editan clip ini (trim, teks, media, efek, gaya), lalu menyiapkan dan merender ulang dari hasil analisa.">?</span>
    </div>
  </div>`;
}

/* ---------- Editor teks terpadu: subtitle (gaya semua baris), hook, dan teks tambahan ---------- */
const textSelected = () => (sel.type === 'line' && !!E.lines[sel.i]) || sel.type === 'hook' || (sel.type === 'text' && !!E.texts[sel.i]);

function textEditorHtml() {
  const sw = (k, on, label) => `<label class="switch"><input type="checkbox" data-k="${k}" aria-label="${label}" ${on ? 'checked' : ''}></label>`;
  let title, tg, text, max, toggle = '', actions, ph = '', info = '';
  if (sel.type === 'line') {
    const l = E.lines[sel.i];
    title = 'Subtitle';
    tg = 'style'; text = l.words.map(w => w.w).join(' '); max = 300;
    toggle = sw('subtitles', E.subtitles, 'Tampilkan subtitle');
    info = 'Isi teks hanya mengubah baris ini, sedangkan gaya teks berlaku untuk semua baris subtitle.';
    actions = `<button class="btn sm danger" data-p="delete" style="grid-column:1 / -1">${ic('trash')} Hapus baris</button>`;
  } else if (sel.type === 'hook') {
    title = 'Teks hook';
    tg = 'hook'; text = E.hook.text; max = 120; ph = ' placeholder="Kalimat pembuka yang bikin penasaran…"';
    toggle = sw('hook.enabled', E.hook.enabled, 'Tampilkan hook');
    actions = `<button class="btn sm danger" data-p="hook-remove" style="grid-column:1 / -1">${ic('trash')} Hapus hook</button>`;
  } else {
    const c = E.texts[sel.i];
    title = 'Teks';
    tg = 'texts.' + sel.i; text = c.text; max = 200;
    actions = `<button class="btn sm danger" data-p="text-remove" style="grid-column:1 / -1">${ic('trash')} Hapus teks</button>`;
  }
  return `<div class="card p-card"><div class="p-sec">
    <h3><span>${title}${info ? `<span class="tip" data-tip="${info}">?</span>` : ''}</span>${toggle}</h3>
    <div class="p-field" style="margin-top:0">
      <div class="p-label"><label for="sel-text">Teks</label><span class="cnt" id="sel-cnt">${text.length}/${max}</span></div>
      <input class="input p-input" id="sel-text" value="${esc(text)}" maxlength="${max}" spellcheck="false"${ph}>
    </div>
    ${textPanel(tg)}
    <div class="p-actions">${actions}</div>
  </div></div>`;
}

// Tab Teks tanpa teks terpilih: format teks hanya sebagai tampilan (nonaktif, tidak terhubung ke teks mana pun)

// Semua baris subtitle: teks bisa langsung diketik; ⋯ membuka detail (waktu, pecah, gabung, hapus)
function linesPanel() {
  const [t0, t1] = E.trim;
  // Hanya baris di rentang rekomendasi AI (bagian luar itu tidak mungkin masuk clip)
  const rows = E.lines.map((l, i) => [l, i]).filter(([l]) => l.e > E.base[0] && l.s < E.base[1]).map(([l, i]) => `<div class="ln ${l.e <= t0 || l.s >= t1 ? 'dim' : ''}" data-li="${i}">
      <span class="ln-t" data-seek="${i}" title="Putar dari baris ini">${fmtTs(Math.max(0, l.s - E.base[0]))}</span>
      <input data-li="${i}" value="${esc(l.words.map(w => w.w).join(' '))}" spellcheck="false">
      <button data-detail="${i}" title="Waktu, pecah, gabung, hapus">⋯</button></div>`).join('');
  return `<div class="p-sec">
    <h3><span>Teks subtitle<span class="tip" data-tip="Baris pudar berada di luar trim dan tidak ikut di clip. Tombol ⋯ untuk mengatur waktu, memecah, menggabung, atau menghapus baris.">?</span></span> <button class="back-link" data-p="back">← Pengaturan clip</button></h3>
    <div class="lines">${rows || '<p class="hint">Belum ada baris subtitle.</p>'}</div>
  </div>`;
}

// Tandai baris yang sedang tampil di daftar baris
function markActiveLine() {
  const i = activeLine();
  document.querySelectorAll('.ln').forEach(r => r.classList.toggle('on', +r.dataset.li === i));
}

function setPath(path, value) {
  const keys = path.split('.');
  let o = E;
  keys.slice(0, -1).forEach(k => { o = o[k]; });
  o[keys[keys.length - 1]] = value;
}

// Ubah teks satu baris; waktu kata dipertahankan kalau jumlah kata sama
function setLineText(i, text) {
  const l = E.lines[i];
  const tokens = text.trim().split(/\s+/).filter(Boolean);
  if (!tokens.length) return;
  if (tokens.length === l.words.length) {
    tokens.forEach((w, k) => { l.words[k].w = w; });
  } else {
    const total = l.e - l.s, weight = tokens.map(w => w.length + 1), sum = weight.reduce((a, b) => a + b, 0);
    let acc = l.s;
    l.words = tokens.map((w, k) => {
      const s = acc; acc += total * weight[k] / sum;
      return { s: round3(s), e: round3(acc), w };
    });
  }
}
const round3 = v => Math.round(v * 1000) / 1000;

function setLineTime(i, key, value) {
  const l = E.lines[i];
  if (key === 'ls') {
    const v = Math.min(value, l.words[0].e - 0.05);
    l.words[0].s = round3(v); l.s = round3(v);
  } else {
    const last = l.words[l.words.length - 1];
    const v = Math.max(value, last.s + 0.05);
    last.e = round3(v); l.e = round3(v);
  }
}

function lineAction(act) {
  const i = sel.i, l = E.lines[i];
  if (act === 'delete') {
    E.lines.splice(i, 1);
    select(sel.detail ? { type: 'lines' } : { type: 'clip' });
  }
  changed();
  draw();
}

// Isi panel properti dibuat ulang tiap ganti pilihan; event dipasang sekali di elemen induknya
function bindProps() {
  const P = $('props');
  // Setelan grafik intro (lihat introPropsHtml)
  P.addEventListener('click', e => { if (introPanelOn()) introClick(e); });
  P.addEventListener('input', e => { if (introPanelOn()) introInput(e); });
  P.addEventListener('change', e => { if (introPanelOn() && e.target.closest('[data-itc], [data-itch], #it-label, #it-w')) introChange(e); });
  P.addEventListener('input', e => {
    if (e.target.matches('[data-mixs]') && E.frame.mix) {
      // Ukuran media Split mix (titik tengah tetap)
      E.frame.mix.s = +e.target.value / 100;
      e.target.nextElementSibling.textContent = e.target.value + '%';
      draw();
      return;
    }
    const gk = e.target.dataset.gfx || e.target.dataset.gfxc || e.target.dataset.gfxh;
    if (gk) {
      const m = E.media[sel.i];
      if (!m?.gfx) return;
      let v = e.target.value;
      if (e.target.type === 'range') {
        v = +v;
        e.target.nextElementSibling.textContent = v + '%';
      } else if (e.target.dataset.gfxh) {
        v = v.replace(/^#/, '');
        if (!/^[0-9a-f]{6}$/i.test(v)) return;
        v = '#' + v.toUpperCase();
        const sw = P.querySelector(`[data-gfxc="${gk}"]`);
        if (sw) { sw.value = v.toLowerCase(); sw.parentElement.style.setProperty('--c', v); }
      } else if (e.target.dataset.gfxc) {
        v = v.toUpperCase();
        e.target.parentElement.style.setProperty('--c', v);
        const h = P.querySelector(`[data-gfxh="${gk}"]`);
        if (h) h.value = v.slice(1);
      }
      m.gfx[gk] = v;
      const cn = e.target.closest('.p-field')?.querySelector('.cnt');
      if (cn && e.target.maxLength > 0) cn.textContent = `${v.length}/${e.target.maxLength}`;
      changed();
      scheduleGfx(m);
      return;
    }
    if (e.target.id === 'split-lead') {
      e.target.nextElementSibling.textContent = (+e.target.value).toFixed(2) + 's';
      applyTolerance(+e.target.value);
      return;
    }
    if (e.target.dataset.wt) {
      // Ketebalan: 'r' / 'b' = font bawaan (normal / tebal), angka = ketebalan asli
      const c = cfgOf(e.target.dataset.wt), v = e.target.value;
      c.weight = /^\d+$/.test(v) ? +v : 0;
      c.bold = v === 'b';
      if (c.weight) c.bold = false;
      changed(); draw();
      return;
    }
    const ts = e.target.dataset.ts, hx = e.target.dataset.hex, k = e.target.dataset.k;
    if (ts && e.target.type === 'color') {
      setPath(ts, e.target.value.toUpperCase());
      e.target.parentElement.style.setProperty('--c', e.target.value);
      const h = P.querySelector(`[data-hex="${ts}"]`);
      if (h) h.value = e.target.value.slice(1).toUpperCase();
    } else if (hx) {
      const v = e.target.value.replace(/^#/, '');
      if (!/^[0-9a-f]{6}$/i.test(v)) return;
      setPath(hx, '#' + v.toUpperCase());
      const sw = P.querySelector(`input[type=color][data-ts="${hx}"]`);
      if (sw) { sw.value = '#' + v.toLowerCase(); sw.parentElement.style.setProperty('--c', '#' + v); }
    } else if (ts && e.target.type === 'number') {
      const v = parseFloat(e.target.value);
      if (isNaN(v)) return;
      setPath(ts, Math.max(+e.target.min, Math.min(+e.target.max, v)));
      // Field kembar (mis. tebal border muncul di dua tempat) ikut diperbarui
      P.querySelectorAll(`input[type=number][data-ts="${ts}"]`).forEach(o => { if (o !== e.target) o.value = e.target.value; });
    } else if (ts && e.target.tagName === 'SELECT') {
      setPath(ts, ts.endsWith('.bold') ? e.target.value === '1' : e.target.value);
      // Ganti font: daftar ketebalan ikut font baru (ketebalan yang tidak ada kembali ke bawaan)
      if (ts.endsWith('.font')) {
        const c = cfgOf(ts.slice(0, -5));
        if (c.weight && !fontWeightOf(c)) c.weight = 0;
        const sel = P.querySelector(`[data-wt="${ts.slice(0, -5)}"]`);
        if (sel) sel.innerHTML = weightOptions(c);
      }
    } else if (k && e.target.type === 'range') {
      setPath(k, +e.target.value);
      const mw = /^media\.(\d+)\.w$/.exec(k);
      if (mw && isFullGfx(E.media[+mw[1]])) scheduleGfx(E.media[+mw[1]]);
      if (k === 'volume') { $('vol-val').textContent = e.target.value + '%'; applyVolume(); }
      // Angka nilai ada di samping slider (.p-row) atau di label atasnya (.p-field); volume punya labelnya sendiri
      const val = k === 'volume' ? null : e.target.closest('.p-field')?.querySelector('.val') || e.target.nextElementSibling;
      if (k === 'frame.radius') val.textContent = e.target.value + 'px';
      else if (/\.anim\.d(in|out)$/.test(k)) val.textContent = (+e.target.value).toFixed(2) + 's';
      else if (/\.anim\.tdur$/.test(k)) val.textContent = (+e.target.value).toFixed(1) + 's';
      else if (/^transitions\./.test(k)) { val.textContent = (+e.target.value).toFixed(2) + 's'; renderTimeline(); }
      else if (val && /^sfx\.\d+\.vol$/.test(k)) val.textContent = e.target.value + '%';
      else if (k === 'opening.dur') { val.textContent = (+e.target.value).toFixed(1) + 's'; renderTimeline(); draw(); }
      else if (/^footage\.\d+\.dur$/.test(k)) { val.textContent = (+e.target.value).toFixed(1) + 's'; renderTimeline(); updateTime(); }
      else if (val && /^effects\.\d+\.intensity$/.test(k)) val.textContent = e.target.value + '%';
      else if (val && /^media\.\d+\.opacity$/.test(k)) val.textContent = e.target.value + '%';
      else if (val && /^music\.\d+\.vol$/.test(k)) val.textContent = e.target.value + '%';
      else if (val && /^music\.\d+\.off$/.test(k)) { val.textContent = fmtTs(+e.target.value); musicResync = true; }
      else if (val && /^music\.\d+\.f(in|out)$/.test(k)) val.textContent = (+e.target.value).toFixed(1) + 's';
      else if (val) val.textContent = k === 'style.pos' || /^media\.\d+\.w$/.test(k) ? Math.round(e.target.value * 100) + '%' : k === 'hook.duration' ? (+e.target.value).toFixed(1) + 's' : e.target.value;
    } else if (k && e.target.type === 'color') {
      setPath(k, e.target.value.toUpperCase());
      if (e.target.nextElementSibling?.classList.contains('val')) e.target.nextElementSibling.textContent = e.target.value.toUpperCase();
    } else if (e.target.matches('.ln input')) {
      setLineText(+e.target.dataset.li, e.target.value);
      renderTimeline();
    } else if (e.target.id === 'sel-text') {
      const v = e.target.value;
      if (sel.type === 'line') setLineText(sel.i, v);
      else if (sel.type === 'hook') E.hook.text = v;
      else if (sel.type === 'text') E.texts[sel.i].text = v;
      $('sel-cnt').textContent = v.length + '/' + e.target.maxLength;
      renderTimeline();
    } else return;
    changed();
    draw();
  });
  P.addEventListener('change', e => {
    if (e.target.id === 'follow-on') { E.follow = e.target.checked; changed(); renderProps(); draw(); return; }
    if (e.target.matches('[data-mixs]') && E.frame.mix) { changed(); return; }
    if (e.target.id === 'autosplit-on') { const prev = autoSplitOn(); E.split.enabled = e.target.checked; return applyLayout(prev); }
    if (e.target.id === 'mix-file' && e.target.files.length) return uploadMix(e.target.files[0]);
    if (e.target.dataset.ts && e.target.type === 'number') {
      const v = e.target.dataset.ts.split('.').reduce((o, k) => o[k], E);
      e.target.value = v;
      return;
    }
    if (e.target.dataset.hex) {
      e.target.value = e.target.dataset.hex.split('.').reduce((o, k) => o[k], E).slice(1);
      return;
    }
    if (e.target.matches('[data-gfxfill]') && E.media[sel.i]?.gfx) {
      const m = E.media[sel.i];
      m.gfx.fill = e.target.checked;
      if (!m.gfx.fill) m.gfx.ext = 0;
      changed(); scheduleGfx(m, true);
      return;
    }
    const k = e.target.dataset.k;
    if (k && e.target.type === 'checkbox') {
      setPath(k, e.target.checked);
      changed(); draw(); renderTimeline();
      if (k === 'style.hl') renderProps();
      return;
    }
    const tk = e.target.dataset.time;
    if (tk) {
      const v = parseTs(e.target.value);
      if (!isNaN(v)) applyTime(tk, v + E.base[0]);
    }
  });
  P.addEventListener('focusin', e => {
    if (!e.target.matches('.ln input')) return;
    pause();
    seek(E.lines[+e.target.dataset.li].s + 0.01);
    markActiveLine();
  });
  P.addEventListener('click', e => {
    const fo = e.target.closest('[data-fpk-tg]');
    if (fo) return pickFont(fo.dataset.fpkTg, fo.dataset.fpkV);
    const fb = e.target.closest('[data-fpk]');
    if (fb) {
      fpkOpen = fpkOpen === fb.dataset.fpk ? null : fb.dataset.fpk;
      renderProps();
      if (fpkOpen) P.querySelector('.fpk-opt.on')?.scrollIntoView({ block: 'nearest' });
      return;
    }
    const ts = e.target.closest('[data-seek]');
    if (ts) { seek(E.lines[+ts.dataset.seek].s + 0.01); markActiveLine(); return play(); }
    const b = e.target.closest('button');
    if (!b) return;
    if (b.dataset.nudge) {
      const k = b.dataset.nudge, cur = { t0: E.trim[0], t1: E.trim[1], ls: E.lines[sel.i]?.s, le: E.lines[sel.i]?.e,
        ms: E.media?.[sel.i]?.s, me: E.media?.[sel.i]?.e, xs: E.texts?.[sel.i]?.s, xe: E.texts?.[sel.i]?.e }[k];
      return applyTime(k, cur + Number(b.dataset.v));
    }
    if (b.dataset.split) { E.frame.split = +b.dataset.split; changed(); renderProps(); draw(); return; }
    if (b.dataset.splitGo) { seek(+b.dataset.splitGo + 0.05); return play(); }
    if (b.dataset.caption) return setCaption(b.dataset.caption);
    if (b.dataset.ratio) { const prev = autoSplitOn(); E.frame.ratio = b.dataset.ratio; return applyLayout(prev); }
    if (b.dataset.mixClear !== undefined) return setMix(null);
    if (b.dataset.mixFit !== undefined && E.frame.mix) { Object.assign(E.frame.mix, { s: 1, cx: 0.5, cy: 0.5 }); changed(); renderProps(); draw(); return; }
    if (b.dataset.fbg) { E.frame.bg = b.dataset.fbg; changed(); renderProps(); draw(); return; }
    if (b.dataset.p === 'kin-rebuild') { changed(); return ensureKinetic(true).then(() => toast('Teks kinetik disusun ulang')); }
    if (b.dataset.anim) {
      const tg = animTarget();
      if (!tg) return;
      tg.obj.anim = { ...tg.def, ...(tg.obj.anim || {}), [b.dataset.anim]: b.dataset.v };
      changed(); renderProps(); draw();
      if (b.dataset.v !== 'none') playAnim(b.dataset.anim);
      return;
    }
    if (b.dataset.subtpl) return applySubTpl(b.dataset.subtpl);
    if (b.dataset.hls) { E.style.hl_style = b.dataset.hls; changed(); renderProps(); draw(); return; }
    if (b.dataset.swa) {
      E.style.wanim = b.dataset.swa;
      changed(); renderProps(); draw();
      const l = E.lines[sel.i];
      if (l) { pause(); seek(l.s); play(); }
      return;
    }
    if (b.dataset.sfx) {
      // Efek teks subtitle: terapkan ke semua baris, lalu putar baris terpilih dari awal
      E.style.fx = b.dataset.sfx;
      changed(); renderProps(); draw();
      const l = E.lines[sel.i];
      if (l) { pause(); seek(l.s); play(); }
      return;
    }
    if (b.dataset.tfx) {
      const tg = animTarget();
      if (!tg) return;
      tg.obj.anim = { ...tg.def, ...(tg.obj.anim || {}), text: b.dataset.tfx };
      changed(); renderProps(); draw();
      if (b.dataset.tfx !== 'none') playAnim('in');
      return;
    }
    if (b.dataset.p === 'sub-tpl') {
      if (!E.lines.length) return toast('Subtitle belum siap');
      const i = Math.max(0, activeLine());
      return select({ type: 'line', i });
    }
    if (b.dataset.p === 'anim-play') return playAnim('in');
    if (b.dataset.p === 'op-play') return playOpening();
    if (b.dataset.p === 'op-remove') return setOpening('');
    if (b.dataset.p === 'vol-reset') {
      E.volume = 100;
      changed(); applyVolume(); renderProps();
      return;
    }
    if (b.dataset.p === 'media-remove') return removeMedia(sel.i);
    if (b.dataset.p === 'text-remove') return removeText(sel.i);
    if (b.dataset.gfxAlign !== undefined && E.media[sel.i]?.gfx) {
      const m = E.media[sel.i];
      m.gfx.ta = b.dataset.gfxAlign;
      changed(); scheduleGfx(m, true); renderProps();
      return;
    }
    if (b.dataset.gfxType && E.media[sel.i]?.gfx) {
      // Ganti template: teks & font dipertahankan, warna & label mengikuti template baru; clip lain ikut
      const m = E.media[sel.i];
      setGfxType(m, b.dataset.gfxType);
      changed(); renderProps(); renderTimeline(); scheduleGfx(m, true);
      if (!m.ref) syncGfxOthers(m);
      return;
    }
    if (b.dataset.p === 'fx-remove') return removeEffect(sel.i);
    if (b.dataset.p === 'mu-full' && E.music[sel.i]) {
      const m = E.music[sel.i];
      // Mulai di awal clip; panjangnya sepanjang clip (atau sampai lagu habis kalau lagunya lebih pendek)
      delete m.ref;
      m.s = E.trim[0];
      m.e = round3(Math.min(E.trim[1], m.s + m.dur - m.off));
      changed(); renderProps(); renderTimeline();
      return;
    }
    if (b.dataset.p === 'mu-remove' && E.music[sel.i]) return removeMusic(sel.i);
    if (b.dataset.p === 'fx-full' && E.effects[sel.i]) {
      delete E.effects[sel.i].ref;
      Object.assign(E.effects[sel.i], { s: E.trim[0], e: E.trim[1] });
      changed(); renderProps(); renderTimeline(); draw();
      return;
    }
    if (b.dataset.p === 'foot-remove') return removeFootage(sel.i);
    if (b.dataset.p === 'intro-open') return openIntro();
    if (b.dataset.p === 'tr-sfx') { const j = junctions().find(x => x.id === sel.id); if (j) addPaperSfx(j); return; }
    if (b.dataset.p === 'tr-sfx-swap') {
      const j = junctions().find(x => x.id === sel.id), x = j && paperSfxOf(j), lib = paperLib();
      if (x) { Object.assign(x, { file: lib.file, name: lib.name, dur: +lib.dur }); changed(); renderTimeline(); renderProps(); toast(`SFX diganti: ${lib.name}`); }
      return;
    }
    if (b.dataset.p === 'sfx-play') return playSfxItem(sel.i);
    if (b.dataset.p === 'sfx-remove') return removeSfx(sel.i);
    if (b.dataset.p === 'tr-remove') return removeTransition(sel.id);
    if (b.dataset.p === 'tr-play') return previewTransition(sel.id, true);
    if (b.dataset.p === 'foot-move') return moveFootage(sel.i);
    if (b.dataset.p === 'hook-remove') return deleteSelectedText();
    if (b.dataset.p === 'seg-toggle') return toggleSegment();
    if (b.dataset.p === 'sel-sub') {
      // Baris yang sedang tampil, atau baris pertama di dalam trim
      let i = activeLine();
      if (i < 0) i = E.lines.findIndex(l => l.e > E.trim[0] && l.s < E.trim[1]);
      if (i < 0) return;
      pause(); seek(Math.max(E.lines[i].s, E.trim[0]) + 0.01);
      return select({ type: 'line', i });
    }
    if (b.dataset.p === 'add-hook') return addGraphic('hook');
    if (b.dataset.p === 'sel-hook') {
      if (!E.hook.text.trim()) E.hook.text = 'Teks hook';
      if (!E.hook.enabled) { E.hook.enabled = true; changed(); }
      pause(); seek(E.trim[0] + 0.05);
      return select({ type: 'hook' });
    }
    if (b.dataset.p === 'media-center') {
      Object.assign(E.media[sel.i], { x: 0.5, y: 0.5 });
      changed(); draw();
      return;
    }
    if (b.dataset.p === 'center-text') {
      // Tengahkan kotak teks secara horizontal, perataan tetap
      const tg = b.dataset.v, c = cfgOf(tg), el = textElOf(tg);
      const w = el && el.offsetParent ? el.getBoundingClientRect().width / ($('phone').clientWidth / 1080) : 0;
      c.x = ({ left: 540 - w / 2, right: 540 + w / 2 }[c.align] ?? 540) / 1080;
      if (!w && c.align !== 'center') { c.align = 'center'; c.x = 0.5; renderProps(); }
      changed(); draw();
      return;
    }
    if (b.dataset.align) {
      setAlign(b.dataset.align, b.dataset.v);
      b.parentElement.querySelectorAll('[data-align]').forEach(o => o.classList.toggle('on', o === b));
      changed(); draw();
      return;
    }
    if (b.dataset.chunk !== undefined) {
      E.style.chunk = +b.dataset.chunk;
      changed(); renderProps(); draw();
      return;
    }
    if (b.dataset.flag) {
      const f = b.dataset.flag;
      setPath(f, !f.split('.').reduce((o, k) => o[k], E));
      b.classList.toggle('on');
      changed(); draw();
      return;
    }
    if (b.dataset.detail) { pause(); seek(E.lines[+b.dataset.detail].s + 0.01); return select({ type: 'line', i: +b.dataset.detail, detail: true }); }
    if (b.dataset.p === 'back') return select({ type: 'clip' });
    if (b.dataset.p === 'lines') return select({ type: 'lines' });
    if (b.dataset.p === 'reset') return resetEdit();
    if (b.dataset.p) lineAction(b.dataset.p);
  });
}

/* ---------- Teks kinetik: beat dari server (api kinetic), digambar di kanvas preview sama dengan render ASS ---------- */
const kfam = k => (FONTS.find(f => f.k === k) || FONTS[0]).family;
let kinLoading = null;
// Ambil (ulang) layout beat dari server; build = susun beat baru dari kata subtitle
async function ensureKinetic(build) {
  if (!E || (kinLoading && !build)) return kinLoading;
  const req = build || !E.kinetic.beats.length
    ? { lines: E.lines, trim: E.trim, y: E.kinetic.y }
    : { beats: E.kinetic.beats, y: E.kinetic.y };
  req.x = E.kinetic.x ?? 0.5;
  const ref = { x: req.x, y: E.kinetic.y };
  const ed = E;
  kinLoading = api('kinetic', req).then(async d => {
    await Promise.all([`italic 100px "${kfam('archivo-black')}"`, `100px "${kfam('libre-baskerville')}"`].map(f => document.fonts.load(f).catch(() => {})));
    if (ed !== E) return;
    E.kinetic.beats = d.beats;
    E._klayout = d.layout;
    E._kref = ref;
    draw();
  }).catch(err => toast(err.message)).finally(() => { kinLoading = null; });
  return kinLoading;
}
function drawKinetic(on) {
  const c = $('kcv');
  if (!c) return;
  const ctx2 = c.getContext('2d'), W = c.clientWidth, H = c.clientHeight, dpr = devicePixelRatio || 1;
  if (c.width !== Math.round(W * dpr)) { c.width = Math.round(W * dpr); c.height = Math.round(H * dpr); }
  ctx2.setTransform(dpr, 0, 0, dpr, 0, 0);
  ctx2.clearRect(0, 0, W, H);
  const kb = $('kbox');
  if (kb) kb.hidden = true;
  if (!on) return;
  if (!E._klayout) { ensureKinetic(); return; }
  const bi = E.kinetic.beats.findIndex(b => t >= b.s && t < b.e), L = E._klayout[bi];
  if (!L) return;
  const k = W / 1080, be = E.kinetic.beats[bi].e;
  // Layout dihitung server di posisi referensi; posisi blok yang digeser cukup digeser rata
  const ref = E._kref || { x: 0.5, y: E.kinetic.y };
  const ox = ((E.kinetic.x ?? 0.5) - ref.x) * 1080, oy = (E.kinetic.y - ref.y) * 1920;
  ctx2.translate(ox * k, oy * k);
  if (kb) {
    const x0 = Math.min(...L.map(w => w.x)), x1 = Math.max(...L.map(w => w.x + w.width));
    const y0 = Math.min(...L.map(w => w.base - w.em * 0.82)), y1 = Math.max(...L.map(w => w.bottom));
    Object.assign(kb.style, { left: (x0 + ox - 10) * k + 'px', top: (y0 + oy - 8) * k + 'px', width: (x1 - x0 + 20) * k + 'px', height: (y1 - y0 + 16) * k + 'px' });
    kb.hidden = false;
  }
  for (const w of L) {
    const st = Math.max(E.kinetic.beats[bi].s, w.s - 0.04);
    if (t < st || t >= be) continue;
    const p = Math.min(1, (t - st) / 0.13), sc = 0.72 + 0.28 * p, a = Math.min(1, (t - st) / 0.09);
    ctx2.save();
    ctx2.translate((w.x + w.width / 2) * k, w.bottom * k);
    ctx2.scale(sc, sc);
    ctx2.globalAlpha = a;
    ctx2.font = `${w.italic ? 'italic ' : ''}${w.em * k}px "${kfam(w.font)}"`;
    ctx2.textAlign = 'center';
    ctx2.shadowColor = 'rgba(0,0,0,.45)'; ctx2.shadowBlur = 10 * k; ctx2.shadowOffsetY = 4 * k;
    ctx2.fillStyle = w.c;
    ctx2.fillText(w.w, 0, (w.base - w.bottom) * k);
    ctx2.restore();
  }
}
// Seret blok teks kinetik: posisi tengah blok (x, y pecahan layar); magnet ke garis tengah horizontal
function bindKineticDrag() {
  const kb = $('kbox');
  if (!kb) return;
  kb.addEventListener('pointerdown', e => {
    if (e.button !== 0 || !E) return;
    e.preventDefault();
    e.stopPropagation();
    pause();
    const k = $('phone').clientWidth / 1080, sx = e.clientX, sy = e.clientY, x0 = E.kinetic.x ?? 0.5, y0 = E.kinetic.y;
    kb.setPointerCapture(e.pointerId);
    kb.classList.add('drag');
    const move = ev => {
      let x = x0 + (ev.clientX - sx) / k / 1080;
      if (Math.abs(x - 0.5) * 1080 < 16) x = 0.5;
      E.kinetic.x = Math.round(Math.max(0.15, Math.min(0.85, x)) * 1e4) / 1e4;
      E.kinetic.y = Math.round(Math.max(0.08, Math.min(0.92, y0 + (ev.clientY - sy) / k / 1920)) * 1e4) / 1e4;
      changed();
      draw();
    };
    const up = () => {
      kb.classList.remove('drag');
      kb.removeEventListener('pointermove', move);
      kb.removeEventListener('pointerup', up);
      kb.removeEventListener('pointercancel', up);
    };
    kb.addEventListener('pointermove', move);
    kb.addEventListener('pointerup', up);
    kb.addEventListener('pointercancel', up);
  });
  kb.addEventListener('click', e => e.stopPropagation());
}

async function setCaption(mode) {
  E.caption = mode;
  changed();
  if (mode === 'kinetic' && !E.kinetic.beats.length) {
    if (!E.lines.length) toast('Subtitle belum siap; teks kinetik disusun begitu subtitle tersedia.');
    else await ensureKinetic(true);
  }
  renderProps();
  draw();
}

/* ---------- Tooltip ---------- */

const tipbox = document.createElement('div');
tipbox.className = 'tipbox';
document.body.appendChild(tipbox);
let tipEl = null;
document.addEventListener('mouseover', e => {
  const tip = e.target.closest('[data-tip]');
  if (!tip || tip === tipEl || !tip.dataset.tip) return;
  tipEl = tip;
  tipbox.textContent = tip.dataset.tip;
  const r = tip.getBoundingClientRect();
  const w = tipbox.offsetWidth, h = tipbox.offsetHeight;
  const left = Math.max(8, Math.min(innerWidth - w - 8, r.left + r.width / 2 - w / 2));
  const up = r.bottom + 8 + h > innerHeight;
  tipbox.style.left = left + 'px';
  tipbox.style.top = (up ? r.top - h - 8 : r.bottom + 8) + 'px';
  tipbox.classList.toggle('up', up);
  tipbox.classList.add('show');
});
document.addEventListener('mouseout', e => {
  if (!tipEl || tipEl.contains(e.relatedTarget)) return;
  if (e.target.closest('[data-tip]') === tipEl) { tipEl = null; tipbox.classList.remove('show'); }
});

/* ---------- Overlay proses di preview ---------- */

function busy(title, sub) {
  $('busy-title').textContent = title;
  $('busy-sub').textContent = sub || '';
  $('busy').classList.add('show');
}
const idle = () => $('busy').classList.remove('show');
const wait = ms => new Promise(r => setTimeout(r, ms));

function splitSummary() {
  const parts = splitParts().filter(p => p.e > E.trim[0] && p.s < E.trim[1]);
  const secs = parts.reduce((a, p) => a + Math.min(p.e, E.trim[1]) - Math.max(p.s, E.trim[0]), 0);
  return { n: parts.length, secs };
}

// Layout / Auto split berubah: turunkan layout, lalu siapkan data yang dibutuhkan kalau resepnya belum punya
// (posisi webcam untuk Split game, posisi 2 orang untuk Auto split). prev = Auto split aktif sebelum perubahan.
let layoutBusy = false;
async function applyLayout(prev = false) {
  syncLayout();
  const game = E.frame.ratio === 'split', auto = autoSplitOn();
  const need = !E.pending && (game ? E.cam === undefined : auto && (!E.shots || !E.precise));
  if (need && !layoutBusy) {
    layoutBusy = true;
    pause();
    busy(game ? 'Mencari webcam streamer…' : 'Mendeteksi posisi orang…',
      game ? 'Mendeteksi posisi wajah streamer di video. Biasanya beberapa detik.' : 'Mencari bagian video yang menampilkan 2 orang. Biasanya beberapa detik.');
    try {
      const d = await api('edit_track', { id: PID, iid: IID });
      Object.assign(E, { segments: d.segments, shots: d.shots, cam: d.cam, precise: d.precise });
    } catch (err) {
      toast(err.message);
    } finally {
      idle();
      layoutBusy = false;
    }
  }
  // Subtitle paling enak di tengah (di antara dua orang) saat Auto split aktif; kembalikan kalau dimatikan
  const p = E.style.pos;
  if (auto && !prev && Math.abs(p - 0.71) < 0.02) E.style.pos = 0.56;
  if (!auto && prev && Math.abs(p - 0.56) < 0.02) E.style.pos = 0.71;
  changed();
  renderProps();
  renderTimeline();
  draw();
  if (game && E.cam === null) toast('Webcam tidak terdeteksi, memakai bagian tengah video');
  if (auto && !prev && E.shots) {
    const r = splitSummary();
    toast(r.n ? `Auto split · ${r.n} bagian dengan 2 orang (${Math.round(r.secs)} dtk)` : 'Tidak ada bagian dengan 2 orang di clip ini');
  }
}

// Toleransi diterapkan setelah slider berhenti digeser, dengan loading singkat sebagai tanda
let tolTimer = null;
function applyTolerance(v) {
  clearTimeout(tolTimer);
  tolTimer = setTimeout(async () => {
    busy(`Menerapkan toleransi ${v.toFixed(2)} dtk…`, 'Split screen dimajukan sebelum bagian 2 orang.');
    await wait(500);
    E.split.early = v;
    changed();
    renderTimeline();
    draw();
    idle();
    const r = splitSummary();
    toast(`Split muncul ${v.toFixed(2)} dtk lebih awal · ${r.n} bagian split`);
  }, 350);
}

function applyTime(key, v) {
  if (key === 't0') { E.trim[0] = Math.max(E.base[0], Math.min(E.trim[1] - 1, round3(v))); seek(E.trim[0]); }
  if (key === 't1') { E.trim[1] = Math.min(E.base[1], Math.max(E.trim[0] + 1, round3(v))); seek(E.trim[1] - 0.05); }
  if (key === 'ls' || key === 'le') setLineTime(sel.i, key, v);
  const m = E.media?.[sel.i];
  if (key === 'ms' && m) { m.s = round3(Math.max(E.base[0], Math.min(m.e - 0.2, v))); seek(m.s); }
  if (key === 'me' && m) { m.e = round3(Math.min(E.base[1], Math.max(m.s + 0.2, v))); seek(m.e - 0.05); }
  const tx = E.texts?.[sel.i];
  if (key === 'xs' && tx) { tx.s = round3(Math.max(E.base[0], Math.min(tx.e - 0.2, v))); seek(tx.s + 0.01); }
  if (key === 'xe' && tx) { tx.e = round3(Math.min(E.base[1], Math.max(tx.s + 0.2, v))); seek(tx.e - 0.05); }
  changed();
  renderProps();
  renderTimeline();
  draw();
}

/* ---------- Simpan & render ---------- */

async function resetEdit() {
  if (!confirm('Buang semua editan clip ini dan kembali ke hasil AI?')) return;
  try {
    await api('edit_reset', { id: PID, iid: IID });
    setDirty(false);
    openClip(IID, true);
    loadProject();
  } catch (err) { toast(err.message); }
}

const payload = () => ({ trim: E.trim, layout: E.layout, split: E.split, subtitles: E.subtitles, style: E.style, lines: E.lines, hook: E.media.some(m => m._fromHook && !m.file) ? { ...E.hook, enabled: true } : E.hook, overlay: E.overlay, media: E.media.map(m => Object.fromEntries(Object.entries(m).filter(([k]) => k[0] !== '_'))), volume: E.volume, volKf: E.volKf, opening: E.opening, sfx: E.sfx, texts: E.texts, cuts: E.cuts, gaps: E.gaps, effects: E.effects, footage: E.footage, music: E.music, transitions: E.transitions, tracks: E.tracks, layers: E.layers, caption: E.caption, kinetic: E.kinetic, frame: E.frame, kind: E.kind, follow: E.follow });

/* ---------- Terapkan style clip ini ke clip lain (alur template) ---------- */
// Bagian style yang bisa disalin; avail() = ada isinya di clip sumber
const isHookGfx = m => /^hook/.test(m.gfx?.type || '');
const isTplGfx = m => !!m.gfx && !isHookGfx(m);   // template grafik berita / judul (bukan hook AI)
const spanFull = (it, ed) => it.s <= ed.trim[0] + 0.3 && it.e >= ed.trim[1] - 0.3;   // tampil sepanjang clip
// Sumber style: clip yang sedang diedit (E), atau preset yang dipilih di menu Preset
let styFrom = null;
const SRC = () => styFrom?.edit || E;
const STY_PARTS = [
  { k: 'sub', name: 'Subtitle', desc: 'Font, ukuran, warna, posisi, & mode teks kinetik', avail: () => true },
  { k: 'hook', name: 'Hook / judul AI', desc: 'Template, warna, posisi, & animasi grafik hook (kalimat tiap clip tetap)', avail: () => SRC().media.some(isHookGfx) },
  { k: 'gfx', name: 'Template grafik', desc: 'Grafik berita / judul: template, label, warna, posisi, ukuran, & animasi', avail: () => SRC().media.some(isTplGfx) },
  { k: 'frame', name: 'Framing & bingkai', desc: 'Mode kamera, rasio, sudut membulat, split screen', avail: () => true },
  { k: 'filter', name: 'Filter warna', desc: 'Filter grading dipasang sepanjang clip tujuan', avail: () => SRC().effects.some(fx => EFFECTS[fx.type]?.kind === 'grade') },
  { k: 'fixed', name: 'Teks & logo tetap', desc: 'Elemen yang tampil sepanjang clip, mis. watermark atau @username', avail: () => SRC().media.some(m => !m.gfx && spanFull(m, SRC())) || SRC().texts.some(c => spanFull(c, SRC())) },
  { k: 'opening', name: 'Opening', desc: 'Animasi opening di awal video', avail: () => !!SRC().opening },
  { k: 'audio', name: 'Musik & volume', desc: 'Musik latar, volume clip, & status bisu musik', avail: () => !!SRC().music.length || (SRC().volume ?? 100) !== 100 },
];
let styParts = new Set(['sub', 'hook', 'gfx', 'frame', 'filter', 'fixed', 'opening', 'audio']);
try {
  const v = JSON.parse(localStorage.getItem('ed-sty-parts'));
  if (Array.isArray(v)) styParts = new Set(v);
  // Bagian "Template grafik" baru: ikut tercentang sekali untuk pengguna yang sudah punya pilihan tersimpan
  if (!localStorage.getItem('ed-sty-gfx')) { styParts.add('gfx'); localStorage.setItem('ed-sty-gfx', '1'); }
} catch {}
// Teks template grafik di clip tujuan: 'title' = judul clip masing-masing, 'same' = persis sama dengan clip ini
let styGfxText = 'title';
try { if (localStorage.getItem('ed-sty-gfxtext') === 'same') styGfxText = 'same'; } catch {}
const styState = {};   // iid -> { ready, pick, status, cls }
let styBusy = false;

async function openStyle(preset = null) {
  if (!E || !project) return;
  styFrom = preset && preset.edit ? preset : null;
  if (!styFrom && project.items.length < 2) return toast('Proyek ini hanya punya 1 clip.');
  $('sty-title').textContent = styFrom ? `Pakai preset “${styFrom.name}”` : 'Terapkan style ke clip lain';
  $('sty-desc').textContent = styFrom
    ? 'Style dari preset disalin ke clip yang dipilih (clip ini sudah tercentang). Isi tiap clip tetap miliknya sendiri: teks subtitle, potongan, dan kalimat hook tidak ikut berubah.'
    : 'Style dari clip ini disalin ke clip yang dipilih. Isi tiap clip tetap miliknya sendiri: teks subtitle, potongan, dan kalimat hook tidak ikut berubah.';
  $('sty').hidden = false;
  renderStyParts();
  Object.keys(styState).forEach(k => delete styState[k]);
  // Dari preset: clip ini ikut jadi tujuan (tercentang), clip lain bisa dicentang; dari clip ini: semua clip lain
  project.items.filter(it => styFrom || it.iid !== IID).forEach(it => { styState[it.iid] = { ready: null, pick: styFrom ? it.iid === IID : true, status: '', cls: '' }; });
  renderStyClips();
  // Clip yang editornya belum siap (resep belum dibuat) belum bisa diberi style
  await Promise.all(Object.keys(styState).map(async iid => {
    let ok = false;
    try { const d = await api('edit_get', null, `&id=${PID}&iid=${iid}&peek=1`); ok = !!d.edit; } catch {}
    Object.assign(styState[iid], { ready: ok, pick: ok && styState[iid].pick, status: ok ? '' : 'Editor belum siap', cls: '' });
  }));
  renderStyClips();
}
function renderStyParts() {
  $('sty-parts').innerHTML = STY_PARTS.map(p => {
    const ok = p.avail();
    return `<label class="sty-opt${ok ? '' : ' na'}"><input type="checkbox" data-part="${p.k}" ${ok && styParts.has(p.k) ? 'checked' : ''} ${ok ? '' : 'disabled'}>
      <span><b>${p.name}</b><small>${ok ? p.desc : styFrom ? 'Tidak ada di preset ini' : 'Tidak ada di clip ini'}</small></span></label>`
      + (p.k === 'gfx' && ok && styParts.has('gfx') ? `<div class="sty-sub"><span>Teks grafik:</span>
        <label><input type="radio" name="sty-gfxtext" value="title" ${styGfxText === 'title' ? 'checked' : ''}> Judul clip masing-masing</label>
        <label><input type="radio" name="sty-gfxtext" value="same" ${styGfxText === 'same' ? 'checked' : ''}> Sama persis</label></div>` : '');
  }).join('');
}
function renderStyClips() {
  const rows = project.items.map((it, i) => ({ it, i })).filter(({ it }) => styState[it.iid]);
  $('sty-clips').innerHTML = rows.map(({ it, i }) => {
    const st = styState[it.iid];
    const thumb = `api.php?action=frame&id=${project.analysis_id}&t=${Math.round((it.start + it.end) / 2 * 10) / 10}`;
    const working = st.status === 'Menerapkan…';
    return `<label class="sty-clip${st.ready === false ? ' na' : ''}"><input type="checkbox" data-sc="${it.iid}" ${st.pick ? 'checked' : ''} ${st.ready && !styBusy ? '' : 'disabled'}>
      <span class="th"><img src="${thumb}" alt="" loading="lazy">${working || st.ready === null ? '<span class="busy2"><span class="spin"></span></span>' : ''}</span>
      <span class="tx"><span class="tt" title="${esc(it.title)}"><span class="no">${i + 1}</span>${esc(it.title)}${it.iid === IID ? '<span class="me">clip ini</span>' : ''}</span><span class="st ${st.cls}">${st.ready === null || working ? '' : esc(st.status)}</span></span>
      <span class="ck">${ic('check')}</span></label>`;
  }).join('');
  const ready = Object.values(styState).filter(s => s.ready), n = ready.filter(s => s.pick).length;
  $('sty-go').disabled = styBusy || !n || !STY_PARTS.some(p => p.avail() && styParts.has(p.k));
  $('sty-go').innerHTML = styBusy ? '<span class="spin"></span> Menerapkan…' : `${ic('copy')} Terapkan ke ${n} clip`;
  $('sty-all').textContent = ready.length && n === ready.length ? 'Kosongkan' : 'Pilih semua';
}
function closeStyle() { if (!styBusy) { $('sty').hidden = true; styFrom = null; } }

// Gambar ulang PNG grafik (teks clip tujuan, gaya clip sumber) lalu unggah; mengembalikan nama file
async function gfxFile(gfx) {
  const c = await gfxCanvas(gfx, 2);
  const blob = await new Promise(r => c.toBlob(r, 'image/png'));
  const fd = new FormData();
  fd.append('file', blob, 'grafik.png');
  const res = await fetch('api.php?action=overlay_upload', { method: 'POST', body: fd });
  const d = await res.json().catch(() => ({ error: 'Respons server tidak valid.' }));
  if (!res.ok || d.error) throw new Error(d.error || 'Grafik gagal disimpan.');
  return d.file;
}

// Salin bagian style terpilih dari E (clip sumber) ke resep clip tujuan T
async function styleInto(T, parts, item) {
  const rid = () => Math.random().toString(36).slice(2, 10);
  const clone = o => JSON.parse(JSON.stringify(o));
  const E = SRC(); // sumber: clip ini atau preset
  const [s0, s1] = E.trim, [t0, t1] = T.trim;
  // Waktu relatif dari awal clip; yang sampai ujung clip sumber ikut sampai ujung clip tujuan
  const mapSpan = it => {
    const s = Math.min(t1 - 0.2, t0 + Math.max(0, it.s - s0));
    const e = it.e >= s1 - 0.3 ? t1 : Math.min(t1, t0 + (it.e - s0));
    return { s: round3(s), e: round3(Math.max(s + 0.2, e)) };
  };
  T.media = T.media || []; T.texts = T.texts || []; T.effects = T.effects || []; T.music = T.music || []; T.tracks = T.tracks || {};
  if (parts.has('sub')) {
    T.style = clone(E.style);
    T.subtitles = E.subtitles;
    T.caption = E.caption;
    T.kinetic = { ...(T.kinetic || {}), y: E.kinetic.y, x: E.kinetic.x ?? 0.5, beats: T.kinetic?.beats || [] };
    // Teks kinetik butuh beat dari subtitle clip tujuan sendiri
    if (E.caption === 'kinetic' && !T.kinetic.beats.length && T.lines?.length) {
      try { T.kinetic.beats = (await api('kinetic', { lines: T.lines, trim: T.trim, y: T.kinetic.y, x: T.kinetic.x })).beats; } catch {}
    }
  }
  if (parts.has('hook')) {
    const src = E.media.find(isHookGfx);
    if (src) {
      const look = { type: src.gfx.type, c1: src.gfx.c1, c2: src.gfx.c2, c3: src.gfx.c3 };
      let targets = T.media.filter(isHookGfx);
      // Hook AI clip tujuan yang belum pernah dibuka di editor: jadikan grafik hook dengan gaya clip sumber
      if (!targets.length && T.hook?.enabled && String(T.hook.text || '').trim()) {
        const m = { id: rid(), file: '', name: src.name, gfx: { label: '', text: String(T.hook.text).trim(), ...look },
          s: round3(t0), e: round3(Math.min(t1, t0 + (src.e - src.s))) };
        T.media.push(m);
        T.hook.enabled = false;
        targets = [m];
      }
      for (const m of targets) {
        Object.assign(m.gfx, look);
        Object.assign(m, { x: src.x, y: src.y, w: src.w, anim: clone(src.anim || null) });
        m.file = await gfxFile(m.gfx);
      }
    }
  }
  if (parts.has('gfx')) {
    // Template grafik berita / judul: template, label, warna, posisi, ukuran, animasi & waktu tampil (relatif ke awal clip).
    // Salinan memakai id sumber, jadi menerapkan ulang mengganti salinan lama (tidak dobel)
    const src = E.media.filter(isTplGfx), ids = new Set(src.map(m => m.id));
    T.media = T.media.filter(m => !ids.has(m.id));
    for (const m of src) {
      const c = { ...clone(m), ...mapSpan(m) };
      const title = String(item?.title || '').trim();
      // Banner selebar layar: tepi atas sama dengan clip sumber, pita dihitung ulang untuk teks clip tujuan
      const top = isFullGfx(m) ? m.y * 1920 - (await gfxCanvas(m.gfx, 1)).height * (1080 * m.w / (GFX_W + 36)) / 2 : null;
      const retitle = styGfxText === 'title' && title && title !== c.gfx.text;
      if (retitle) c.gfx.text = title.slice(0, 200);
      if (top !== null) await fitBand(c, top);
      if (retitle || top !== null) c.file = await gfxFile(c.gfx); // gambar ulang dengan judul / pita clip tujuan
      T.media.push(c);
    }
  }
  if (parts.has('frame')) {
    T.layout = E.layout;
    T.kind = E.kind;
    T.follow = E.follow;
    T.frame = clone(E.frame);
    T.split = clone(E.split);
  }
  if (parts.has('filter')) {
    T.effects = T.effects.filter(fx => EFFECTS[fx.type]?.kind !== 'grade')
      .concat(E.effects.filter(fx => EFFECTS[fx.type]?.kind === 'grade').map(fx => ({ ...clone(fx), id: rid(), ...mapSpan(fx) })));
  }
  if (parts.has('fixed')) {
    // Salinan memakai id sumber, jadi menerapkan ulang mengganti salinan lama (tidak dobel)
    const ids = new Set([...E.media.filter(m => !m.gfx), ...E.texts].map(x => x.id));
    T.media = T.media.filter(m => !ids.has(m.id)).concat(E.media.filter(m => !m.gfx && spanFull(m, E)).map(m => ({ ...clone(m), s: round3(t0), e: round3(t1) })));
    T.texts = T.texts.filter(c => !ids.has(c.id)).concat(E.texts.filter(c => spanFull(c, E)).map(c => ({ ...clone(c), s: round3(t0), e: round3(t1) })));
    T.layers = clone(E.layers);
  }
  if (parts.has('opening')) T.opening = clone(E.opening);
  if (parts.has('audio')) {
    T.volume = E.volume;
    // Keyframe: posisi relatif dari awal clip (yang jatuh di luar clip tujuan dibuang)
    T.volKf = [];
    T.music = E.music.map(m => {
      const sp = mapSpan(m);
      return { ...clone(m), id: rid(), ...sp, e: round3(Math.min(sp.e, sp.s + m.dur - m.off)), kf: [] };
    });
    T.tracks.music = { ...(E.tracks.music || {}) };
  }
  return T;
}

async function applyStyle() {
  const parts = new Set([...styParts].filter(k => STY_PARTS.find(p => p.k === k)?.avail()));
  const targets = Object.entries(styState).filter(([, s]) => s.ready && s.pick).map(([iid]) => iid);
  if (!targets.length || !parts.size) return;
  const render = $('sty-render').checked;
  styBusy = true;
  renderStyClips();
  try { await flushGfx(); } catch {}
  // Clip ini ikut jadi tujuan (dari preset): simpan editan yang belum tersimpan dulu, lalu dimuat ulang setelahnya
  const self = targets.includes(IID);
  if (self && dirty) {
    try { await api('edit_save', { id: PID, iid: IID, edit: payload() }); setDirty(false); } catch (err) { toast(err.message); }
  }
  let ok = 0;
  for (const iid of targets) {
    Object.assign(styState[iid], { status: 'Menerapkan…', cls: '' });
    renderStyClips();
    try {
      const d = await api('edit_get', null, `&id=${PID}&iid=${iid}&peek=1`);
      if (!d.edit) throw new Error('Editor belum siap');
      const T = await styleInto(d.edit, parts, project.items.find(x => x.iid === iid));
      await api(render ? 'edit_render' : 'edit_save', { id: PID, iid, edit: T });
      vcache.delete(iid); peeked.delete(iid);
      Object.assign(styState[iid], { status: render ? '✓ Diterapkan · render' : '✓ Diterapkan', cls: 'ok', pick: false });
      markEdited(iid);
      ok++;
    } catch (err) {
      Object.assign(styState[iid], { status: '⚠ ' + err.message, cls: 'err' });
    }
    renderStyClips();
  }
  styBusy = false;
  renderStyClips();
  loadProject();
  toast(ok ? `${styFrom ? 'Preset' : 'Style'} diterapkan ke ${ok} clip${render ? ' · render dimulai' : ''}` : 'Style gagal diterapkan');
  if (self && styState[IID]?.cls === 'ok') { $('sty').hidden = true; styFrom = null; openClip(IID, true); }
}

$('btn-style').onclick = () => openStyle();

/* ---------- Preset editing (menu Preset di panel kiri) ---------- */
let presets = [];
async function loadPresets() {
  try { presets = (await api('presets')).presets; } catch { presets = []; }
  renderPresets();
}
// Style clip ini yang disimpan ke preset: bagian yang sama dengan "Terapkan style"
function presetSnapshot() {
  const clone = o => JSON.parse(JSON.stringify(o));
  const strip = m => Object.fromEntries(Object.entries(m).filter(([k]) => k[0] !== '_'));
  return clone({
    trim: E.trim, style: E.style, subtitles: E.subtitles, caption: E.caption, kinetic: { y: E.kinetic.y, x: E.kinetic.x ?? 0.5 },
    media: E.media.filter(m => m.gfx || spanFull(m, E)).map(strip), texts: E.texts.filter(c => spanFull(c, E)),
    effects: E.effects.filter(fx => EFFECTS[fx.type]?.kind === 'grade'), layout: E.layout, frame: E.frame, split: E.split, kind: E.kind, follow: E.follow,
    volume: E.volume, volKf: E.volKf, opening: E.opening, music: E.music, tracks: { music: E.tracks.music || {} }, layers: E.layers,
  });
}
function presetPreview(S) {
  // Mini layar 9:16: posisi grafik (gambar aslinya) & subtitle sesuai preset
  const imgs = (S.media || []).filter(m => m.file).map(m => `<img src="previews/overlays/${esc(m.file)}" alt="" style="left:${m.x * 100}%;top:${m.y * 100}%;width:${m.w * 100}%;opacity:${(m.opacity ?? 100) / 100}">`).join('');
  const st = S.style || {};
  const sub = S.subtitles ? `<span class="sb" style="top:${(st.pos ?? 0.71) * 100}%;color:${esc(st.color || '#fff')}">AB<span style="color:${esc(st.highlight || '#FFD400')}">CD</span></span>` : '';
  return `<div class="ps-prev">${imgs}${sub}</div>`;
}
function presetChips(S) {
  const out = [];
  const font = FONTS.find(f => f.k === S.style?.font)?.label;
  if (S.subtitles !== false && S.style) out.push(`<span><i style="background:${esc(S.style.color)}"></i><i style="background:${esc(S.style.highlight)}"></i>${esc(font || 'Subtitle')}${S.caption === 'kinetic' ? ' · kinetik' : ''}</span>`);
  (S.media || []).filter(m => m.gfx).forEach(m => out.push(`<span><i style="background:${esc(m.gfx.c1 === '#FFFFFF' ? m.gfx.c2 : m.gfx.c1)}"></i>${esc(GFX[m.gfx.type]?.name || 'Grafik')}</span>`));
  if (S.effects?.length) out.push(`<span>${esc(EFFECTS[S.effects[0].type]?.label || 'Filter')}</span>`);
  if (S.frame?.ratio && S.frame.ratio !== 'full') out.push(`<span>${S.frame.ratio === 'split' ? 'Split game' : S.frame.ratio === 'mix' ? 'Split mix' : 'Bingkai ' + esc(S.frame.ratio)}</span>`);
  if (S.music?.length) out.push(`<span>♪ ${esc(S.music[0].name || 'Musik')}</span>`);
  return out.join('');
}
function renderPresets() {
  $('preset-info').textContent = presets.length || '';
  $('preset-list').innerHTML = presets.length ? presets.map(p => `<div class="ps-card" data-ps="${p.id}">
      ${presetPreview(p.edit)}
      <div style="min-width:0"><div class="nm" title="${esc(p.name)}">${esc(p.name)}</div>
        <div class="dt">Diperbarui ${new Date((p.updated_at || p.created_at) * 1000).toLocaleDateString('id-ID', { day: 'numeric', month: 'short' })}</div>
        <div class="ps-chips">${presetChips(p.edit)}</div>
        <div class="ps-acts"><button class="btn sm primary" data-ps-use title="Pakai di clip ini / clip lain">Pakai</button>
          <button class="btn sm ghost ic" data-ps-over title="Timpa dengan style clip ini">${ic('save')}</button>
          <button class="btn sm ghost ic" data-ps-ren title="Ganti nama">${ic('pencil')}</button>
          <button class="btn sm ghost ic danger" data-ps-del title="Hapus preset">${ic('trash')}</button></div></div>
    </div>`).join('')
    : `<div class="ps-empty" id="preset-empty"><b>Belum ada preset</b><br>Atur style clip ini (subtitle, grafik, filter, dll.), lalu klik <b>+</b> untuk menyimpannya sebagai preset.</div>`;
}
function openPresetForm() {
  if (!E) return toast('Tunggu editor selesai dimuat.');
  $('preset-form').hidden = false;
  let n = presets.length + 1;
  while (presets.some(p => p.name === `Clip style ${n}`)) n++;
  $('preset-name').value = `Clip style ${n}`;
  $('preset-name').focus();
  $('preset-name').select();
}
$('preset-add').onclick = openPresetForm;
$('preset-form').onsubmit = async e => {
  e.preventDefault();
  const name = $('preset-name').value.trim();
  if (!name) return;
  try {
    await flushGfx();
    presets = (await api('preset_save', { name, edit: presetSnapshot() })).presets;
    $('preset-form').hidden = true;
    renderPresets();
    toast(`Preset "${name}" disimpan`);
  } catch (err) { toast(err.message); }
};
$('preset-name').addEventListener('keydown', e => { if (e.key === 'Escape') $('preset-form').hidden = true; });
$('preset-list').addEventListener('click', async e => {
  if (e.target.closest('#preset-empty')) return openPresetForm();
  const card = e.target.closest('[data-ps]');
  const p = card && presets.find(x => x.id === card.dataset.ps);
  if (!p) return;
  try {
    if (e.target.closest('[data-ps-use]')) return openStyle(p);
    if (e.target.closest('[data-ps-over]')) {
      if (!confirm(`Timpa preset "${p.name}" dengan style clip ini?`)) return;
      await flushGfx();
      presets = (await api('preset_save', { id: p.id, edit: presetSnapshot() })).presets;
      toast(`Preset "${p.name}" diperbarui`);
    } else if (e.target.closest('[data-ps-ren]')) {
      const name = prompt('Nama preset', p.name);
      if (!name || !name.trim() || name.trim() === p.name) return;
      presets = (await api('preset_rename', { id: p.id, name: name.trim() })).presets;
    } else if (e.target.closest('[data-ps-del]')) {
      if (!confirm(`Hapus preset "${p.name}"?`)) return;
      presets = (await api('preset_delete', { id: p.id })).presets;
    } else return;
    renderPresets();
  } catch (err) { toast(err.message); }
});
loadPresets();
$('sty-cancel').onclick = closeStyle;
$('sty').addEventListener('click', e => { if (e.target === $('sty')) closeStyle(); });
document.addEventListener('keydown', e => { if (e.key === 'Escape' && !$('sty').hidden) closeStyle(); });
$('sty-parts').addEventListener('change', e => {
  if (e.target.name === 'sty-gfxtext') { styGfxText = e.target.value; try { localStorage.setItem('ed-sty-gfxtext', styGfxText); } catch {} return; }
  const k = e.target.dataset.part;
  if (!k) return;
  e.target.checked ? styParts.add(k) : styParts.delete(k);
  try { localStorage.setItem('ed-sty-parts', JSON.stringify([...styParts])); } catch {}
  if (k === 'gfx') renderStyParts();
  renderStyClips();
});
$('sty-clips').addEventListener('change', e => {
  const iid = e.target.dataset.sc;
  if (iid && styState[iid]) { styState[iid].pick = e.target.checked; renderStyClips(); }
});
$('sty-all').onclick = () => {
  const ready = Object.values(styState).filter(s => s.ready);
  const all = ready.length && ready.every(s => s.pick);
  ready.forEach(s => { s.pick = !all; });
  renderStyClips();
};
$('sty-go').onclick = applyStyle;

$('btn-save').onclick = async () => {
  $('btn-save').disabled = true;
  try {
    await flushGfx();
    const d = await api('edit_save', { id: PID, iid: IID, edit: payload() });
    E.trim = d.edit.trim;
    E._migratedHook = false;
    setDirty(false);
    markEdited();
    toast('Perubahan disimpan');
  } catch (err) { toast(err.message); $('btn-save').disabled = false; }
};

// Tombol Render: pilih clip ini / semua clip, dan (kalau relevan) dengan / tanpa musik
const hasMusic = () => !!E?.music.length && !musicMuted();
const rm = { scope: 'one', music: 'with' };
try { rm.music = localStorage.getItem('ed-render-music') || 'with'; } catch {}
function paintRender() {
  const n = project?.items.length || 1;
  const nPend = (project?.items || []).filter(it => !it.uploaded).length;
  if (n < 2) rm.scope = 'one';
  if (rm.scope === 'pending' && !nPend) rm.scope = 'one';
  const pb = $('rmod').querySelector('[data-scope="pending"]');
  pb.disabled = n < 2 || !nPend;
  $('rm-pend-sub').textContent = !nPend ? 'Semua clip sudah diupload'
    : `${nPend} clip${n - nPend ? ` · ${n - nPend} yang sudah diupload dilewati` : ''}`;
  $('rmod').querySelectorAll('[data-scope]').forEach(b => b.classList.toggle('on', b.dataset.scope === rm.scope));
  $('rmod').querySelector('[data-scope="all"]').disabled = n < 2;
  $('rm-one-sub').textContent = meta?.item?.title || 'Clip yang sedang diedit';
  $('rm-all-sub').textContent = n < 2 ? 'Proyek ini hanya punya 1 clip' : `${n} clip, masing-masing dengan editannya sendiri`;
  // Pilihan musik: selalu untuk "semua clip"; untuk clip ini hanya kalau clip ini punya musik
  const showMusic = rm.scope !== 'one' || hasMusic();
  $('rm-music').hidden = !showMusic;
  $('rmod').querySelectorAll('[data-mu]').forEach(b => b.classList.toggle('on', b.dataset.mu === rm.music));
  const names = [...new Set((E?.music || []).map(m => m.name || 'Musik'))];
  $('rm-music-note').textContent = rm.music === 'both' ? 'Hasilnya 2 file per clip: dengan musik & tanpa musik. Videonya identik, hanya audionya yang beda.'
    : rm.music === 'without' ? 'Hanya suara asli. Musik tetap tersimpan di editor.'
    : rm.scope !== 'one' ? 'Clip yang punya musik dirender dengan musiknya.' : `Suara asli + ${names.length > 1 ? names.length + ' lagu' : names[0] || 'musik'}`;
  $('rm-go').innerHTML = `${ic('film')} ${rm.scope === 'all' ? `Render ${n} clip` : rm.scope === 'pending' ? `Render ${nPend} clip` : 'Render clip ini'}`;
}
$('btn-render').onclick = () => {
  if (!E) return;
  rm.scope = 'one';
  paintRender();
  $('rmod').hidden = false;
  $('rm-go').focus();
};
$('rmod').addEventListener('click', e => {
  const sc = e.target.closest('[data-scope]'), mu = e.target.closest('[data-mu]');
  if (sc && !sc.disabled) { rm.scope = sc.dataset.scope; return paintRender(); }
  if (mu) { rm.music = mu.dataset.mu; try { localStorage.setItem('ed-render-music', rm.music); } catch {} return paintRender(); }
  if (e.target.closest('#rm-go')) {
    $('rmod').hidden = true;
    const mode = $('rm-music').hidden ? 'with' : rm.music;
    return rm.scope === 'one' ? renderClip(mode) : renderAll(mode, rm.scope === 'pending');
  }
  if (e.target === $('rmod') || e.target.closest('#rm-cancel')) $('rmod').hidden = true;
});
document.addEventListener('keydown', e => { if (e.key === 'Escape' && !$('rmod').hidden) $('rmod').hidden = true; });

// mode musik: 'with' | 'without' | 'both' (2 file per clip)
async function renderAll(mode, onlyPending = false) {
  $('btn-render').disabled = true;
  $('btn-render').innerHTML = '<span class="spin"></span> Mengirim…';
  try {
    await flushGfx();
    const d = await api('edit_render_all', { id: PID, iid: IID, edit: payload(), no_music: mode === 'without', both_music: mode === 'both', only_pending: onlyPending });
    E._migratedHook = false;
    setDirty(false);
    toast(`${d.queued.length} clip masuk antrean render${mode === 'without' ? ' (tanpa musik)' : mode === 'both' ? ' (dengan & tanpa musik)' : ''}`
      + (d.uploaded_skipped ? ` · ${d.uploaded_skipped} yang sudah diupload dilewati` : '')
      + (d.skipped.length ? ` · ${d.skipped.length} dilewati karena editornya belum siap` : ''));
    loadProject();
  } catch (err) {
    toast(err.message);
  }
  $('btn-render').innerHTML = `${ic('film')} Render clip`;
  $('btn-render').disabled = !E;
}

async function renderClip(mode) {
  $('btn-render').disabled = true;
  $('btn-render').innerHTML = '<span class="spin"></span> Mengirim…';
  try {
    await flushGfx();
    await api('edit_render', { id: PID, iid: IID, edit: payload(), no_music: mode === 'without', both_music: mode === 'both' });
    E._migratedHook = false;
    setDirty(false);
    markEdited();
    toast(`Render${mode === 'without' ? ' tanpa musik' : mode === 'both' && hasMusic() ? ' dengan & tanpa musik' : hasMusic() ? ' dengan musik' : ''} dimulai · progresnya terlihat di daftar clip.`);
    loadProject();
  } catch (err) {
    toast(err.message);
  }
  $('btn-render').innerHTML = `${ic('film')} Render clip`;
  $('btn-render').disabled = !E;
}

/* ---------- Daftar clip proyek (pindah clip tanpa keluar editor) ---------- */

let project = null, pjobs = {}, pedited = {}, pscores = {}, projTimer = null;
let switching = null; // clip tujuan yang sedang dimuat (sudah disorot di daftar)

async function loadProject() {
  clearTimeout(projTimer);
  try {
    const d = await api('project', null, '&id=' + PID);
    project = d.project;
    pjobs = d.jobs;
    pedited = d.edited || {};
    pscores = d.scores || {};
    renderStrip();
  } catch { /* coba lagi di putaran berikutnya */ }
  const busyRender = project?.items.some(it => ACTIVE.includes(pjobs[it.job_id]?.status));
  projTimer = setTimeout(loadProject, busyRender ? 2500 : 15000);
}
// Clip ini baru disimpan: centang "sudah diedit" langsung menyala tanpa menunggu polling
function markEdited(iid = IID) {
  if (pedited[iid]) return;
  pedited[iid] = true;
  renderStrip();
}

// Status render singkat untuk kartu thumbnail: [kelas, teks, keterangan tooltip]
function clipStatus(it) {
  const j = pjobs[it.job_id];
  if (!j) return ['err', '⚠', 'Data render hilang'];
  if (j.status === 'done') return ['ok', '✓', 'Sudah dirender'];
  if (j.status === 'queued' || j.stage === 'Menunggu giliran render…') return ['run', '⏳', 'Menunggu giliran render'];
  if (ACTIVE.includes(j.status)) return ['run', Math.floor(j.progress || 0) + '%', 'Sedang dirender'];
  return ['err', '⚠', j.status === 'cancelled' ? 'Render dibatalkan' : 'Render gagal'];
}

function renderStrip() {
  if (!project) return;
  const nEdited = project.items.filter(it => pedited[it.iid]).length;
  $('reels-cnt').textContent = `${project.items.length} clip`;
  $('reels-done').textContent = `${nEdited} dari ${project.items.length}`;
  $('reels-bar').style.width = (project.items.length ? nEdited / project.items.length * 100 : 0) + '%';
  // Prioritas: clip yang belum diupload di depan; yang sudah diupload pindah ke belakang (nomor clip tetap)
  const rows = project.items.map((it, i) => ({ it, i }));
  const pend = rows.filter(r => !r.it.uploaded), done = rows.filter(r => r.it.uploaded);
  const row = ({ it, i }) => {
    const on = it.iid === (switching || IID), ed = !!pedited[it.iid], vs = pscores[it.iid];
    const [cls, txt, info] = clipStatus(it);
    const thumb = `api.php?action=frame&id=${project.analysis_id}&t=${Math.round((it.start + it.end) / 2 * 10) / 10}`;
    const tip = `#${i + 1} ${it.title}\n${fmtDur(it.end - it.start)}${vs != null ? ` · skor viral ${vs}` : ''} · ${ed ? 'sudah diedit' : 'belum diedit'} · ${info}${on ? ' · sedang dibuka' : ''}`;
    return `<button class="rc${on ? ' on' : ''}${it.iid === switching ? ' loading' : ''}" data-clip="${it.iid}" title="${esc(tip)}">
      <img src="${thumb}" alt="" loading="lazy">
      ${vs != null ? `<span class="vs ${vs >= 80 ? 'hi' : vs >= 60 ? 'mid' : ''}" title="Skor potensi viral dari AI (0–100)">${ic('sparkles')}${vs}</span>` : ''}
      <span class="ck ${ed ? 'on' : ''}" title="${ed ? 'Sudah diedit' : 'Belum diedit'}">${ic('check')}</span>
      <span class="cap"><b>${esc(it.title)}</b><small><span class="no">#${i + 1}</span><span class="du">${fmtDur(it.end - it.start)}</span>${cls !== 'ok' ? `<span class="st ${cls}" title="${info}">${txt}</span>` : ''}${it.uploaded ? `<span class="st up" title="Sudah diupload ${esc(it.uploaded)}">${ic('check')} Upload</span>` : ''}</small></span></button>`;
  };
  setHTML($('reels-row'), pend.map(row).join('')
    + (done.length ? `<div class="rc-sep"><span>Sudah diupload</span><b>${done.length}</b></div>` + done.map(row).join('') : ''));
}

$('reels-row').addEventListener('click', e => {
  const c = e.target.closest('[data-clip]');
  if (c) switchClip(c.dataset.clip);
});
// Arahkan kursor ke clip = video-nya mulai dimuat di belakang, jadi saat diklik langsung tampil
$('reels-row').addEventListener('pointerover', e => {
  const c = e.target.closest('[data-clip]');
  if (c && c.dataset.clip !== IID) prefetchClip(c.dataset.clip);
});

/* Video proxy per clip disimpan di memori (maks. 6) supaya pindah bolak-balik antar clip instan. */
const vcache = new Map(); // iid -> { url, v, ready }
function clipVideo(iid, url, time) {
  let c = vcache.get(iid);
  if (!c || c.url !== url) {
    const v = document.createElement('video');
    v.preload = 'auto';
    v.playsInline = true;
    v.src = url;
    c = { url, v, ready: null };
    vcache.set(iid, c);
    while (vcache.size > 6) {
      const [k, old] = vcache.entries().next().value;
      if (old.v === vid) break;
      old.v.removeAttribute('src'); old.v.load();
      vcache.delete(k);
    }
  }
  c.ready = videoAt(c.v, time);
  return c.ready;
}

// Selesai saat frame pada waktu `time` sudah bisa digambar (maks. 8 dtk, supaya tidak menggantung)
function videoAt(v, time) {
  return new Promise(res => {
    const done = () => res(v);
    setTimeout(done, 8000);
    const go = () => {
      if (v.readyState >= 2 && Math.abs(v.currentTime - time) < 0.03) return done();
      v.addEventListener('seeked', done, { once: true });
      v.currentTime = time;
    };
    if (v.readyState >= 1) go(); else v.addEventListener('loadedmetadata', go, { once: true });
    v.addEventListener('error', done, { once: true });
  });
}

const peeked = new Map(); // iid -> waktu prefetch terakhir
async function prefetchClip(iid) {
  if (Date.now() - (peeked.get(iid) || 0) < 20000) return;
  peeked.set(iid, Date.now());
  try {
    const d = await api('edit_get', null, `&id=${PID}&iid=${iid}&peek=1`);
    if (d.edit && d.proxy && iid !== IID) clipVideo(iid, d.proxy, d.edit.trim[0] - d.edit.pad[0]);
  } catch { /* abaikan, akan dimuat saat diklik */ }
}

// Pindah ke clip lain tanpa layar hitam: tampilan clip sekarang tetap ada sampai frame clip baru siap, lalu ditukar sekaligus
async function switchClip(iid) {
  if (iid === IID || switching === iid) return;
  switching = iid;
  renderStrip();
  document.querySelector(`[data-clip="${iid}"]`)?.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'nearest' });
  try {
    if (dirty && E) {
      try {
        await flushGfx();
        await api('edit_save', { id: PID, iid: IID, edit: payload() });
        markEdited();
      } catch (err) {
        if (!confirm(`Perubahan clip ini gagal disimpan (${err.message}). Tetap pindah clip?`)) return;
      }
      setDirty(false);
    }
    const seq = ++loadSeq;
    let d;
    try { d = await api('edit_get', null, `&id=${PID}&iid=${iid}`); }
    catch (err) { toast(err.message); return; }
    if (seq !== loadSeq) return;
    // Clip belum siap: tampilkan layar persiapan seperti biasa
    if (!d.edit || d.preparing || d.error || !$('cv')) return openClip(iid);
    const v = await clipVideo(iid, d.proxy, d.edit.trim[0] - d.edit.pad[0]);
    if (seq !== loadSeq) return;
    commitClip(iid, d, v);
  } finally {
    if (switching === iid) switching = null;
    renderStrip();
  }
}

function commitClip(iid, d, v) {
  pause();
  if (!vcache.has(IID) && vid.src) vcache.set(IID, { url: vid.getAttribute('src'), v: vid, ready: null });
  IID = iid;
  history.replaceState(null, '', `editor.php?project=${PID}&item=${iid}`);
  vid.onseeked = null;
  vid = v;
  vfT = null;
  watchFrames(vid);
  vid.onseeked = () => draw();
  sel = { type: 'clip' };
  adoptEdit(d);
  $('vload')?.remove();
  renderProps();
  renderTimeline();
  updateTime();
  draw();
  setDirty(!!E._migratedHook);
  showPending(d);
  renderStrip();
  loadProject();
  upgradeProxy(iid);
}

function openClip(iid, force) {
  if (iid === IID && !force) return;
  pause();
  E = null;
  meta = null;
  sel = { type: 'clip' };
  IID = iid;
  history.replaceState(null, '', `editor.php?project=${PID}&item=${iid}`);
  // Video lama dibiarkan (mungkin ada di cache); clip ini memakai elemen video baru
  vid.onseeked = vid.onloadeddata = null;
  vid = document.createElement('video');
  vid.preload = 'auto';
  vid.playsInline = true;
  vfT = null;
  watchFrames(vid);
  setDirty(false);
  $('t-status').textContent = 'Memuat…';
  $('main').innerHTML = '<div class="card prep"><span class="spin"></span></div>';
  renderStrip();
  load();
}

window.addEventListener('beforeunload', e => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });

// Kolom ketik (teks, angka, dropdown, edit langsung) memakai tombolnya sendiri; slider / centang / warna tidak menghalangi shortcut
const isTyping = el => !!el.closest('textarea, select, [contenteditable]')
  || (el.tagName === 'INPUT' && !['range', 'checkbox', 'radio', 'color', 'button', 'submit', 'file'].includes(el.type));
document.addEventListener('keydown', e => {
  if (!E || isTyping(e.target)) return;
  if (e.code === 'Space') { e.preventDefault(); togglePlay(); }
  // F atau ` (backtick): preview layar penuh, tekan lagi untuk keluar
  if (!e.metaKey && !e.ctrlKey && !e.altKey && (e.key.toLowerCase() === 'f' || e.code === 'Backquote')) { e.preventDefault(); togglePvFullscreen(); }
  if (e.key === 'ArrowLeft') { pause(); seekV(curV() - (e.shiftKey ? 1 : 0.1)); }
  if (e.key === 'ArrowRight') { pause(); seekV(curV() + (e.shiftKey ? 1 : 0.1)); }
  if ((e.key === 'Delete' || e.key === 'Backspace') && mixSel && E.frame.mix) { e.preventDefault(); return setMix(null); }
  if ((e.key === 'Delete' || e.key === 'Backspace') && ['line', 'hook', 'media', 'text', 'video', 'effect', 'footage', 'transition', 'music', 'opening', 'sfx'].includes(sel.type)) { e.preventDefault(); deleteSelectedText(); }
  if (e.key === 'Enter' && !e.target.closest('button, a') && ['line', 'hook', 'text'].includes(sel.type)) { e.preventDefault(); startInlineEdit(); }
  if (e.key === 'Enter' && !e.target.closest('button, a') && sel.type === 'media' && E.media[sel.i]?.gfx) { e.preventDefault(); startGfxEdit(sel.i); }
  if (e.key === 'Escape' && ['line', 'hook', 'text', 'media', 'video', 'effect', 'footage', 'transition', 'music', 'opening', 'sfx'].includes(sel.type)) select({ type: 'clip' });
  if ((e.metaKey || e.ctrlKey) && !e.shiftKey && e.key.toLowerCase() === 'b') { e.preventDefault(); setRangeEdge('a'); }
  if ((e.metaKey || e.ctrlKey) && !e.shiftKey && e.key.toLowerCase() === 'e') { e.preventDefault(); setRangeEdge('b'); }
  if (!e.metaKey && !e.ctrlKey && !e.altKey && (e.key === 'i' || e.key === 'o')) setRangeEdge(e.key === 'i' ? 'a' : 'b');
  if ((e.metaKey || e.ctrlKey) && e.shiftKey && e.key.toLowerCase() === 'd') { e.preventDefault(); cutAtPlayhead(); }
  if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'z') { e.preventDefault(); e.shiftKey ? redo() : undo(); }
  if (e.ctrlKey && !e.metaKey && e.key.toLowerCase() === 'y') { e.preventDefault(); redo(); }
});

watchFrames(vid);
load();
loadProject();
</script>
</body>
</html>
