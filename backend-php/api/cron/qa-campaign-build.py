#!/usr/bin/env python3
"""
Build the Q&A campaign pack for the next session.

The pack at /video-coaching/static/campaign/ is what Michi actually posts from:
nine Instagram images with the date burned into the pixels, plus the caption,
the WhatsApp copy and the run sheet.

It used to be hand-copied from the previous month. On 7 September, six days
after the September call, the pack still said "Tuesday, September 1" and served
qa-sept1-*.jpg, while its own footer claimed the URL "always shows the current
session". It did not. This script makes that claim true.

    python3 qa-campaign-build.py                  # build for the next session
    python3 qa-campaign-build.py --deploy         # build and upload
    python3 qa-campaign-build.py --session 16     # build for a specific one

The date is read from the live sessions API, so there is nothing to remember to
change. Timezone is derived from the date, not assumed: the November session
falls after DST ends and is correctly labelled CET rather than CEST.
"""
import argparse
import json
import pathlib
import re
import shutil
import subprocess
import sys
import tempfile
import urllib.request
from datetime import datetime, timedelta
from zoneinfo import ZoneInfo

HERE = pathlib.Path(__file__).resolve().parent
CAMPAIGN = HERE.parents[2] / "website" / "static" / "campaign"
TEMPLATES = CAMPAIGN / "_templates"
API = "https://coaching.tricktionary.com/video-coaching/api/qa-sessions"
REMOTE = "coaching-server:/home/coaching/public_html/video-coaching/static/campaign/"

# template -> (output suffix, width, height)
IMAGES = {
    "feed-a.html":         ("feed-A-portrait", 1080, 1350),
    "feed-b.html":         ("feed-B-action",   1080, 1350),
    "feed-c.html":         ("feed-C-airborne", 1080, 1350),
    "feed-d.html":         ("feed-D-cruising", 1080, 1350),
    "story.html":          ("story",           1080, 1920),
    "story-this-tue.html": ("story-this-tue",  1080, 1920),
    "story-tomorrow.html": ("story-tomorrow",  1080, 1920),
    "story-tonight.html":  ("story-tonight",   1080, 1920),
    "story-1hour.html":    ("story-1hour",     1080, 1920),
}

DE_DAYS = {"Monday": "Montag", "Tuesday": "Dienstag", "Wednesday": "Mittwoch",
           "Thursday": "Donnerstag", "Friday": "Freitag", "Saturday": "Samstag",
           "Sunday": "Sonntag"}
DE_MONTHS = {1: "Januar", 2: "Februar", 3: "März", 4: "April", 5: "Mai", 6: "Juni",
             7: "Juli", 8: "August", 9: "September", 10: "Oktober", 11: "November",
             12: "Dezember"}


def next_session(session_id=None):
    # Cloudflare 403s the default urllib agent, so identify ourselves.
    req = urllib.request.Request(API, headers={"User-Agent": "qa-campaign-build/1.0"})
    with urllib.request.urlopen(req, timeout=20) as r:
        rows = json.load(r)
    if not rows:
        sys.exit("the sessions API returned nothing")
    if session_id:
        for row in rows:
            if int(row["id"]) == int(session_id):
                return row
        sys.exit(f"session {session_id} is not in the upcoming list")
    return rows[0]


def tokens(session):
    dt = datetime.strptime(session["scheduled_at"], "%Y-%m-%d %H:%M:%S")
    # Europe/Vienna decides CEST vs CET for us, so a winter session is labelled
    # correctly without anyone remembering that DST ended.
    tz = dt.replace(tzinfo=ZoneInfo("Europe/Vienna")).strftime("%Z")
    hour12 = dt.strftime("%I").lstrip("0")
    day = dt.strftime("%A")
    return {
        "DAY": day,
        "DAY_UPPER": day.upper(),
        "DAY_DATE": f"{day}, {dt.strftime('%B')} {dt.day}",
        "DATE_DAY": f"{dt.day} {dt.strftime('%B')}",
        "DATE_DE": f"{dt.day}. {DE_MONTHS[dt.month]}",
        "DAY_DE": DE_DAYS[day],
        "TIME": f"{hour12} PM" if dt.hour >= 12 else f"{hour12} AM",
        "TIME24": dt.strftime("%H:%M"),
        "TZ": tz,
        "SLUG": dt.strftime("%b%-d").lower(),           # oct6
        "ISO": dt.strftime("%Y-%m-%d"),
        "PRETTY": f"{dt.strftime('%b')} {dt.day}",       # Oct 6
        # run-sheet anchors, derived so they never drift from the session date
        "D_MINUS_7": (dt - timedelta(days=7)).strftime("%a %-d").upper(),
        "D_MINUS_2": (dt - timedelta(days=2)).strftime("%a %-d").upper(),
        "D_MINUS_1": (dt - timedelta(days=1)).strftime("%a %-d").upper(),
        "D_DAY": dt.strftime("%a %-d").upper(),
    }, dt


