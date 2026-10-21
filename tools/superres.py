#!/usr/bin/env python3
"""AI upscale (Real-ESRGAN realesr-general-x4v3) untuk render clip BisaClip di Linux / Windows (WSL2).

Pengganti tools/superres (Swift, khusus macOS) dengan antarmuka yang sama:
  superres.py <inW> <inH> <outW> <outH> [mode]
  stdin : frame mentah BGRA inW x inH berurutan (dari ffmpeg)
  stdout: frame mentah BGRA outW x outH (hasil 4x lalu diperkecil ke ukuran tujuan)
Kode keluar 3 = tidak didukung di komputer ini (tidak ada GPU CUDA / PyTorch / model), pemanggil memakai jalur biasa.
Model: storage/models/realesr-general-x4v3.pth (dipasang oleh install/setup-wsl.sh).
"""
import os
import sys

MODEL = os.environ.get('SUPERRES_MODEL') or os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'storage', 'models', 'realesr-general-x4v3.pth')


def fail(msg, code=1):
    sys.stderr.write(f'superres: {msg}\n')
    sys.exit(code)


try:
    import torch
    import torch.nn as nn
    import torch.nn.functional as F
except Exception:
    fail('PyTorch tidak terpasang', 3)


class SRVGGNetCompact(nn.Module):
    """Arsitektur Real-ESRGAN compact (sama dengan basicsr/realesrgan SRVGGNetCompact)."""

    def __init__(self, num_feat=64, num_conv=32, upscale=4):
        super().__init__()
        self.upscale = upscale
        body = [nn.Conv2d(3, num_feat, 3, 1, 1), nn.PReLU(num_parameters=num_feat)]
        for _ in range(num_conv):
            body += [nn.Conv2d(num_feat, num_feat, 3, 1, 1), nn.PReLU(num_parameters=num_feat)]
        body.append(nn.Conv2d(num_feat, 3 * upscale * upscale, 3, 1, 1))
        self.body = nn.ModuleList(body)
        self.upsampler = nn.PixelShuffle(upscale)

    def forward(self, x):
        out = x
        for layer in self.body:
            out = layer(out)
        return self.upsampler(out) + F.interpolate(x, scale_factor=self.upscale, mode='nearest')


def main():
    if len(sys.argv) < 5:
        fail('pemakaian: superres.py inW inH outW outH')
    in_w, in_h, out_w, out_h = map(int, sys.argv[1:5])
    # Hanya GPU: di CPU terlalu lambat untuk video (pemanggil memakai jalur biasa)
    if torch.cuda.is_available():
        dev = torch.device('cuda')
    elif os.environ.get('SUPERRES_ALLOW_CPU') == '1':
        dev = torch.device('mps' if torch.backends.mps.is_available() else 'cpu')
    else:
        fail('GPU CUDA tidak tersedia', 3)
    if not os.path.isfile(MODEL):
        fail('model realesr-general-x4v3.pth tidak ada', 3)
    state = torch.load(MODEL, map_location='cpu', weights_only=True)
    state = state.get('params_ema', state.get('params', state))
    net = SRVGGNetCompact()
    net.load_state_dict(state, strict=True)
    half = dev.type == 'cuda'
    net = net.to(dev).eval()
    if half:
        net = net.half()
    torch.backends.cudnn.benchmark = True

    size = in_w * in_h * 4
    inp = sys.stdin.buffer
    out = sys.stdout.buffer
    with torch.inference_mode():
        while True:
            buf = inp.read(size)
            if not buf:
                break
            while len(buf) < size:
                more = inp.read(size - len(buf))
                if not more:
                    fail('frame terpotong')
                buf += more
            x = torch.frombuffer(bytearray(buf), dtype=torch.uint8).to(dev).view(in_h, in_w, 4)
            # BGRA -> RGB, 0..1, NCHW
            x = x[:, :, [2, 1, 0]].permute(2, 0, 1).unsqueeze(0)
            x = x.half() / 255 if half else x.float() / 255
            y = net(x).float().clamp_(0, 1)
            # 4x lalu diperkecil ke ukuran tujuan (antialias = sama seperti flags=area)
            if y.shape[-1] != out_w or y.shape[-2] != out_h:
                y = F.interpolate(y, size=(out_h, out_w), mode='bicubic', antialias=True, align_corners=False).clamp_(0, 1)
            y = (y[0] * 255).round_().to(torch.uint8)  # 3,H,W RGB
            a = torch.full((1, out_h, out_w), 255, dtype=torch.uint8, device=y.device)
            bgra = torch.cat([y[[2, 1, 0]], a], 0).permute(1, 2, 0).contiguous().cpu().numpy()
            out.write(bgra.tobytes())
    out.flush()


if __name__ == '__main__':
    try:
        main()
    except BrokenPipeError:
        sys.exit(1)
