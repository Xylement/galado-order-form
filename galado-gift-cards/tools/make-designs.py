#!/usr/bin/env python3
"""Draws the gift card design artwork into assets/designs/{key}.jpg (1200 x 750, no words).

Brand Guidelines v1.0: flat brand and campaign colours, simple graphic shapes, no gradients. The
headline, the amount and the galado wordmark are laid over the art by the plugin, so the lower left
(headline and amount) and the top left (wordmark) are kept clear here. Real artwork can replace any
file without running this: drop a JPG with the same name into the child theme (see the README).

    python3 tools/make-designs.py            # all designs
    python3 tools/make-designs.py raya cny   # some
Needs Pillow.
"""
import math
import os
import random
import sys

from PIL import Image, ImageDraw

W, H, S = 1200, 750, 2  # drawn at 2x, then scaled down for smooth edges
OUT = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'assets', 'designs')

INK, WHITE, SURFACE = (17, 17, 17), (255, 255, 255), (245, 245, 243)
RED, RED_TINT = (228, 0, 43), (255, 233, 236)
TEAL, COBALT, LILAC, LIME, SUN = (42, 181, 165), (43, 94, 232), (180, 140, 242), (198, 232, 75), (255, 201, 60)


def mix(a, b, t):
    return tuple(round(a[i] + (b[i] - a[i]) * t) for i in range(3))


def P(x, y):
    """Fractions of the card to pixels on the 2x canvas."""
    return x * W * S, y * H * S


def R(r):
    """A length given in 1x pixels."""
    return r * S


def RW(w):
    """A line width given in 1x pixels (PIL wants a whole number)."""
    return max(1, int(round(w * S)))


class Card:
    def __init__(self, bg):
        self.img = Image.new('RGB', (W * S, H * S), bg)
        self.d = ImageDraw.Draw(self.img)

    def circle(self, x, y, r, fill=None, outline=None, width=0):
        cx, cy = P(x, y)
        r = R(r)
        self.d.ellipse([cx - r, cy - r, cx + r, cy + r], fill=fill, outline=outline, width=RW(width) if width else 0)

    def ellipse(self, x, y, rx, ry, fill=None, outline=None, width=0, angle=0):
        cx, cy = P(x, y)
        if angle == 0:
            self.d.ellipse([cx - R(rx), cy - R(ry), cx + R(rx), cy + R(ry)], fill=fill, outline=outline,
                           width=RW(width) if width else 0)
            return
        a = math.radians(angle)
        pts = []
        for i in range(72):
            t = 2 * math.pi * i / 72
            ex, ey = R(rx) * math.cos(t), R(ry) * math.sin(t)
            pts.append((cx + ex * math.cos(a) - ey * math.sin(a), cy + ex * math.sin(a) + ey * math.cos(a)))
        self.d.polygon(pts, fill=fill, outline=outline)

    def poly(self, pts, fill=None, outline=None, width=0):
        pts = [P(x, y) for x, y in pts]
        if width:
            self.d.line(pts + [pts[0]], fill=outline, width=RW(width), joint='curve')
            if fill:
                self.d.polygon(pts, fill=fill)
        else:
            self.d.polygon(pts, fill=fill, outline=outline)

    def line(self, pts, fill, width):
        self.d.line([P(x, y) for x, y in pts], fill=fill, width=RW(width), joint='curve')
        for x, y in (pts[0], pts[-1]):  # round ends
            self.circle(x, y, width / 2, fill=fill)

    def rect(self, x, y, w, h, angle, fill):
        """A w x h (1x pixels) rectangle centred on (x, y), turned by angle degrees."""
        cx, cy = P(x, y)
        a = math.radians(angle)
        out = []
        for dx, dy in ((-w / 2, -h / 2), (w / 2, -h / 2), (w / 2, h / 2), (-w / 2, h / 2)):
            px, py = R(dx), R(dy)
            out.append((cx + px * math.cos(a) - py * math.sin(a), cy + px * math.sin(a) + py * math.cos(a)))
        self.d.polygon(out, fill=fill)

    def save(self, key):
        img = self.img.resize((W, H), Image.LANCZOS)
        img.save(os.path.join(OUT, key + '.jpg'), 'JPEG', quality=86, optimize=True, progressive=True)


def heart_pts(x, y, size, angle=0):
    a = math.radians(angle)
    pts = []
    for i in range(80):
        t = 2 * math.pi * i / 80
        hx = 16 * math.sin(t) ** 3
        hy = -(13 * math.cos(t) - 5 * math.cos(2 * t) - 2 * math.cos(3 * t) - math.cos(4 * t))
        hx, hy = hx * size / 32, hy * size / 32
        rx, ry = hx * math.cos(a) - hy * math.sin(a), hx * math.sin(a) + hy * math.cos(a)
        pts.append((x + rx / W, y + ry / H))
    return pts


