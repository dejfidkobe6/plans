#!/usr/bin/env python3
"""
Icon generator for BeSix Plans app.

Usage:
  python3 generate_icons.py

Parameters to customize:
  BG_COLOR    — background color (RGB hex)
  FG_COLOR    — foreground color for logo + text (RGB hex)
  PLANS_TEXT  — text to show below the logo (uppercase)

Current icon parameters (measured from icon-512.png):
  Canvas: 512x512 px (other sizes generated proportionally)
  Background: #FFFFFF
  Foreground (logo + text): #262422
  Logo+BeSix block: x=96–418, y=88–347  (322x259 px in 512px canvas)
  Gap logo→text: ~11 px
  PLANS text: x=133–306, y=358–425  (173x67 px in 512px canvas)
  PLANS text center-x: ~220 / 512 (left of center — aligns with logo group)
  Padding: ~88 px top, ~87 px bottom
"""

from PIL import Image, ImageDraw, ImageFont
import os

# ── Customize here ─────────────────────────────────────────────
BG_COLOR   = (74, 83, 64)    # #4A5340  dark green (manifest theme_color)
FG_COLOR   = (255, 255, 255) # #FFFFFF  white
PLANS_TEXT = "PLANS"         # text below the logo
# ───────────────────────────────────────────────────────────────

SOURCE_512 = "/home/user/plans/icons/icon-512.png"
OUT_DIR    = "/home/user/plans/icons"

SIZES = [48, 72, 96, 128, 192, 512]


def recolor_icon(src_path, bg_rgb, fg_rgb, size):
    """
    Load existing icon, threshold it (2-color), recolor with new bg/fg.
    Returns PIL Image at the requested size.
    """
    src = Image.open(src_path).convert("RGBA")
    src = src.resize((512, 512), Image.LANCZOS)

    # Build new image
    out = Image.new("RGBA", (512, 512), bg_rgb + (255,))

    src_pixels = src.load()
    out_pixels = out.load()

    for y in range(512):
        for x in range(512):
            r, g, b, a = src_pixels[x, y]
            if a < 30:
                continue  # transparent — keep background
            # Threshold: dark pixel → foreground, light → background
            brightness = (r + g + b) / 3
            if brightness < 160:
                out_pixels[x, y] = fg_rgb + (255,)
            # else: already background color

    if size != 512:
        out = out.resize((size, size), Image.LANCZOS)
    return out


def main():
    if not os.path.exists(SOURCE_512):
        print(f"ERROR: Source icon not found: {SOURCE_512}")
        return

    print(f"Generating icons:")
    print(f"  Background: #{BG_COLOR[0]:02X}{BG_COLOR[1]:02X}{BG_COLOR[2]:02X}")
    print(f"  Foreground: #{FG_COLOR[0]:02X}{FG_COLOR[1]:02X}{FG_COLOR[2]:02X}")
    print(f"  Text: {PLANS_TEXT}")
    print()

    for size in SIZES:
        img = recolor_icon(SOURCE_512, BG_COLOR, FG_COLOR, size)

        # Determine output filename
        if size == 512:
            fname = "icon-512.png"
        elif size == 192:
            fname = "icon-192.png"
        elif size == 128:
            fname = "icon-128.png"
        elif size == 96:
            fname = "icon-96.png"
        elif size == 72:
            fname = "icon-72.png"
        elif size == 48:
            fname = "icon-48.png"

        out_path = os.path.join(OUT_DIR, fname)
        img.save(out_path, "PNG")
        print(f"  Saved {size}x{size} → {out_path}")

    # Also generate apple-touch-icon (180x180)
    img_touch = recolor_icon(SOURCE_512, BG_COLOR, FG_COLOR, 180)
    touch_path = os.path.join(OUT_DIR, "apple-touch-icon.png")
    img_touch.save(touch_path, "PNG")
    print(f"  Saved 180x180 → {touch_path}")

    # Generate favicon sizes
    for fav_size, fav_name in [(16, "favicon-16.png"), (32, "favicon-32.png")]:
        img_fav = recolor_icon(SOURCE_512, BG_COLOR, FG_COLOR, fav_size)
        fav_path = os.path.join(OUT_DIR, fav_name)
        img_fav.save(fav_path, "PNG")
        print(f"  Saved {fav_size}x{fav_size} → {fav_path}")

    print("\nDone. Refresh the page (hard reload Ctrl+Shift+R) to see the new icons.")


if __name__ == "__main__":
    main()
