#!/usr/bin/env python3
"""
Generátor ikon aplikace BeSix Plans.

Spuštění:
  python3 generate_icons.py           # zapíše do icons/
  python3 generate_icons.py BOARD     # jiný nápis v pásu

Skládá ikonu z loga besix_logo_bila.png a fontu Montserrat Bold,
takže ji lze kdykoli přegenerovat beze ztráty kvality – na rozdíl
od dřívější verze, která jen přebarvovala hotové icon-512.png.

Rozvržení (hodnoty platí pro 512 px, menší velikosti se odvozují poměrem):
  spodní pás      26 % výšky           → od y = 378
  logo            56 % šířky (287 px), horní okraj 12 % (y = 61)
  text            52 pt, prostrkání 6 px, svislý střed pásu (y = 445)

Barevnost je černobílá:
  horní pozadí  bílá   #FFFFFF
  logo          černá  #000000
  spodní pás    černá  #000000
  text          bílá   #FFFFFF
"""

import os
import sys
from PIL import Image, ImageDraw, ImageFont

# ── Nastavení ──────────────────────────────────────────────────
BAND_COLOR = (0, 0, 0)         # #000000  spodní pás
LOGO_COLOR = (0, 0, 0)         # #000000  logo
TOP_COLOR  = (255, 255, 255)   # #FFFFFF  horní pozadí
TEXT_COLOR = (255, 255, 255)   # #FFFFFF  text v pásu
PLANS_TEXT = "PLANS"           # nápis v pásu
# ───────────────────────────────────────────────────────────────

BASE     = os.path.dirname(os.path.abspath(__file__))
LOGO_SRC = os.path.join(BASE, "besix_logo_bila.png")
FONT_TTF = os.path.join(BASE, "assets", "fonts", "Montserrat-Bold.ttf")
OUT_DIR  = os.path.join(BASE, "icons")

REF = 512          # referenční plátno, ke kterému se vztahují rozměry níže

# běžná ikona
BAND_TOP, LOGO_W, LOGO_TOP, FONT_PT, TEXT_Y = 378, 287, 61, 52, 445
KERNING = 6

# maskable: launcher ořízne ~13 %, viditelná zůstane oblast 33–479 px,
# takže celá skladba je stažena dovnitř této zóny
M_BAND_TOP, M_LOGO_W, M_LOGO_TOP, M_FONT_PT, M_TEXT_Y = 359, 232, 93, 50, 419

# ikony, které se skládají celé; favicony viz FAVICONS
SIZES = [48, 72, 96, 128, 192, 512]

# na 16 a 32 px by byl pás i text jen šmouha, proto samotné logo na bílé
FAVICONS = [(16, "favicon-16.png"), (32, "favicon-32.png")]

# Zaoblení rohů ikony, podíl ze strany. Odpovídá masce, kterou si na ikonu
# kreslí systémy samy, takže ikona nevypadá jako ostrý bílý čtverec ani tam,
# kde si ji systém nemaskuje (lišta Windows, záložka prohlížeče).
CORNER = 0.22

# Launcher na mobilu z maskable ikony ukáže jen bezpečnou oblast 33–479 px.
# Běžné ikony se proto kreslí ze stejné předlohy a stejně oříznuté, aby ikona
# na ploše počítače vypadala identicky jako ta na telefonu.
SAFE = (33, 33, 479, 479)


def load_logo():
    """Logo oříznuté o průhledný okraj a přebarvené na LOGO_COLOR."""
    src = Image.open(LOGO_SRC).convert("RGBA")
    src = src.crop(src.split()[3].getbbox())
    logo = Image.new("RGBA", src.size, LOGO_COLOR + (0,))
    logo.putalpha(src.split()[3])
    return logo


def draw_text(draw, text, font, center_x, center_y, tracking):
    """Prostrkaný text vycentrovaný vodorovně i svisle podle skutečných glyfů."""
    widths = [draw.textlength(ch, font=font) for ch in text]
    total = sum(widths) + tracking * (len(text) - 1)
    top, bottom = font.getbbox(text)[1], font.getbbox(text)[3]
    x, y = center_x - total / 2, center_y - (top + bottom) / 2
    for ch, w in zip(text, widths):
        draw.text((x, y), ch, font=font, fill=TEXT_COLOR)
        x += w + tracking