def star_pts(x, y, ro, ri, n=5, angle=-90):
    pts = []
    for i in range(n * 2):
        r = ro if i % 2 == 0 else ri
        t = math.radians(angle + 180 * i / n)
        pts.append((x + r * math.cos(t) / W, y + r * math.sin(t) / H))
    return pts


def sparkle(c, x, y, r, fill):
    c.poly(star_pts(x, y, r, r * 0.28, 4, -90), fill=fill)


def flower(c, x, y, r, petals, petal, centre, angle=0):
    for i in range(petals):
        t = math.radians(angle + 360 * i / petals)
        c.circle(x + r * 0.62 * math.cos(t) / W, y + r * 0.62 * math.sin(t) / H, r * 0.48, fill=petal)
    c.circle(x, y, r * 0.36, fill=centre)


def leaf(c, x, y, length, angle, fill):
    c.ellipse(x, y, length / 2, length / 5.5, fill=fill, angle=angle)


def scatter(rnd, n, x0, x1, y0, y1, avoid=None, gap=0.0):
    """n random points in a box, kept out of the text areas."""
    out, tries = [], 0
    while len(out) < n and tries < n * 200:
        tries += 1
        x, y = rnd.uniform(x0, x1), rnd.uniform(y0, y1)
        if x < 0.34 and y < 0.22:  # wordmark
            continue
        if x < 0.64 and y > 0.46:  # headline and amount
            continue
        if gap and any(abs(x - a) < gap and abs(y - b) < gap * 1.6 for a, b in out):
            continue
        out.append((x, y))
    return out


# ---- the designs -------------------------------------------------------------------------------

def classic(rnd):
    c = Card(INK)
    for k, r in enumerate((150, 240, 330, 420, 510, 600, 690)):
        c.circle(1.0, 0.0, r, outline=mix(INK, WHITE, 0.20 - k * 0.018), width=3)
    c.circle(0.80, 0.42, 16, fill=RED)  # the brand dot
    return c


def birthday(rnd):
    c = Card(SUN)
    cols = [INK, COBALT, RED, WHITE, TEAL]
    for i, (x, y) in enumerate(scatter(rnd, 70, 0.30, 0.99, 0.02, 0.98, gap=0.035)):
        col = cols[i % len(cols)]
        if i % 3 == 0:
            c.circle(x, y, rnd.uniform(5, 8), fill=col)
        else:
            c.rect(x, y, rnd.uniform(9, 14), rnd.uniform(18, 26), rnd.uniform(0, 180), col)
    for x, y, col in ((0.80, 0.30, COBALT), (0.905, 0.40, RED)):
        c.line([(x, y + 0.12), (x - 0.01, y + 0.30), (x + 0.004, y + 0.52)], INK, 2.2)
        c.ellipse(x, y, 58, 72, fill=col)
        c.ellipse(x - 0.016, y - 0.035, 12, 20, fill=mix(col, WHITE, 0.55), angle=25)
        c.poly([(x - 0.008, y + 0.098), (x + 0.008, y + 0.098), (x, y + 0.115)], fill=col)
    return c


def thanks(rnd):
    c = Card(LIME)
    for x, y, s, a in ((0.82, 0.36, 230, -12), (0.66, 0.16, 90, 14), (0.95, 0.80, 120, 18)):
        c.poly(heart_pts(x, y, s, a), outline=INK, width=4)
    for x, y in scatter(rnd, 16, 0.40, 0.99, 0.03, 0.97, gap=0.07):
        c.poly(heart_pts(x, y, rnd.uniform(22, 34), rnd.uniform(-20, 20)), fill=WHITE)
    return c


def justbecause(rnd):
    c = Card(LIME)
    for y0, col, amp in ((0.20, INK, 0.035), (0.48, COBALT, 0.045), (0.78, WHITE, 0.03)):
        pts = [(0.62 + i * 0.012, y0 + amp * math.sin(i * 0.9)) for i in range(32)]
        c.line(pts, col, 9)
    c.circle(0.83, 0.62, 70, fill=SUN)
    c.circle(0.808, 0.60, 7, fill=INK)
    c.circle(0.852, 0.60, 7, fill=INK)
    c.d.arc([*P(0.80, 0.60), *P(0.86, 0.69)], 20, 160, fill=INK, width=RW(6))
    for x, y in scatter(rnd, 10, 0.40, 0.99, 0.03, 0.4, gap=0.08):
        c.circle(x, y, rnd.uniform(6, 10), fill=WHITE)
    return c


