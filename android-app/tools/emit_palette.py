exec(open(__file__.rsplit('/',1)[0] + '/tonal.py').read().split('SEEDS =')[0])

# Chroma targets follow Material's TonalSpot scheme, nudged up on primary so the
# Navy brand hue keeps its punch instead of drifting grey.
SEEDS = {
  'Primary':        ('#073851', 36.0, 0),
  'Secondary':      ('#00BCD4', 24.0, 0),
  'Tertiary':       ('#073851', 24.0, 60),
  'Neutral':        ('#073851',  6.0, 0),
  'NeutralVariant': ('#073851', 10.0, 0),
  'Error':          ('#BA1A1A', None, 0),
  'ClockedIn':      ('#2E7D32', 44.0, 0),
  'Lunch':          ('#F57C00', 52.0, 0),
  'Warning':        ('#F59E0B', 52.0, 0),
}
P={}
for name,(seed,ch,rot) in SEEDS.items():
    L,C,H = lch(seed)
    H=(H+rot)%360
    if ch is not None: C=ch
    P[name]={t: tone(H,C,float(t)) for t in TONES}

def k(pal,t): return f"0xFF{P[pal][t]}"
lines=[]
A=lines.append
A('package com.foleyit.foleytimepro.ui.theme')
A('')
A('import androidx.compose.ui.graphics.Color')
A('')
A('/*')
A(' * Material 3 tonal palettes, generated from the brand seeds rather than picked by')
A(' * hand: each ramp holds its seed hue and pushes chroma to the gamut edge at every')
A(' * tone, which is what makes the container/on-container pairs land on legal')
A(' * contrast without anyone checking them one by one.')
A(' *')
A(' *   Primary        #073851  Foley navy')
A(' *   Secondary      #00BCD4  Foley cyan')
A(' *   Tertiary       navy hue +60 deg')
A(' *   ClockedIn      #2E7D32  punch-state green')
A(' *   Lunch          #F57C00  punch-state orange')
A(' *   Warning        #F59E0B  offline / queued amber')
A(' *')
A(' * Clocking out is destructive, so it borrows the error roles instead of carrying')
A(' * a fourth near-identical red ramp. Ending lunch borrows secondary.')
A(' *')
A(' * Regenerate with tools/tonal.py if a seed ever changes -- do not edit tones by hand.')
A(' */')
A('')
for name in P:
    A(f'// ---- {name} ----')
    for t in TONES:
        A(f'internal val {name}{t} = Color({k(name,t)})')
    A('')
open('Color.kt','w').write('\n'.join(lines))
print('\n'.join(lines[:14]))
print('...')
for n in P: print(n, {t:P[n][t] for t in (10,30,40,80,90)})
