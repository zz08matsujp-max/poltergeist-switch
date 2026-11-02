# Speak every delivered line of the nine recorded episodes with the voices chosen on 2026-11-02 (Kokoro-82M).
import json, re, hashlib, warnings, numpy as np, soundfile as sf
warnings.filterwarnings('ignore')
from kokoro import KPipeline
VO = {'claude': ('a', 'af_heart', 1.0), 'gpt': ('a', 'am_fenrir', 1.0), 'man': ('b', 'bm_lewis', 0.8), 'gpt_ja': ('j', 'jf_alpha', 1.0)}
P = {l: KPipeline(lang_code=l, repo_id='hexgrad/Kokoro-82M') for l in ('a', 'b', 'j')}
JA = re.compile(r'[぀-ヿ一-鿿]')
rp = json.load(open('tts/replay_t2.json'))
lines = [('man', "What's happening?!")]
for ep in rp['episodes']:
    for t in ep['turns']:
        for k in ('claude', 'gpt'):
            lines += [(k, w) for w in t[k]['words']]
out, seen = {}, set()
for who, text in lines:
    if (who, text) in seen: continue
    seen.add((who, text))
    key = who + ('_ja' if who == 'gpt' and JA.search(text) else '')
    lang, voice, speed = VO[key]
    say = text.replace('’', "'").replace('‘', "'").replace('—', ', ').replace('“', '"').replace('”', '"')
    a = np.concatenate([np.asarray(x) for _, _, x in P[lang](say, voice=voice, speed=speed)])
    name = f"tts/{who}_{hashlib.sha1(text.encode()).hexdigest()[:10]}.wav"
    sf.write(name, a, 24000)
    out[who + '\t' + text] = dict(file=name, secs=round(len(a) / 24000, 3), voice=voice)
json.dump(out, open('tts/lines.json', 'w'), ensure_ascii=False, indent=1)
print(len(out), 'lines', round(sum(v['secs'] for v in out.values()), 1), 's of speech')