def thinking(rnd):
    c = Card(SURFACE)
    for x, y, s, col in ((0.80, 0.34, 1.35, LILAC), (0.95, 0.74, 0.85, mix(LILAC, WHITE, 0.45)), (0.64, 0.13, 0.7, mix(LILAC, WHITE, 0.45))):
        for dx, dy, r in ((-0.07, 0.035, 52), (0.0, -0.025, 70), (0.075, 0.03, 50), (0.0, 0.06, 56)):
            c.circle(x + dx * s, y + dy * s, r * s, fill=col)
    c.poly(heart_pts(0.80, 0.35, 92, -8), fill=RED)
    for x, y in scatter(rnd, 14, 0.42, 0.99, 0.03, 0.97, gap=0.07):
        sparkle(c, x, y, rnd.uniform(10, 16), SUN)
    return c


def getwell(rnd):
    c = Card(TEAL)
    c.circle(0.90, 0.20, 92, fill=SUN)
    for i in range(12):  # sun rays
        t = 2 * math.pi * i / 12
        c.line([(0.90 + 112 * math.cos(t) / W, 0.20 + 112 * math.sin(t) / H),
                (0.90 + 140 * math.cos(t) / W, 0.20 + 140 * math.sin(t) / H)], SUN, 7)
    for x, y, r in ((0.76, 0.56, 100), (0.94, 0.80, 70), (0.66, 0.22, 58)):
        c.line([(x, y + r * 0.6 / H), (x, y + r * 2.4 / H)], mix(LIME, INK, 0.2), 6)
        leaf(c, x - 0.045, y + r * 1.5 / H, r * 1.3, -35, LIME)
        leaf(c, x + 0.045, y + r * 1.7 / H, r * 1.1, 40, LIME)
        flower(c, x, y, r, 6, WHITE, SUN, rnd.uniform(0, 60))
    return c


def congrats(rnd):
    c = Card(COBALT)
    for x, y, r, col in ((0.80, 0.30, 150, SUN), (0.93, 0.74, 95, LIME), (0.64, 0.14, 70, WHITE), (0.96, 0.18, 55, LILAC)):
        n = 14
        for i in range(n):
            t = 2 * math.pi * i / n
            x1, y1 = x + r * 0.35 * math.cos(t) / W, y + r * 0.35 * math.sin(t) / H
            x2, y2 = x + r * math.cos(t) / W, y + r * math.sin(t) / H
            c.line([(x1, y1), (x2, y2)], col, 5)
            c.circle(x + r * 1.18 * math.cos(t) / W, y + r * 1.18 * math.sin(t) / H, 5, fill=col)
    return c


def graduation(rnd):
    c = Card(SUN)
    for x, y, s, a in ((0.80, 0.32, 1.0, -14), (0.93, 0.72, 0.7, 12), (0.65, 0.13, 0.55, 8)):
        top = [(x - 0.11 * s, y), (x, y - 0.075 * s), (x + 0.11 * s, y), (x, y + 0.075 * s)]
        c.rect(x, y + 0.065 * s, 150 * s, 70 * s, a, INK)
        c.poly(top, fill=INK)
        c.line([(x, y), (x + 0.07 * s, y + 0.02 * s), (x + 0.07 * s, y + 0.13 * s)], RED, 4 * s)
        c.circle(x + 0.07 * s, y + 0.14 * s, 9 * s, fill=RED)
    for i, (x, y) in enumerate(scatter(rnd, 26, 0.38, 0.99, 0.02, 0.98, gap=0.05)):
        c.rect(x, y, 10, 20, rnd.uniform(0, 180), [COBALT, WHITE, TEAL][i % 3])
    return c


def wedding(rnd):
    c = Card(SURFACE)
    for x, y in ((0.76, 0.40), (0.86, 0.40)):
        c.circle(x, y, 118, outline=SUN, width=16)
    c.circle(0.86, 0.40 - 0.157, 22, fill=WHITE, outline=SUN, width=6)
    for x, y, a in ((0.64, 0.16, 30), (0.70, 0.12, -20), (0.95, 0.74, 25), (0.90, 0.80, -35), (0.98, 0.64, 60)):
        leaf(c, x, y, 70, a, TEAL if a > 0 else LILAC)
    return c


