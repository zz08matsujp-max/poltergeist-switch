# Assemble the draft: one encoded file per scene (same size, rate, encoder, audio), then concat with -c copy.
import subprocess, os
V = ['-c:v', 'libx264', '-preset', 'veryfast', '-crf', '20', '-pix_fmt', 'yuv420p', '-r', '30']
A = ['-c:a', 'aac', '-b:a', '160k', '-ar', '48000', '-ac', '2']
def run(args, out):
    tmp = out.replace('.mp4', '.part.mp4')
    r = subprocess.run(['ffmpeg', '-v', 'error', '-y'] + args + [tmp])
    if r.returncode: raise SystemExit('failed ' + out)
    os.rename(tmp, out)
def ov_chain(base, ovs, dur):
    # ovs: list of (png, start, end); each faded in/out over 0.3 s
    f, inputs, last = [], [], base
    for i, (png, a, b) in enumerate(ovs):
        inputs += ['-loop', '1', '-t', f'{dur}', '-i', png]
        f.append(f'[{i + 1}:v]format=rgba,fade=in:st={a}:d=0.3:alpha=1,fade=out:st={b - 0.3}:d=0.3:alpha=1[o{i}]')
        f.append(f'[{last}][o{i}]overlay=0:0[v{i}]'); last = f'v{i}'
    return inputs, f, last
def scene_footage(src, start, dur, ovs, out, keep_audio=False):
    inputs, f, last = ov_chain('0:v', ovs, dur)
    f.append(f'[{last}]fade=in:st=0:d=0.3,fade=out:st={dur - 0.3}:d=0.3,format=yuv420p[vout]')
    args = ['-ss', f'{start}', '-t', f'{dur}', '-i', src] + inputs
    if keep_audio:
        f.append(f'[0:a]afade=in:st=0:d=0.3,afade=out:st={dur - 0.3}:d=0.3[aout]'); amap = '[aout]'
    else:
        args += ['-f', 'lavfi', '-t', f'{dur}', '-i', 'anullsrc=r=48000:cl=stereo']; amap = f'{len(ovs) + 1}:a'
    run(args + ['-filter_complex', ';'.join(f), '-map', '[vout]', '-map', amap, '-t', f'{dur}'] + V + A, out)
def scene_card(png, dur, out):
    run(['-framerate', '30', '-i', png, '-f', 'lavfi', '-t', f'{dur}', '-i', 'anullsrc=r=48000:cl=stereo',
         '-vf', f'format=yuv420p,loop=-1:1,trim=duration={dur},fade=in:st=0:d=0.3,fade=out:st={dur - 0.3}:d=0.3', '-t', f'{dur}'] + V + A, out)
scene_footage('idle.mp4', 0, 12, [('ov_op1.png', 0.5, 6.0), ('ov_op2.png', 6.3, 11.8)], 's1.mp4')
scene_card('card_premise.png', 15, 's2.mp4')
scene_footage('replay.mp4', 0, 62.5, [('ov_r1.png', 17.3, 25.0), ('ov_r2.png', 35.2, 41.5), ('ov_r3.png', 49.6, 56.5)], 's3.mp4', keep_audio=True)
scene_card('card_numbers.png', 15, 's4.mp4')
scene_card('card_kiro.png', 3, 's5.mp4')
parts = ['s1', 's2', 's3', 's4', 's5']
for n, s in [('1', 6), ('2', 8), ('3', 5), ('4', 8), ('5', 10), ('6', 7), ('7', 6)]:
    scene_card(f'card_kiro{n}.png', s, f's5_{n}.mp4'); parts.append(f's5_{n}')
scene_footage('idle.mp4', 0.5, 15, [('ov_c1.png', 0.5, 7.0), ('ov_c2.png', 7.3, 15.0)], 's6.mp4'); parts.append('s6')
open('list.txt', 'w').write(''.join(f"file '{p}.mp4'\n" for p in parts))
run(['-f', 'concat', '-safe', '0', '-i', 'list.txt', '-c', 'copy', '-movflags', '+faststart'], '提出動画_下書き_v0.1.mp4')
print('ok', parts)
