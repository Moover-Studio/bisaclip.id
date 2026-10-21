// AI upscale (super-resolution 4x bawaan macOS 26, VideoToolbox VTFrameProcessor) untuk render clip BisaClip.
// Pemakaian: superres <inW> <inH> <outW> <outH>
//   stdin : frame mentah BGRA inW x inH berurutan (dari ffmpeg)
//   stdout: frame mentah BGRA outW x outH (hasil 4x lalu diperkecil ke ukuran tujuan)
// Kode keluar 3 = tidak didukung di Mac ini (pemanggil memakai jalur biasa).
// Build: xcrun swiftc -O tools/superres.swift -o tools/superres
import CoreMedia
import CoreVideo
import Foundation
import VideoToolbox

// Mode cepat: low-latency super-resolution 2x (maks. input 960 px), lalu diskalakan ke ukuran tujuan
func runLowLatency() -> Never {
    let scales = VTLowLatencySuperResolutionScalerConfiguration.supportedScaleFactors(frameWidth: inW, frameHeight: inH)
    guard VTLowLatencySuperResolutionScalerConfiguration.isSupported, let sc = scales.max() else { fail("low-latency tidak didukung untuk \(inW)x\(inH)", 3) }
    let cfg = VTLowLatencySuperResolutionScalerConfiguration(frameWidth: inW, frameHeight: inH, scaleFactor: sc)
    let proc = VTFrameProcessor()
    do { try proc.startSession(configuration: cfg) } catch { fail("sesi gagal: \(error.localizedDescription)", 3) }
    var xfer: VTPixelTransferSession?
    VTPixelTransferSessionCreate(allocator: nil, pixelTransferSessionOut: &xfer)
    guard let xfer else { fail("transfer session gagal") }
    let bgraIn = buffer(inW, inH, kCVPixelFormatType_32BGRA), bgraOut = buffer(outW, outH, kCVPixelFormatType_32BGRA)
    let src = buffer(attrs: cfg.sourcePixelBufferAttributes), dst = buffer(attrs: cfg.destinationPixelBufferAttributes)
    var frame = Data(count: inW * 4 * inH)
    var n: Int64 = 0
    while readFull(into: &frame) {
        putFrame(frame, bgraIn, inW, inH)
        VTPixelTransferSessionTransferImage(xfer, from: bgraIn, to: src)
        let pts = CMTime(value: n, timescale: 30)
        let params = VTLowLatencySuperResolutionScalerParameters(sourceFrame: VTFrameProcessorFrame(buffer: src, presentationTimeStamp: pts)!,
                                                                 destinationFrame: VTFrameProcessorFrame(buffer: dst, presentationTimeStamp: pts)!)
        let sem = DispatchSemaphore(value: 0)
        var perr: Error?
        proc.process(parameters: params) { _, e in perr = e; sem.signal() }
        sem.wait()
        if let perr { fail("proses gagal di frame \(n): \(perr.localizedDescription)") }
        VTPixelTransferSessionTransferImage(xfer, from: dst, to: bgraOut)
        writeFrame(bgraOut, outW, outH)
        n += 1
    }
    proc.endSession()
    exit(0)
}

func putFrame(_ data: Data, _ pb: CVPixelBuffer, _ w: Int, _ h: Int) {
    CVPixelBufferLockBaseAddress(pb, [])
    let base = CVPixelBufferGetBaseAddress(pb)!, bpr = CVPixelBufferGetBytesPerRow(pb)
    data.withUnsafeBytes { raw in for y in 0..<h { memcpy(base + y * bpr, raw.baseAddress! + y * w * 4, w * 4) } }
    CVPixelBufferUnlockBaseAddress(pb, [])
}

func writeFrame(_ pb: CVPixelBuffer, _ w: Int, _ h: Int) {
    CVPixelBufferLockBaseAddress(pb, .readOnly)
    let base = CVPixelBufferGetBaseAddress(pb)!, bpr = CVPixelBufferGetBytesPerRow(pb)
    var out = Data(count: w * 4 * h)
    out.withUnsafeMutableBytes { raw in for y in 0..<h { memcpy(raw.baseAddress! + y * w * 4, base + y * bpr, w * 4) } }
    CVPixelBufferUnlockBaseAddress(pb, .readOnly)
    FileHandle.standardOutput.write(out)
}

func buffer(_ w: Int, _ h: Int, _ fmt: OSType) -> CVPixelBuffer {
    var pb: CVPixelBuffer?
    let attrs = [kCVPixelBufferIOSurfacePropertiesKey as String: [String: Any]()] as CFDictionary
    guard CVPixelBufferCreate(nil, w, h, fmt, attrs, &pb) == kCVReturnSuccess, let pb else { fail("buffer gagal dibuat") }
    return pb
}

// Buffer persis sesuai atribut yang diminta processor (format, ukuran, padding)
func buffer(attrs: [String: Any]) -> CVPixelBuffer {
    let w = attrs[kCVPixelBufferWidthKey as String] as! Int, h = attrs[kCVPixelBufferHeightKey as String] as! Int
    let f = attrs[kCVPixelBufferPixelFormatTypeKey as String]
    let fmt = (f as? NSNumber)?.uint32Value ?? (f as? [NSNumber])?.first?.uint32Value ?? kCVPixelFormatType_32BGRA
    var a = attrs
    a[kCVPixelBufferPixelFormatTypeKey as String] = fmt
    var pb: CVPixelBuffer?
    guard CVPixelBufferCreate(nil, w, h, fmt, a as CFDictionary, &pb) == kCVReturnSuccess, let pb else { fail("buffer gagal dibuat") }
    return pb
}

