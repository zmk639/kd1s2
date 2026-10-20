#!/usr/bin/env python3
"""
Instagram Auto Story Viewer
============================
Uses instagrapi - modern Instagram private API
Install: pip install instagrapi --break-system-packages
"""

import time, random, sys
from datetime import datetime

try:
    from instagrapi import Client
    from instagrapi.exceptions import (
        LoginRequired, ChallengeRequired, BadPassword, TwoFactorRequired
    )
except ImportError:
    print("[!] Run: pip install instagrapi --break-system-packages")
    sys.exit(1)

DELAY_MIN    = 4
DELAY_MAX    = 9
CYCLE_WAIT   = 1800
MAX_FOLLOWING = 500

def log(msg):
    print(f"[{datetime.now().strftime('%d-%m-%Y %H:%M:%S')}] {msg}", flush=True)

def banner():
    print("""
 ╔══════════════════════════════════════════╗
 ║      Instagram Auto Story Viewer         ║
 ║  Mass Views • Poll • Slider • Questions  ║
 ╚══════════════════════════════════════════╝
""")

def login(username, password):
    cl = Client()
    cl.delay_range = [2, 5]
    cl.bloks_versioning_id = "799db3e387d7a21a19c72918b8698b6cd28a1516ade363792c100ed45a0f9f0b"
    log(f"[~] Logging in as @{username} ...")
    try:
        cl.login(username, password)
        log("[+] Login successful!")
        return cl
    except BadPassword:
        log("[!] Wrong password — check and retry")
        sys.exit(1)
    except ChallengeRequired:
        log("[!] Challenge required — open Instagram app and verify, then retry")
        sys.exit(1)
    except TwoFactorRequired:
        code = input("[?] 2FA code: ").strip()
        cl.login(username, password, verification_code=code)
        log("[+] Login successful with 2FA!")
        return cl
    except Exception as e:
        log(f"[!] Login failed: {e}")
        sys.exit(1)

def run(username, password):
    cl    = login(username, password)
    uid   = cl.user_id_from_username(username)
    total = 0
    cycle = 1

    while True:
        log(f"\n{'='*48}\n  Cycle #{cycle}\n{'='*48}")

        try:
            followings = cl.user_following(uid, amount=MAX_FOLLOWING)
        except Exception as e:
            log(f"[!] Can't fetch followings: {e} — retry in 60s")
            time.sleep(60)
            continue

        uids = list(followings.keys())
        random.shuffle(uids)
        log(f"[+] {len(uids)} accounts to check")

        for fuid in uids:
            try:
                stories = cl.user_stories(fuid)
                if not stories:
                    continue
                uname = followings[fuid].username
                for s in stories:
                    try:
                        cl.story_seen([s.pk])
                        total += 1
                        log(f"[+] Viewed @{uname} story {s.pk}")

                        # Poll
                        for sticker in (s.story_polls or []):
                            try:
                                cl.story_vote("poll", s.pk, sticker.id, 0)
                                log(f"[~] Poll @{uname}")
                            except: pass

                        # Slider
                        for sticker in (s.story_sliders or []):
                            try:
                                cl.story_vote("slider", s.pk, sticker.id, 0.75)
                                log(f"[~] Slider @{uname}")
                            except: pass

                        # Question
                        for sticker in (s.story_questions or []):
                            try:
                                cl.story_answer(s.pk, sticker.id, "Nice!")
                                log(f"[~] Q&A @{uname}")
                            except: pass

                        time.sleep(random.uniform(DELAY_MIN, DELAY_MAX))
                    except Exception as e:
                        log(f"[!] Story err ({uname}): {e}")
            except Exception as e:
                log(f"[!] User {fuid} err: {e}")

        log(f"\n[+] Cycle #{cycle} done | Total: {total}")
        log(f"[~] Sleeping 30 min...")
        cycle += 1
        time.sleep(CYCLE_WAIT)

def main():
    banner()
    if len(sys.argv) >= 3:
        u, p = sys.argv[1], sys.argv[2]
    else:
        u = input("[?] Username: ").strip()
        p = input("[?] Password: ").strip()
    if not u or not p:
        print("[!] Username and password required")
        sys.exit(1)
    run(u, p)

if __name__ == "__main__":
    main()
