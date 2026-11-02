import json, warnings, numpy as np, soundfile as sf
warnings.filterwarnings('ignore')
from kokoro import KPipeline
P = KPipeline(lang_code='a', repo_id='hexgrad/Kokoro-82M')
S = json.load(open('narr3/script.json')); out = {}
for sc, lines in S.items():
    out[sc] = []
    for i, t in enumerate(lines):
        a = np.concatenate([np.asarray(x) for _, _, x in P(t.replace('1986', 'nineteen eighty-six'), voice='am_michael', speed=1.0)])
        f = f'narr3/{sc}_{i}.wav'; sf.write(f, a, 24000); out[sc].append(dict(text=t, file=f, secs=round(len(a) / 24000, 3)))
json.dump(out, open('narr3/lines.json', 'w'), indent=1)
for sc, L in out.items(): print(sc, round(sum(x['secs'] for x in L) + 0.35 * (len(L) - 1), 2), [x['secs'] for x in L])