func readFull(into data: inout Data) -> Bool {
    var got = 0
    let total = data.count
    while got < total {
        let chunk = FileHandle.standardInput.readData(ofLength: total - got)
        if chunk.isEmpty { return false }
        data.replaceSubrange(got..<got + chunk.count, with: chunk)
        got += chunk.count
    }
    return true
}

func fail(_ msg: String, _ code: Int32 = 1) -> Never {
    FileHandle.standardError.write((msg + "\n").data(using: .utf8)!)
    exit(code)
}

let argv = Array(CommandLine.arguments.dropFirst())
let args = argv.prefix(4).compactMap { Int($0) }
guard args.count == 4 else { fail("pemakaian: superres inW inH outW outH [4x|ll]") }
let (inW, inH, outW, outH) = (args[0], args[1], args[2], args[3])
let lowLatency = argv.count > 4 && argv[4] == "ll"
if lowLatency { runLowLatency() }

guard VTSuperResolutionScalerConfiguration.isSupported,
      let cfg = VTSuperResolutionScalerConfiguration(frameWidth: inW, frameHeight: inH, scaleFactor: 4, inputType: .video,
                                                     usePrecomputedFlow: false, qualityPrioritization: .normal, revision: .revision1)
else { fail("super-resolution tidak didukung untuk \(inW)x\(inH)", 3) }

// Model ML disediakan sistem; unduh dulu kalau belum ada
if cfg.configurationModelStatus != .ready {
    let sem = DispatchSemaphore(value: 0)
    var err: Error?
    cfg.downloadConfigurationModel { e in err = e; sem.signal() }
    sem.wait()
    if let err { fail("model gagal diunduh: \(err.localizedDescription)", 3) }
}

let proc = VTFrameProcessor()
do { try proc.startSession(configuration: cfg) } catch { fail("sesi gagal: \(error.localizedDescription)", 3) }

let half = kCVPixelFormatType_64RGBAHalf
var xfer: VTPixelTransferSession?
VTPixelTransferSessionCreate(allocator: nil, pixelTransferSessionOut: &xfer)
guard let xfer else { fail("transfer session gagal") }

let bgraIn = buffer(inW, inH, kCVPixelFormatType_32BGRA)
let bgraOut = buffer(outW, outH, kCVPixelFormatType_32BGRA)
// Dua set buffer bergantian: frame sebelumnya dipakai model untuk konsistensi antar-frame
let src = [buffer(inW, inH, half), buffer(inW, inH, half)]
let dst = [buffer(inW * 4, inH * 4, half), buffer(inW * 4, inH * 4, half)]

let inRow = inW * 4, outRow = outW * 4
var frameBytes = Data(count: inRow * inH)
let stdin = FileHandle.standardInput, stdout = FileHandle.standardOutput
var n: Int64 = 0

while readFull(into: &frameBytes) {
    // stdin → buffer BGRA (per baris, karena bytesPerRow bisa lebih lebar)
    CVPixelBufferLockBaseAddress(bgraIn, [])
    let base = CVPixelBufferGetBaseAddress(bgraIn)!, bpr = CVPixelBufferGetBytesPerRow(bgraIn)
    frameBytes.withUnsafeBytes { raw in
        for y in 0..<inH { memcpy(base + y * bpr, raw.baseAddress! + y * inRow, inRow) }
    }
    CVPixelBufferUnlockBaseAddress(bgraIn, [])

    let k = Int(n % 2), p = 1 - k
    VTPixelTransferSessionTransferImage(xfer, from: bgraIn, to: src[k])
    let pts = CMTime(value: n, timescale: 30), ppts = CMTime(value: n - 1, timescale: 30)
    guard let sf = VTFrameProcessorFrame(buffer: src[k], presentationTimeStamp: pts),
          let df = VTFrameProcessorFrame(buffer: dst[k], presentationTimeStamp: pts) else { fail("frame tidak valid") }
    let pf = n > 0 ? VTFrameProcessorFrame(buffer: src[p], presentationTimeStamp: ppts) : nil
    let pof = n > 0 ? VTFrameProcessorFrame(buffer: dst[p], presentationTimeStamp: ppts) : nil
    guard let params = VTSuperResolutionScalerParameters(sourceFrame: sf, previousFrame: pf, previousOutputFrame: pof, opticalFlow: nil,
                                                         submissionMode: .sequential, destinationFrame: df) else { fail("parameter tidak valid") }
    let sem = DispatchSemaphore(value: 0)
    var perr: Error?
    proc.process(parameters: params) { _, e in perr = e; sem.signal() }
    sem.wait()
    if let perr { fail("proses gagal di frame \(n): \(perr.localizedDescription)") }

    // Hasil 4x → ukuran tujuan (diperkecil berkualitas tinggi) → stdout
    VTPixelTransferSessionTransferImage(xfer, from: dst[k], to: bgraOut)
    CVPixelBufferLockBaseAddress(bgraOut, .readOnly)
    let ob = CVPixelBufferGetBaseAddress(bgraOut)!, obpr = CVPixelBufferGetBytesPerRow(bgraOut)
    var out = Data(count: outRow * outH)
    out.withUnsafeMutableBytes { raw in
        for y in 0..<outH { memcpy(raw.baseAddress! + y * outRow, ob + y * obpr, outRow) }
    }
    CVPixelBufferUnlockBaseAddress(bgraOut, .readOnly)
    stdout.write(out)
    n += 1
}
proc.endSession()