def build(size, text, maskable=False):
    k = size / REF
    band_top = round((M_BAND_TOP if maskable else BAND_TOP) * k)
    logo_w   = round((M_LOGO_W   if maskable else LOGO_W)   * k)
    logo_top = round((M_LOGO_TOP if maskable else LOGO_TOP) * k)
    font_pt  = max(1, round((M_FONT_PT if maskable else FONT_PT) * k))
    text_y   = round((M_TEXT_Y   if maskable else TEXT_Y)   * k)

    img = Image.new("RGB", (size, size), TOP_COLOR)
    d = ImageDraw.Draw(img)
    d.rectangle([0, band_top, size, size], fill=BAND_COLOR)

    logo = load_logo()
    logo = logo.resize((logo_w, round(logo.height * logo_w / logo.width)), Image.LANCZOS)
    img.paste(logo, ((size - logo_w) // 2, logo_top), logo)

    draw_text(d, text, ImageFont.truetype(FONT_TTF, font_pt), size / 2, text_y, KERNING * k)
    return img


def rounded(img):
    """Ořízne rohy do oblouku. Výsledek je RGBA – rohy jsou průhledné."""
    size = img.size[0]
    mask = Image.new("L", (size, size), 0)
    ImageDraw.Draw(mask).rounded_rectangle([0, 0, size - 1, size - 1],
                                           round(size * CORNER), fill=255)
    out = img.convert("RGBA")
    out.putalpha(mask)
    return out


def build_logo_only(size):
    """Favicon: logo na bílé, s okrajem 12 % jako u velké ikony."""
    img = Image.new("RGB", (size, size), TOP_COLOR)
    logo = load_logo()
    w = round(size * 0.76)
    logo = logo.resize((w, round(logo.height * w / logo.width)), Image.LANCZOS)
    img.paste(logo, ((size - w) // 2, (size - logo.height) // 2), logo)
    return img


def main():
    text = sys.argv[1] if len(sys.argv) > 1 else PLANS_TEXT
    for src in (LOGO_SRC, FONT_TTF):
        if not os.path.exists(src):
            print("CHYBA: chybí %s" % src)
            return 1
    os.makedirs(OUT_DIR, exist_ok=True)
    print("Generuji ikony s nápisem %s" % text)

    # 512 se vykreslí v plné velikosti, menší se z něj zmenšují, aby byly ostré.
    # Zaoblit se musí až po zmenšení, jinak se oblouk rozmaže.
    master = build(512, text, maskable=True).crop(SAFE).resize((512, 512), Image.LANCZOS)
    for size in SIZES:
        img = master if size == 512 else master.resize((size, size), Image.LANCZOS)
        path = os.path.join(OUT_DIR, "icon-%d.png" % size)
        rounded(img).save(path, "PNG")
        print("  %3dx%-3d → %s" % (size, size, path))

    # apple-touch-icon a maskable zůstávají ostré čtverce: oba si systém maskuje
    # sám a průhledné rohy by u nich vyšly černě, respektive by se oblouk ořízl
    # podruhé.
    for name, size, img in [
        ("apple-touch-icon.png", 180, master.resize((180, 180), Image.LANCZOS)),
        ("icon-512-maskable.png", 512, build(512, text, maskable=True)),
    ]:
        path = os.path.join(OUT_DIR, name)
        img.save(path, "PNG")
        print("  %3dx%-3d → %s  (čtverec, maskuje si systém)" % (size, size, path))

    for size, name in FAVICONS:
        path = os.path.join(OUT_DIR, name)
        rounded(build_logo_only(size)).save(path, "PNG")
        print("  %3dx%-3d → %s" % (size, size, path))

    print("\nHotovo. Pro zobrazení tvrdě obnov stránku (Ctrl+Shift+R).")
    return 0


if __name__ == "__main__":
    sys.exit(main())
