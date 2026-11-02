# Draft v0.2: narration (am_michael) with burned-in subtitles, names Amber and Jade, no product names in the video.
import subprocess, os, json, numpy as np, soundfile as sf
V = ['-c:v', 'libx264', '-preset', 'veryfast', '-crf', '20', '-pix_fmt', 'yuv420p', '-r', '30']
A = ['-c:a', 'aac', '-b:a', '160k', '-ar', '48000', '-ac', '2']
SR = 24000
N = json.load(open('../narr/lines.json'))
for sc in N.values():
    for l in sc: l['file'] = '../' + l['file']
def run(args, out):
    tmp = out.replace('.mp4', '.part.mp4')
    r = subprocess.run(['ffmpeg', '-v', 'error', '-y'] + args + [tmp])
    if r.returncode: raise SystemExit('failed ' + out)
    os.rename(tmp, out)
def place(sc, t0, gap=0.35):
    """narration lines of scene part `sc` from t0, one after another; returns [(wav, start, end, subtitle png)]"""
    out, t = [], t0
    for i, l in enumerate(N[sc]):
        out.append((l['file'], t, t + l['secs'], f'sub_{sc}_{i}.png')); t += l['secs'] + gap
    return out
def scene(base, dur, narr, ovs, out, base_is_card=False, base_audio=None, start=0):
    # audio: base audio (if any) + narration, mixed at 24 kHz mono
    track = np.zeros(int(dur * SR) + SR, dtype='float32')
    if base_audio:
        subprocess.run(['ffmpeg', '-v', 'error', '-y', '-ss', f'{start}', '-t', f'{dur}', '-i', base_audio, '-ac', '1', '-ar', str(SR), 'base_tmp.wav'], check=True)
        b, _ = sf.read('base_tmp.wav', dtype='float32'); track[:len(b)] += b
    for wav, a, b_, png in narr:
        x, _ = sf.read(wav, dtype='float32'); i = int(a * SR); track[i:i + len(x)] += x[:len(track) - i]
    pk = np.abs(track).max()
    if pk > 0.89: track *= 0.89 / pk
    sf.write('mix_tmp.wav', track[:int(dur * SR)], SR)
    allov = ovs + [(png, a, b_ + 0.25, 0.15) for wav, a, b_, png in narr]
    f, inputs = [], []
    src = ['-framerate', '30', '-i', base] if base_is_card else ['-ss', f'{start}', '-t', f'{dur}', '-i', base]
    first = '[0:v]format=yuv420p,loop=-1:1,trim=duration=%s,setpts=PTS-STARTPTS[b]' % dur if base_is_card else '[0:v]setpts=PTS-STARTPTS[b]'
    f.append(first); last = 'b'
    for i, (png, a, b_, fd) in enumerate(allov):
        inputs += ['-loop', '1', '-t', f'{dur}', '-i', png]
        f.append(f'[{i + 1}:v]format=rgba,fade=in:st={a:.3f}:d={fd}:alpha=1,fade=out:st={max(a, b_ - fd):.3f}:d={fd}:alpha=1[o{i}]')
        f.append(f'[{last}][o{i}]overlay=0:0[v{i}]'); last = f'v{i}'
    f.append(f'[{last}]fade=in:st=0:d=0.3,fade=out:st={dur - 0.3}:d=0.3,format=yuv420p[vout]')
    n_in = len(allov) + 1
    run(src + inputs + ['-i', 'mix_tmp.wav', '-filter_complex', ';'.join(f), '-map', '[vout]', '-map', f'{n_in}:a', '-t', f'{dur}'] + V + A, out)
    return dur
total = 0
# 1 night house: the hook, then the one big caption
n1 = place('s1', 0.6); d1 = 15.0
total += scene('idle_v2.mp4', d1, n1, [('ov2_top.png', 7.0, d1, 0.3)], 'v2_s1.mp4')
# 2 what the robot is told
n2 = place('s2', 0.6); d2 = round(n2[-1][2] + 1.2, 2)
total += scene('card2_premise.png', d2, n2, [], 'v2_s2.mp4', base_is_card=True)
# 3 the replay, narration only in the gaps between the robots' lines
sp = json.load(open('replay_v2_silent.speech.json'))
starts = [((e['t'] - sp['t0']) / 1000, (e['t'] - sp['t0']) / 1000 + e['d']) for e in sp['log']]
d3 = round(sp['frames'] / 30, 3)
man_end = starts[0][1]
gap_i = max(range(1, len(starts)), key=lambda i: starts[i][0] - starts[i - 1][1])   # the pause between turn 1 and turn 2
n3 = place('s3a', man_end + 0.3) + place('s3b', starts[gap_i - 1][1] + 0.3) + place('s3c', starts[-1][1] + 0.3)
for wav, a, b_, png in n3:
    clash = [s for s in starts if a < s[1] and b_ > s[0]]
    print('s3 narration', round(a, 2), round(b_, 2), 'overlaps a line' if clash else 'clear', '(end of scene %.1f)' % d3 if b_ > d3 else '')
total += scene('replay_v2.mp4', d3, n3, [], 'v2_s3.mp4', base_audio='replay_v2.mp4')
# 4 numbers
n4 = place('s4', 0.6); d4 = round(n4[-1][2] + 1.2, 2)
total += scene('card2_numbers.png', d4, n4, [], 'v2_s4.mp4', base_is_card=True)
# 5 Kiro: header + seven placeholder cuts as one base clip, narration across it
cuts = [('card2_kiro.png', 3)] + [(f'card2_kiro{n}.png', s) for n, s in [('1', 5), ('2', 6), ('3', 4), ('4', 6), ('5', 7), ('6', 5), ('7', 5)]]
parts = []
for i, (png, s) in enumerate(cuts):
    run(['-framerate', '30', '-i', png, '-vf', f'format=yuv420p,loop=-1:1,trim=duration={s}', '-t', f'{s}'] + V, f'k{i}.mp4'); parts.append(f'k{i}.mp4')
open('klist.txt', 'w').write(''.join(f"file '{p}'\n" for p in parts))
run(['-f', 'concat', '-safe', '0', '-i', 'klist.txt', '-c', 'copy'], 'kiro_base.mp4')
d5 = float(sum(s for _, s in cuts)); n5 = place('s5', 0.6)
total += scene('kiro_base.mp4', d5, n5, [], 'v2_s5.mp4')
# 6 close: narration, then the credits
n6 = place('s6', 0.6); d6 = round(n6[-1][2] + 4.0, 2)
total += scene('idle_v2.mp4', d6, n6, [('ov2_credits.png', n6[-1][2] + 0.2, d6, 0.3)], 'v2_s6.mp4', start=1.0)
open('v2_list.txt', 'w').write(''.join(f"file 'v2_s{i}.mp4'\n" for i in range(1, 7)))
run(['-f', 'concat', '-safe', '0', '-i', 'v2_list.txt', '-c', 'copy', '-movflags', '+faststart'], '提出動画_下書き_v0.2.mp4')
print('scenes', d1, d2, d3, d4, d5, d6, 'total', round(total, 2))
