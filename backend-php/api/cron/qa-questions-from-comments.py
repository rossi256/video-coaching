#!/usr/bin/env python3
"""
Pull audience questions out of Instagram comments.

Every inbound comment is already stored whole in the funnel database
(events_in, kind='comments', raw JSON with the text and the username), whether
or not a keyword rule matched it. So asking people to comment "Question: ..."
needs no new funnel wiring at all: the questions are already being captured,
they just have nobody reading them.

This reads them back out for the prep list. It never writes anything.

  python3 qa-questions-from-comments.py            # since the last session
  python3 qa-questions-from-comments.py --days 30
  python3 qa-questions-from-comments.py --all      # anything question-shaped

Default matches a leading "question:" or "frage:". --all also picks up comments
that simply end in a question mark, which is noisier but catches people who
ignore the format.
"""
import argparse
import json
import os
import re
import sqlite3
import sys
from datetime import datetime, timedelta, timezone

DB = os.environ.get("INSTAGRAM_DB_PATH",
                    os.path.expanduser("~/.openclaw/workspace/instagram.db"))

# Our own accounts, so Michi's replies do not come back as audience questions.
OWN = {"wing.tricktionary", "michirossmeier", "loveallsurfallstyle"}

PREFIX = re.compile(r"^\s*(question|frage)\s*[:\-]\s*(.+)$", re.I | re.S)


def parse(raw):
    try:
        v = json.loads(raw).get("value", {})
    except Exception:
        return None
    text = (v.get("text") or "").strip()
    user = (v.get("from") or {}).get("username") or "unknown"
    if not text:
        return None
    return user, text, (v.get("media") or {}).get("id")


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--days", type=int, default=14)
    ap.add_argument("--all", action="store_true",
                    help="also include any comment ending in a question mark")
    args = ap.parse_args()

    if not os.path.exists(DB):
        sys.exit(f"funnel database not found at {DB}")

    since = (datetime.now(timezone.utc) - timedelta(days=args.days)).isoformat()
    db = sqlite3.connect(f"file:{DB}?mode=ro", uri=True)

    rows = db.execute(
        "SELECT raw, received_at FROM events_in "
        "WHERE kind = 'comments' AND received_at >= ? ORDER BY received_at",
        (since,),
    ).fetchall()

    hits, seen = [], set()
    for raw, at in rows:
        p = parse(raw)
        if not p:
            continue
        user, text, media = p
        if user in OWN:
            continue
        m = PREFIX.match(text)
        if m:
            q = m.group(2).strip()
        elif args.all and text.rstrip().endswith("?"):
            q = text.strip()
        else:
            continue
        key = (user, q.lower())
        if key in seen:
            continue
        seen.add(key)
        hits.append((at[:10], user, q, media))

    if not hits:
        print(f"No questions in the last {args.days} days."
              + ("" if args.all else "  Try --all for anything ending in '?'."))
        return

    print(f"{len(hits)} question(s) from Instagram comments, last {args.days} days\n")
    for at, user, q, media in hits:
        print(f"  {at}  @{user}")
        for line in q.splitlines():
            print(f"      {line}")
        print()


if __name__ == "__main__":
    main()
