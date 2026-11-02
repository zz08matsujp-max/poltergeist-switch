# Lay the Kokoro lines on the filmed frames at the moments the page started speaking them, then mux (video copied).
import json, sys, subprocess, os, numpy as np, soundfile as sf
vid, out = sys.argv[1], sys.argv[2]
meta = json.load(open(vid.replace('.mp4', '.speech.json')))
lines = json.load(open('tts/lines.json'))
by_text = {k.split('\t')[1]: v for k, v in lines.items()}
sr = 24000; total = meta['frames'] / 30
track = np.zeros(int((total + 1) * sr), dtype='float32')
for e in meta['log']:
    v = by_text.get(e['text'])
    if not v: print('no audio for', e['text'][:50]); continue
    a, _ = sf.read(v['file'], dtype='float32')
    at = max(0, (e['t'] - meta['t0']) / 1000 - 1 / 30)
    i = int(at * sr); track[i:i + len(a)] += a[:max(0, len(track) - i)]
peak = np.abs(track).max(); track = track / peak * 0.89 if peak > 0.89 else track
sf.write('mix_tmp.wav', track[:int(total * sr)], sr)
tmp = out.replace('.mp4', '.part.mp4')
r = subprocess.run(['ffmpeg', '-v', 'error', '-y', '-i', vid, '-i', 'mix_tmp.wav', '-map', '0:v', '-map', '1:a', '-c:v', 'copy', '-c:a', 'aac', '-b:a', '160k', '-ar', '48000', '-ac', '2', '-movflags', '+faststart', '-shortest', tmp])
if r.returncode == 0: os.rename(tmp, out)
print('lines placed', len(meta['log']), 'duration', round(total, 1), 'exit', r.returncode)
