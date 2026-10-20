#!/usr/bin/env python3
"""
Instagram Auto Story Viewer
============================
Features:
  - Mass story views (all followings)
  - Auto Poll reactions
  - Auto Slider reactions
  - Auto Question answers
  - Anti-block delays
  - Runs in cycles every 30 minutes

Usage:
  python3 story_viewer.py <username> <password>
  python3 story_viewer.py           (interactive)

Install:
  pip install igramscraper --break-system-packages
"""

import time
import random
import sys
from datetime import datetime

try:
    from igramscraper.instagram import Instagram
except ImportError:
    print("[!] Missing dependency. Run:")
    print("    pip install igramscraper --break-system-packages")
    sys.exit(1)

# ─── CONFIG ───────────────────────────────────────────────────────────────────
DELAY_MIN       = 3       # min seconds between story views
DELAY_MAX       = 8       # max seconds between story views
POLL_VOTE       = 0       # 0 = first option, 1 = second option
SLIDER_VALUE    = 0.75    # slider reaction value (0.0 to 1.0)
QUESTION_ANSWER = "Nice story!"
CYCLE_WAIT      = 1800    # seconds between full cycles (30 min)
MAX_FOLLOWING   = 500     # max accounts to fetch
# ──────────────────────────────────────────────────────────────────────────────


def log(msg):
    ts = datetime.now().strftime("%d-%m-%Y %H:%M:%S")
    print(f"[{ts}] {msg}", flush=True)


def banner():
    print("""
 ╔══════════════════════════════════════════╗
 ║      Instagram Auto Story Viewer         ║
 ║  Mass Views • Poll • Slider • Questions  ║
 ╚══════════════════════════════════════════╝
""")


def login(username: str, password: str) -> Instagram:
    log(f"[~] Logging in as @{username} ...")
    ig = Instagram()
    ig.with_credentials(username, password)
    try:
        ig.login(force=False, two_step_verificator=None)
        log("[+] Login successful!")
        return ig
    except Exception as e:
        log(f"[!] Login failed: {e}")
        sys.exit(1)


def get_followings(ig: Instagram, username: str) -> list:
    log("[~] Fetching following list ...")
    try:
        account   = ig.get_account(username)
        result    = ig.get_following(
            account.identifier,
            count=MAX_FOLLOWING,
            rank_token=Instagram.generate_uuid()
        )
        accounts  = result.get("accounts", [])
        log(f"[+] Found {len(accounts)} accounts to check")
        return accounts
    except Exception as e:
        log(f"[!] Could not fetch followings: {e}")
        return []


def view_user_stories(ig: Instagram, account) -> int:
    """View all stories of one account and react to interactive elements."""
    try:
        data = ig.get_stories(userids=[account.identifier])
        if not data or not data.get("items"):
            return 0

        viewed = 0
        for story in data["items"]:
            try:
                # ── View ──────────────────────────────────────────────────
                ig.mark_story_as_seen(story)
                viewed += 1
                log(f"[+] Viewed  @{account.username}  story {story.pk}")

                # ── Poll ──────────────────────────────────────────────────
                polls = getattr(story, "story_polls", None)
                if polls:
                    try:
                        poll_id = polls[0]["poll_sticker_id"]
                        ig.vote_poll(story.pk, poll_id, POLL_VOTE)
                        log(f"[~] Poll    @{account.username}")
                    except Exception:
                        pass

                # ── Slider ────────────────────────────────────────────────
                sliders = getattr(story, "story_sliders", None)
                if sliders:
                    try:
                        slider_id = sliders[0]["slider_sticker_id"]
                        ig.vote_slider(story.pk, slider_id, SLIDER_VALUE)
                        log(f"[~] Slider  @{account.username}")
                    except Exception:
                        pass

                # ── Question ──────────────────────────────────────────────
                questions = getattr(story, "story_questions", None)
                if questions:
                    try:
                        q_id = questions[0]["question_sticker_id"]
                        ig.reply_to_question(story.pk, q_id, QUESTION_ANSWER)
                        log(f"[~] Q&A     @{account.username}")
                    except Exception:
                        pass

                time.sleep(random.uniform(DELAY_MIN, DELAY_MAX))

            except Exception as e:
                log(f"[!] Story error ({account.username}): {e}")
                continue

        return viewed

    except Exception as e:
        log(f"[!] Could not get stories for @{account.username}: {e}")
        return 0


def run(username: str, password: str):
    ig    = login(username, password)
    total = 0
    cycle = 1

    while True:
        log(f"\n{'='*50}")
        log(f"  Cycle #{cycle} started")
        log(f"{'='*50}")

        accounts = get_followings(ig, username)

        if not accounts:
            log("[!] Following list empty or error — retrying in 60s ...")
            time.sleep(60)
            continue

        random.shuffle(accounts)   # randomise order to look natural

        for acc in accounts:
            total += view_user_stories(ig, acc)

        log(f"\n[+] Cycle #{cycle} complete  |  Total stories viewed so far: {total}")
        log(f"[~] Sleeping {CYCLE_WAIT // 60} minutes until next cycle ...")
        cycle += 1
        time.sleep(CYCLE_WAIT)


def main():
    banner()

    if len(sys.argv) >= 3:
        username = sys.argv[1]
        password = sys.argv[2]
    else:
        username = input("[?] Instagram username : ").strip()
        password = input("[?] Instagram password : ").strip()

    if not username or not password:
        print("[!] Username and password are required.")
        sys.exit(1)

    run(username, password)


if __name__ == "__main__":
    main()