def fill(text, tok):
    for k, v in tok.items():
        text = text.replace("{{" + k + "}}", str(v))
    left = re.findall(r"\{\{[A-Z_0-9]+\}\}", text)
    if left:
        sys.exit(f"unfilled tokens: {sorted(set(left))}")
    return text


def render(tok, outdir, check_only=False):
    from PIL import Image
    work = pathlib.Path(tempfile.mkdtemp(prefix="qa-campaign-"))
    shutil.copytree(TEMPLATES / "bg", work / "bg")
    made = []
    for tmpl, (suffix, w, h) in IMAGES.items():
        src = TEMPLATES / tmpl
        (work / tmpl).write_text(fill(src.read_text(encoding="utf-8"), tok), encoding="utf-8")
        png = work / f"{suffix}.png"
        subprocess.run(
            ["chromium", "--headless", "--disable-gpu", "--no-sandbox", "--hide-scrollbars",
             "--force-device-scale-factor=1", f"--window-size={w},{h}",
             f"--screenshot={png}", f"file://{work / tmpl}"],
            check=True, capture_output=True)
        if not png.exists():
            sys.exit(f"chromium produced no image for {tmpl}")
        im = Image.open(png)
        if im.size != (w, h):
            sys.exit(f"{tmpl} rendered at {im.size}, expected {(w, h)}")
        out = outdir / f"qa-{tok['SLUG']}-{suffix}.jpg"
        if not check_only:
            im.convert("RGB").save(out, quality=90, optimize=True)
        made.append(out)
        print(f"  {out.name:<34}{im.size[0]}x{im.size[1]}"
              f"{'' if check_only else f'  {out.stat().st_size // 1024} KB'}")
    shutil.rmtree(work, ignore_errors=True)
    return made


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--session", type=int, help="build for this session id instead of the next one")
    ap.add_argument("--deploy", action="store_true", help="upload the pack to the live server")
    ap.add_argument("--check", action="store_true", help="render but write nothing")
    args = ap.parse_args()

    s = next_session(args.session)
    tok, dt = tokens(s)
    print(f"session {s['id']}  {tok['DAY_DATE']}  {tok['TIME24']} {tok['TZ']}\n")

    if not (TEMPLATES / "index.html").exists():
        sys.exit(f"missing {TEMPLATES / 'index.html'}")

    made = render(tok, CAMPAIGN, check_only=args.check)

    page = fill((TEMPLATES / "index.html").read_text(encoding="utf-8"), tok)
    newsletter = CAMPAIGN / f"newsletter-qa-{tok['SLUG']}.html"
    if not newsletter.exists():
        page = re.sub(
            r'<a href="' + re.escape(newsletter.name) + r'"[^>]*>.*?</a>',
            'Newsletter preview: save the Sendy HTML here as '
            f'<b>{newsletter.name}</b> and it appears on this page.',
            page, flags=re.S)
    if not args.check:
        (CAMPAIGN / "index.html").write_text(page, encoding="utf-8")
        print(f"\n  index.html                        {tok['DAY_DATE']}")
        # Drop images from previous sessions so the pack only ever offers the
        # current ones. This is why a stale pack was not obvious: the old
        # September jpgs sat beside a page that no longer referenced them.
        keep = {p.name for p in made}
        for old in CAMPAIGN.glob("qa-*.jpg"):
            if old.name not in keep:
                old.unlink()
                print(f"  removed stale {old.name}")

    if args.deploy and not args.check:
        files = [str(p) for p in made] + [str(CAMPAIGN / "index.html")]
        subprocess.run(["ssh", "coaching-server",
                        "rm -f /home/coaching/public_html/video-coaching/static/campaign/qa-*.jpg"],
                       check=True)
        subprocess.run(["scp", "-q", *files, REMOTE], check=True)
        print("\n  deployed: https://coaching.tricktionary.com/video-coaching/static/campaign/")
    elif not args.check:
        print("\n  not deployed. Re-run with --deploy when it looks right.")


if __name__ == "__main__":
    main()
