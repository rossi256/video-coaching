#!/usr/bin/env python3
"""
Make the replay poster and the email thumbnail for one session.

Two images come out of one frame of the recording:

  qa-<date>-poster.jpg   clean frame, used as the <video poster> on the page
  qa-<date>-thumb.jpg    same frame with a play button and the duration burned
                         in, used in the replay email

The email one needs the play button baked into the pixels: mail clients cannot
play video and most strip the CSS you would use to overlay a button, so the
image has to look like a player on its own.

    python3 qa-make-thumb.py 2026-09-01 --at 2400
    python3 qa-make-thumb.py 2026-09-01 --at 2400 --upload

--at is the second to grab. Pick a moment where Michi is facing the camera;
the first frame is usually a Zoom name card and looks broken.
"""
import argparse
import subprocess
import sys
import tempfile
from pathlib import Path

from PIL import Image, ImageDraw, ImageFont

BASE = "https://coaching.tricktionary.com/video-coaching/static/replay"
REMOTE = "coaching-server:/home/coaching/public_html/video-coaching/static/replay/"
FONTS = [
    "/usr/share/fonts/truetype/liberation2/LiberationSans-Bold.ttf",
    "/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf",
    "/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf",
]


def font(size):
    for f in FONTS:
        if Path(f).exists():
            return ImageFont.truetype(f, size)
    return ImageFont.load_default()


def grab(video_url, at, dest):
    subprocess.run(
        ["ffmpeg", "-y", "-loglevel", "error", "-ss", str(at), "-i", video_url,
         "-frames:v", "1", "-vf", "scale=1280:-2", "-q:v", "2", str(dest)],
        check=True)
    if not dest.exists() or dest.stat().st_size == 0:
        sys.exit(f"could not grab a frame at {at}s")


def make_thumb(poster, out, duration):
    im = Image.open(poster).convert("RGB")
    w, h = im.size
    d = ImageDraw.Draw(im, "RGBA")

    # Darken slightly so a white play button reads on any frame.
    d.rectangle([0, 0, w, h], fill=(0, 0, 0, 60))

    # Play button: white disc, dark triangle.
    r = int(h * 0.13)
    cx, cy = w // 2, h // 2
    d.ellipse([cx - r, cy - r, cx + r, cy + r], fill=(255, 255, 255, 235))
    t = int(r * 0.46)
    d.polygon([(cx - t * 0.55 + t * 0.25, cy - t),
               (cx - t * 0.55 + t * 0.25, cy + t),
               (cx + t, cy)], fill=(13, 27, 46, 255))

    # Duration pill, bottom right, the way a player shows it.
    if duration:
        f = font(int(h * 0.045))
        pad = int(h * 0.022)
        box = d.textbbox((0, 0), duration, font=f)
        tw, th = box[2] - box[0], box[3] - box[1]
        x1, y1 = w - tw - pad * 3, h - th - pad * 3
        d.rounded_rectangle([x1, y1, w - pad, h - pad], radius=int(pad * 0.7),
                            fill=(0, 0, 0, 175))
        d.text((x1 + pad, y1 + pad - box[1]), duration, font=f, fill=(255, 255, 255, 255))

    im.save(out, quality=88, optimize=True)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("date", help="session date, YYYY-MM-DD")
    ap.add_argument("--at", type=int, default=2400, help="second to grab (default 2400)")
    ap.add_argument("--duration", default="", help='overlay text, e.g. "1:16:23"')
    ap.add_argument("--upload", action="store_true", help="scp both to the server")
    args = ap.parse_args()

    video = f"{BASE}/qa-{args.date}.mp4"
    tmp = Path(tempfile.mkdtemp())
    poster = tmp / f"qa-{args.date}-poster.jpg"
    thumb = tmp / f"qa-{args.date}-thumb.jpg"

    print(f"  grabbing {args.at}s from {video}")
    grab(video, args.at, poster)
    make_thumb(poster, thumb, args.duration)
    for p in (poster, thumb):
        print(f"  {p.name:<30}{p.stat().st_size // 1024} KB")

    if args.upload:
        subprocess.run(["scp", "-q", str(poster), str(thumb), REMOTE], check=True)
        print("\n  uploaded:")
        for p in (poster, thumb):
            print(f"    {BASE}/{p.name}")
        print(f"\n  add to the session data file:\n    \"poster\": \"{BASE}/{poster.name}\"")
    else:
        print(f"\n  not uploaded. Re-run with --upload when the frame looks right.")
        print(f"  local: {tmp}")


if __name__ == "__main__":
    main()