def anniversary(rnd):
    c = Card(LILAC)
    c.poly(heart_pts(0.78, 0.38, 250, -10), fill=RED)
    c.poly(heart_pts(0.88, 0.46, 250, 12), outline=WHITE, width=10)
    for x, y in scatter(rnd, 9, 0.42, 0.99, 0.03, 0.97, gap=0.09):
        sparkle(c, x, y, rnd.uniform(14, 24), WHITE)
    return c


def newbaby(rnd):
    c = Card(RED_TINT)
    c.circle(0.84, 0.30, 105, fill=LILAC)
    c.circle(0.885, 0.255, 92, fill=RED_TINT)  # turns it into a crescent
    for x, y, s in ((0.70, 0.62, 0.8), (0.93, 0.78, 0.6)):
        for dx, dy, r in ((-0.05, 0.02, 44), (0.0, -0.02, 60), (0.055, 0.02, 42)):
            c.circle(x + dx * s, y + dy * s, r * s, fill=WHITE)
    for i, (x, y) in enumerate(scatter(rnd, 12, 0.40, 0.99, 0.03, 0.97, gap=0.08)):
        c.poly(star_pts(x, y, rnd.uniform(12, 20), rnd.uniform(5, 8)), fill=[SUN, TEAL][i % 2])
    return c


def raya(rnd):
    c = Card(TEAL)
    c.circle(0.62, 0.20, 70, fill=SUN)
    c.circle(0.645, 0.18, 60, fill=TEAL)
    c.poly(star_pts(0.69, 0.13, 20, 8), fill=SUN)
    for x, y, s in ((0.80, 0.40, 1.0), (0.92, 0.28, 0.75), (0.93, 0.74, 0.8)):
        c.line([(x, 0.0), (x, y - 0.13 * s)], WHITE, 3)
        dx, dy = 0.075 * s, 0.12 * s
        c.poly([(x, y - dy), (x + dx, y), (x, y + dy), (x - dx, y)], fill=LIME)
        for k in (-2, -1, 0, 1, 2):  # the woven grid
            f = k / 3
            c.line([(x - dx / 2 + f * dx / 2, y - dy / 2 - f * dy / 2), (x + dx / 2 + f * dx / 2, y + dy / 2 - f * dy / 2)],
                   mix(LIME, INK, 0.35), 2.5)
            c.line([(x - dx / 2 + f * dx / 2, y + dy / 2 + f * dy / 2), (x + dx / 2 + f * dx / 2, y - dy / 2 + f * dy / 2)],
                   mix(LIME, INK, 0.35), 2.5)
        c.line([(x - 0.01 * s, y + dy), (x - 0.03 * s, y + dy + 0.10 * s)], LIME, 7 * s)
        c.line([(x + 0.01 * s, y + dy), (x + 0.025 * s, y + dy + 0.09 * s)], LIME, 7 * s)
    return c


def cny(rnd):
    c = Card(RED)
    dark = mix(RED, INK, 0.45)
    for x, y, s in ((0.78, 0.36, 1.0), (0.91, 0.24, 0.8), (0.92, 0.70, 0.7)):
        c.line([(x, 0.0), (x, y - 0.12 * s)], SUN, 3)
        c.rect(x, y - 0.115 * s, 60 * s, 14 * s, 0, dark)
        c.rect(x, y + 0.115 * s, 60 * s, 14 * s, 0, dark)
        c.ellipse(x, y, 95 * s, 82 * s, fill=SUN)
        for k in (0.55, 0.15):
            c.ellipse(x, y, 95 * s * k, 82 * s, outline=mix(SUN, RED, 0.35), width=3)
        c.line([(x, y + 0.13 * s), (x, y + 0.24 * s)], SUN, 4)
        c.rect(x, y + 0.255 * s, 14 * s, 30 * s, 0, SUN)
    for x, y in scatter(rnd, 8, 0.42, 0.99, 0.05, 0.95, gap=0.10):
        flower(c, x, y, rnd.uniform(18, 26), 5, mix(WHITE, RED_TINT, 0.3), SUN, rnd.uniform(0, 70))
    return c


def deepavali(rnd):
    c = Card(COBALT)
    x, y = 0.83, 0.40
    rings = ((260, 18, SUN), (200, 14, LILAC), (140, 12, WHITE), (85, 10, SUN))
    for r, n, col in rings:
        for i in range(n):
            t = 360 * i / n
            px, py = x + r * 0.78 * math.cos(math.radians(t)) / W, y + r * 0.78 * math.sin(math.radians(t)) / H
            c.ellipse(px, py, r * 0.20, r * 0.085, fill=col, angle=t)
    c.circle(x, y, 34, fill=TEAL)
    c.circle(x, y, 14, fill=SUN)
    for dxx in (0.70, 0.80, 0.90):
        bx, by = dxx, 0.88
        c.d.chord([*P(bx - 0.04, by - 0.06), *P(bx + 0.04, by + 0.06)], 0, 180, fill=SUN)
        c.poly([(bx - 0.012, by - 0.005), (bx, by - 0.075), (bx + 0.012, by - 0.005)], fill=WHITE)
        c.circle(bx, by - 0.012, 12, fill=WHITE)
    return c


