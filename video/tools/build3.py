# Draft v0.3 (after a review): names introduced over the house, a shorter premise, the replay filmed with a camera that moves in on
# the spoken lines, the numbers one row at a time, Kiro in three captioned blocks plus the next step, and the address at the end.
import subprocess, os, json, numpy as np, soundfile as sf
V = ['-c:v', 'libx264', '-preset', 'veryfast', '-crf', '20', '-pix_fmt', 'yuv420p', '-r', '30']
A = ['-c:a', 'aac', '-b:a', '160k', '-ar', '48000', '-ac', '2']
SR = 24000
N = json.load(open('../narr3/lines.json'))
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
        out.append((l['file'], t, t + l['secs'], f'sub3_{sc}_{i}.png')); t += l['secs'] + gap
    return out
ONLY = [x for x in os.environ.get('ONLY', '').split(',') if x]   # e.g. ONLY=v3_s5.mp4 rebuilds one scene and the join
def scene(base, dur, narr, ovs, out, base_is_card=False, base_audio=None, start=0):
    if ONLY and out not in ONLY: return dur
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
def place_at(sc, starts):
    return [(l['file'], a, a + l['secs'], f'sub3_{sc}_{i}.png') for i, (l, a) in enumerate(zip(N[sc], starts))]
total = 0
# 1 the problem, the setup, the names, then the question with the one big caption
n1 = place('s1', 0.6); d1 = round(n1[-1][2] + 2.0, 2)
total += scene('idle_v3.mp4', d1, n1, [('ov3_names.png', n1[2][1] - 0.1, n1[3][1] + 0.6, 0.3), ('ov3_top.png', n1[3][1], d1, 0.3)], 'v3_s1.mp4')
# 2 what the robot is told
n2 = place('s2', 0.6); d2 = round(n2[-1][2] + 1.0, 2)
total += scene('card3_premise.png', d2, n2, [], 'v3_s2.mp4', base_is_card=True)
# 3 the replay (camera moves are in the film), narration only in the gaps between the robots' lines
sp = json.load(open('replay_v3_silent.speech.json'))
starts = [((e['t'] - sp['t0']) / 1000, (e['t'] - sp['t0']) / 1000 + e['d']) for e in sp['log']]
tEnd = sp['endAt'] / 30; d3 = round(tEnd + 5.0, 3)
gap_i = max(range(1, len(starts)), key=lambda i: starts[i][0] - starts[i - 1][1])
n3 = place('s3a', starts[0][1] + 0.3) + place('s3b', starts[gap_i - 1][1] + 0.3) + place('s3c', starts[-1][1] + 0.3)
for wav, a, b_, png in n3:
    clash = [x for x in starts if a < x[1] and b_ > x[0]]
    print('s3 narration', round(a, 2), round(b_, 2), 'overlaps a line' if clash else 'clear', '(past the end %.1f)' % d3 if b_ > d3 else '')
total += scene('replay_v3.mp4', d3, n3, [], 'v3_s3.mp4', base_audio='replay_v3.mp4')
# 4 numbers, one row with each sentence
n4 = place('s4', 0.6); d4 = round(n4[-1][2] + 1.5, 2)
total += scene('card3_numbers.png', d4, n4, [(f'ov3_row{i + 1}.png', n4[i][1] - 0.1, d4, 0.3) for i in range(3)], 'v3_s4.mp4', base_is_card=True)
# 5 Kiro: header, three blocks (placeholders until the real screens are filmed), then the next step over the replay's end card
L5 = [l['secs'] for l in N['s5']]
blocks = [('card3_kiro.png', round(0.4 + L5[0] + 0.5, 2)), ('card3_kiroA.png', round(0.3 + L5[1] + 0.9, 2)), ('card3_kiroB.png', round(0.3 + L5[2] + 0.9, 2)), ('card3_kiroC.png', round(0.3 + L5[3] + 0.9, 2)), (None, round(0.3 + L5[4] + 0.8, 2))]
parts, t, b0 = [], 0.0, []
for i, (png, s_) in enumerate(blocks):
    if png: run(['-framerate', '30', '-i', png, '-vf', f'format=yuv420p,loop=-1:1,trim=duration={s_}', '-t', f'{s_}'] + V, f'k3_{i}.mp4')
    else: run(['-ss', f'{tEnd + 4.8:.3f}', '-t', f'{s_}', '-i', 'replay_v3_silent.mp4', '-an'] + V, f'k3_{i}.mp4')
    parts.append(f'k3_{i}.mp4'); b0.append(t); t += s_
open('k3list.txt', 'w').write(''.join(f"file '{p}'\n" for p in parts))
run(['-f', 'concat', '-safe', '0', '-i', 'k3list.txt', '-c', 'copy'], 'kiro_base3.mp4')
d5 = round(t, 2); n5 = place_at('s5', [b0[0] + 0.4] + [b0[i] + 0.3 for i in range(1, 5)])
caps5 = [(f'ov3_kcap{k}.png', b0[i + 1] + 0.1, b0[i + 1] + blocks[i + 1][1], 0.25) for i, k in enumerate('ABC')]   # no caption on the end card: it would cover its title
total += scene('kiro_base3.mp4', d5, n5, caps5, 'v3_s5.mp4')
# 6 close: narration, then the name and the address
n6 = place('s6', 0.6); d6 = round(n6[-1][2] + 5.0, 2)
total += scene('idle_v3.mp4', d6, n6, [('ov3_credits.png', n6[-1][2] + 0.2, d6, 0.3)], 'v3_s6.mp4', start=1.0)
open('v3_list.txt', 'w').write(''.join(f"file 'v3_s{i}.mp4'\n" for i in range(1, 7)))
run(['-f', 'concat', '-safe', '0', '-i', 'v3_list.txt', '-c', 'copy', '-movflags', '+faststart'], 'submission_draft_v0.3.mp4')
print('scenes', d1, d2, d3, d4, d5, d6, 'total', round(total, 2), 'kiro blocks', [s_ for _, s_ in blocks])
# loudness: the joined file is about -27 LUFS; bring it to -16 LUFS (true peak -1.5) in two passes, video copied
import re
meas = subprocess.run(['ffmpeg', '-v', 'info', '-i', 'submission_draft_v0.3.mp4', '-af', 'loudnorm=I=-16:TP=-1.5:LRA=11:print_format=json', '-vn', '-f', 'null', '-'], capture_output=True, text=True).stderr
m = json.loads(re.search(r'\{[^{}]*"input_i"[^{}]*\}', meas).group(0))
af = f"loudnorm=I=-16:TP=-1.5:LRA=11:measured_I={m['input_i']}:measured_TP={m['input_tp']}:measured_LRA={m['input_lra']}:measured_thresh={m['input_thresh']}:offset={m['target_offset']}:linear=true"
run(['-i', 'submission_draft_v0.3.mp4', '-map', '0:v', '-map', '0:a', '-c:v', 'copy', '-af', af, '-ar', '48000', '-c:a', 'aac', '-b:a', '160k', '-movflags', '+faststart'], 'submission_draft_v0.3_loud.mp4')
os.replace('submission_draft_v0.3_loud.mp4', 'submission_draft_v0.3.mp4'); print('loudness', m['input_i'], '-> -16 LUFS')
