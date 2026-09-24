#!/usr/bin/env python3
"""
Make the gallery poster for one Q&A session.

    python3 qa-make-poster.py --number 2 --date 2026-09-01 --minutes 76
    python3 qa-make-poster.py --number 2 --date 2026-09-01 --minutes 76 --bg portrait

Why not just use Vimeo's thumbnail: Vimeo grabs a frame, and these are Zoom
calls. The September auto-frame was a participant nobody recognises with their
name badge in the corner; the August one caught Michi mid-blink. Across a
growing library that reads as a folder of screenshots rather than a series.

A designed card is also the only thing that stays legible at gallery size, where
a card is about 380px wide and a talking head becomes a smudge.

Output goes to events-site/live-qa/replay/img/qa-<date>-poster.jpg at 1280x720,
which is small enough to live in the repo, so no deploy can lose it the way the
recordings were lost.
"""
import argparse
import pathlib
import subprocess
import sys
import tempfile
from datetime import date as _date

HERE = pathlib.Path(__file__).resolve().parent
BG_DIR = HERE.parents[2] / "website" / "static" / "campaign" / "_templates" / "bg"
OUT_DIR = (HERE.parents[3] / "events-site" / "live-qa" / "replay" / "img")

BACKGROUNDS = {
    "portrait": "canva-story-portrait-bg.jpg",
    "feed":     "canva-feed-portrait-bg.jpg",
    "action":   "canva-raw-2944.jpg",
    "airborne": "canva-raw-3035.jpg",
    "cruising": "canva-raw-5440.jpg",
}
# Rotate through the riding shots so a shelf of posters does not repeat, while
# staying deterministic: session N always gets the same photo.
ROTATION = ["action", "airborne", "cruising", "feed"]

TEMPLATE = """<!DOCTYPE html><html><head><meta charset="utf-8">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@600;800;900&display=swap" rel="stylesheet">
</head><body style="margin:0">
<div style="width:1280px;height:720px;position:relative;overflow:hidden;background:#0d1b2e;
            font-family:Inter,Arial,Helvetica,sans-serif">

  <img src="bg.jpg" style="position:absolute;inset:0;width:100%;height:100%;
       object-fit:cover;object-position:50% 30%">

  <!-- Only enough wash to keep the number and wordmark legible. The card beside
       this image already carries the title, date, duration and topics, so
       repeating them here made every tile say everything twice. -->
  <div style="position:absolute;inset:0;
       background:linear-gradient(115deg,rgba(9,20,34,.80) 0%,rgba(9,20,34,.42) 38%,rgba(9,20,34,0) 68%)"></div>
  <div style="position:absolute;left:0;right:0;bottom:0;height:34%;
       background:linear-gradient(to top,rgba(9,20,34,.80),rgba(9,20,34,0))"></div>

  <div style="position:absolute;left:0;top:0;width:10px;height:100%;background:#0ea5e9"></div>

  <div style="position:absolute;left:62px;top:78px">
    <div style="font-size:25px;letter-spacing:6px;font-weight:800;color:#0ea5e9;text-transform:uppercase">
      Live Q&amp;A
    </div>
    <div style="font-size:168px;line-height:.9;font-weight:900;color:#fff;margin-top:6px;
                letter-spacing:-6px">__NUMBER__</div>
  </div>

  <!-- Bottom LEFT: the gallery card puts its duration badge bottom right and
       was clipping the wordmark. -->
  <div style="position:absolute;left:62px;bottom:52px;font-size:26px;font-weight:800;
              letter-spacing:1px;color:#fff;opacity:.94">
    TRICK<span style="color:#0ea5e9">TIONARY</span>
  </div>
</div></body></html>"""


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--number", type=int, required=True, help="session number, e.g. 2")
    ap.add_argument("--date", required=True, help="YYYY-MM-DD")
    ap.add_argument("--minutes", type=int, required=True)
    ap.add_argument("--title", default="", help="override the headline")
    ap.add_argument("--bg", default="", help=f"one of {', '.join(BACKGROUNDS)}")
    ap.add_argument("--out", default="", help="override the output path")
    args = ap.parse_args()

    y, m, d = (int(x) for x in args.date.split("-"))
    pretty = _date(y, m, d).strftime("%-d %B %Y")
    bg_key = args.bg or ROTATION[(args.number - 1) % len(ROTATION)]
    if bg_key not in BACKGROUNDS:
        sys.exit(f"unknown --bg {bg_key!r}; pick one of {', '.join(BACKGROUNDS)}")
    bg = BG_DIR / BACKGROUNDS[bg_key]
    if not bg.exists():
        sys.exit(f"missing background photo: {bg}")

    html = TEMPLATE.replace("__NUMBER__", f"#{args.number}")

    work = pathlib.Path(tempfile.mkdtemp(prefix="qa-poster-"))
    (work / "poster.html").write_text(html, encoding="utf-8")
    (work / "bg.jpg").write_bytes(bg.read_bytes())

    png = work / "poster.png"
    subprocess.run(
        ["chromium", "--headless", "--disable-gpu", "--no-sandbox", "--hide-scrollbars",
         "--force-device-scale-factor=1", "--window-size=1280,720",
         f"--screenshot={png}", f"file://{work / 'poster.html'}"],
        check=True, capture_output=True)
    if not png.exists():
        sys.exit("chromium produced no image")

    from PIL import Image
    im = Image.open(png)
    if im.size != (1280, 720):
        sys.exit(f"rendered at {im.size}, expected (1280, 720)")

    out = pathlib.Path(args.out) if args.out else OUT_DIR / f"qa-{args.date}-poster.jpg"
    out.parent.mkdir(parents=True, exist_ok=True)
    im.convert("RGB").save(out, quality=88, optimize=True)
    print(f"  {out}")
    print(f"  {im.size[0]}x{im.size[1]}  {out.stat().st_size // 1024} KB  (photo: {bg_key})")
    print(f'\n  add to the session data file:\n    "poster": "/live-qa/replay/img/{out.name}"')


if __name__ == "__main__":
    main()