def christmas(rnd):
    c = Card(INK)
    for x, base, h, col in ((0.78, 0.92, 0.70, TEAL), (0.92, 0.92, 0.52, LIME), (0.66, 0.92, 0.36, TEAL)):
        w = h * 0.36
        for k in range(3):
            top = base - h + k * h * 0.28
            bot = base - h * 0.18 - (2 - k) * h * 0.24
            ww = w * (0.55 + k * 0.25)
            c.poly([(x, top), (x + ww, bot), (x - ww, bot)], fill=col)
        c.rect(x, base - h * 0.09, 26, h * 140, 0, mix(INK, WHITE, 0.25))
    c.poly(star_pts(0.78, 0.20, 34, 14), fill=SUN)
    c.circle(0.84, 0.55, 18, fill=RED)  # one bauble
    for x, y in scatter(rnd, 40, 0.36, 0.99, 0.02, 0.98, gap=0.04):
        c.circle(x, y, rnd.uniform(3, 6), fill=WHITE)
    return c


def valentines(rnd):
    c = Card(RED_TINT)
    c.poly(heart_pts(0.80, 0.40, 300, -10), fill=RED)
    c.poly(heart_pts(0.93, 0.72, 150, 14), fill=LILAC)
    c.poly(heart_pts(0.64, 0.15, 90, 10), fill=LILAC)
    for x, y in scatter(rnd, 10, 0.40, 0.99, 0.03, 0.97, gap=0.08):
        c.poly(heart_pts(x, y, rnd.uniform(24, 36), rnd.uniform(-20, 20)), outline=RED, width=3)
    return c


def mothers(rnd):
    c = Card(LILAC)
    for x, y, r in ((0.80, 0.38, 95), (0.93, 0.70, 70), (0.66, 0.16, 50), (0.95, 0.16, 45)):
        leaf(c, x - 0.05, y + 0.10, r * 1.6, -30, LIME)
        leaf(c, x + 0.05, y + 0.11, r * 1.4, 35, LIME)
        flower(c, x, y, r, 5, WHITE, SUN, rnd.uniform(0, 70))
    return c


def fathers(rnd):
    c = Card(COBALT)
    x = 0.82
    knot = [(x - 0.045, 0.06), (x + 0.045, 0.06), (x + 0.03, 0.17), (x - 0.03, 0.17)]
    tie = [(x - 0.03, 0.17), (x + 0.03, 0.17), (x + 0.085, 0.78), (x, 0.92), (x - 0.085, 0.78)]
    c.poly(tie, fill=SUN)
    tie_layer = Image.new('L', c.img.size, 0)  # stripes, clipped to the tie
    ImageDraw.Draw(tie_layer).polygon([P(a, b) for a, b in tie], fill=255)
    stripes = Image.new('RGB', c.img.size, SUN)
    sd = ImageDraw.Draw(stripes)
    for k in range(-10, 12):
        y0 = 0.10 + k * 0.075
        sd.line([P(x - 0.12, y0 + 0.10), P(x + 0.12, y0 - 0.10)], fill=INK, width=RW(10))
    c.img.paste(stripes, (0, 0), tie_layer)
    c.poly(knot, fill=mix(SUN, INK, 0.25))
    for x0, y0, r in ((0.93, 0.30, 26), (0.68, 0.30, 18), (0.95, 0.62, 16), (0.66, 0.12, 12)):
        c.circle(x0, y0, r, fill=WHITE)
    return c


DESIGNS = {
    'classic': classic, 'thanks': thanks, 'justbecause': justbecause, 'thinking': thinking, 'getwell': getwell,
    'birthday': birthday, 'congrats': congrats, 'graduation': graduation, 'wedding': wedding,
    'anniversary': anniversary, 'newbaby': newbaby, 'raya': raya, 'cny': cny, 'deepavali': deepavali,
    'christmas': christmas, 'valentines': valentines, 'mothers': mothers, 'fathers': fathers,
}

if __name__ == '__main__':
    keys = sys.argv[1:] or list(DESIGNS)
    os.makedirs(OUT, exist_ok=True)
    for key in keys:
        DESIGNS[key](random.Random(key)).save(key)
        print('drew', key)
