import math

def srgb_to_lin(c):
    c/=255.0
    return c/12.92 if c<=0.04045 else ((c+0.055)/1.055)**2.4
def lin_to_srgb(c):
    v = 12.92*c if c<=0.0031308 else 1.055*(c**(1/2.4))-0.055
    return max(0,min(255,round(v*255)))

M = [[0.4124564,0.3575761,0.1804375],
     [0.2126729,0.7151522,0.0721750],
     [0.0193339,0.1191920,0.9503041]]
MI= [[ 3.2404542,-1.5371385,-0.4985314],
     [-0.9692660, 1.8760108, 0.0415560],
     [ 0.0556434,-0.2040259, 1.0572252]]
WP=(0.95047,1.0,1.08883)

def rgb_to_xyz(r,g,b):
    R,G,B = srgb_to_lin(r),srgb_to_lin(g),srgb_to_lin(b)
    return [sum(M[i][j]*v for j,v in enumerate((R,G,B))) for i in range(3)]
def f(t):
    return t**(1/3) if t>216/24389 else (841/108)*t+4/29
def finv(t):
    return t**3 if t**3>216/24389 else (t-4/29)*108/841
def xyz_to_lab(X,Y,Z):
    fx,fy,fz = f(X/WP[0]), f(Y/WP[1]), f(Z/WP[2])
    return 116*fy-16, 500*(fx-fy), 200*(fy-fz)
def lab_to_xyz(L,a,b):
    fy=(L+16)/116; fx=fy+a/500; fz=fy-b/200
    return finv(fx)*WP[0], finv(fy)*WP[1], finv(fz)*WP[2]
def lab_to_rgb(L,a,b):
    X,Y,Z = lab_to_xyz(L,a,b)
    lin = [sum(MI[i][j]*v for j,v in enumerate((X,Y,Z))) for i in range(3)]
    return [lin_to_srgb(c) for c in lin], all(-0.001<=c<=1.001 for c in lin)

def hex_to_rgb(h):
    h=h.lstrip('#'); return tuple(int(h[i:i+2],16) for i in (0,2,4))
def lch(hexc):
    L,a,b = xyz_to_lab(*rgb_to_xyz(*hex_to_rgb(hexc)))
    return L, math.hypot(a,b), math.degrees(math.atan2(b,a))%360

def tone(hue, chroma, L):
    """Highest-chroma in-gamut colour at lightness L for this hue."""
    lo, hi = 0.0, chroma
    best,_ = lab_to_rgb(L, 0, 0)
    for _ in range(40):
        mid=(lo+hi)/2
        a = mid*math.cos(math.radians(hue)); b = mid*math.sin(math.radians(hue))
        rgb, ok = lab_to_rgb(L,a,b)
        if ok: best=rgb; lo=mid
        else:  hi=mid
    return '%02X%02X%02X' % tuple(best)

TONES=[0,4,6,10,12,17,20,22,24,30,40,50,60,70,80,87,90,92,94,95,96,98,99,100]

def palette(seed, chroma_override=None, name=''):
    L,C,H = lch(seed)
    C = chroma_override if chroma_override is not None else C
    return {t: tone(H, C, float(t)) for t in TONES}

SEEDS = {
  'Primary':        ('#073851', None),
  'Secondary':      ('#00BCD4', 26.0),
  'Tertiary':       ('#073851', None),   # hue-rotated below
  'Neutral':        ('#073851', 5.0),
  'NeutralVariant': ('#073851', 11.0),
  'Error':          ('#BA1A1A', None),
  'ClockedIn':      ('#2E7D32', None),
  'Lunch':          ('#F57C00', None),
  'ClockedOut':     ('#B3261E', None),
  'Warning':        ('#F59E0B', None),
}

out={}
for name,(seed,ch) in SEEDS.items():
    L,C,H = lch(seed)
    if name=='Tertiary': H=(H+60)%360; C=min(C,40.0)
    if ch is not None: C=ch
    out[name]={t: tone(H,C,float(t)) for t in TONES}

import json
json.dump(out, open('palette.json','w'), indent=1)
for n,p in out.items():
    print(n, ' '.join(f'{t}:{p[t]}' for t in (10,20,30,40,80,90)))
