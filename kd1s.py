#!/usr/bin/env python3
"""
Instagram Auto Story Viewer — kd1s Tool
Mass-views stories of followers of target accounts.
Supports: 2FA, proxy, speed control, challenge flow.

Requirements: pip install instagrapi
Usage:        python kd1s.py
"""

import os
import sys
import time
import json
import math
import signal
from datetime import datetime
from pathlib import Path

try:
    from instagrapi import Client
    from instagrapi.exceptions import (
        LoginRequired, ChallengeRequired, TwoFactorRequired,
        BadPassword, InvalidUser, UserNotFound,
        ClientError, ClientConnectionError, ClientJSONDecodeError,
        PleaseWaitFewMinutes, RateLimitError,
    )
except ImportError:
    print("\n[ERROR] instagrapi not installed.")
    print("Run: pip install instagrapi\n")
    sys.exit(1)

# ── Constants ─────────────────────────────────────────────────────────────────

VERSION      = "11.0"
GITHUB       = "https://github.com/zmk639/kd1s2"
SESSIONS_DIR = Path(__file__).parent / "sessions"
BATCH_SIZE   = 100   # users per getReels batch (instagrapi supports larger batches)
FLUSH_AT     = 200   # queue size before marking seen
STATS_EVERY  = 300   # print speed stats every N seconds

# ── Helpers ───────────────────────────────────────────────────────────────────

def banner():
    print()
    print(" ╔══════════════════════════════════════════════════╗")
    print(" ║        Instagram Auto Story Viewer               ║")
    print(" ║          Mass Story Views — kd1s Tool            ║")
    print(" ╚══════════════════════════════════════════════════╝")
    print(f" Version : {VERSION}")
    print(f" GitHub  : {GITHUB}")
    print()

def out(msg: str):
    ts = datetime.now().strftime("%H:%M:%S")
    print(f"[{ts}] {msg}", flush=True)

def prompt_required(label: str) -> str:
    while True:
        val = input(f"{label}: ").strip()
        if val:
            return val
        print(f"  {label} cannot be empty.")

def prompt_optional(label: str) -> str:
    return input(f"{label}: ").strip()

def prompt_choice(label: str, allowed: list) -> str:
    while True:
        val = input(f"{label}: ").strip()
        if val in allowed:
            return val
        print(f"  Choose one of: {', '.join(allowed)}")

def is_valid_proxy(proxy: str) -> bool:
    return proxy.startswith("http://") or proxy.startswith("socks5://")

def session_path(username: str) -> Path:
    SESSIONS_DIR.mkdir(exist_ok=True)
    return SESSIONS_DIR / f"{username}.json"

# ── Login ─────────────────────────────────────────────────────────────────────

def build_client(proxy: str = "") -> Client:
    cl = Client()
    cl.delay_range = [1, 3]
    if proxy and is_valid_proxy(proxy):
        cl.set_proxy(proxy)
    return cl

def login(username: str, password: str, proxy: str) -> Client:
    cl = build_client(proxy)
    sess = session_path(username)

    # Try saved session first
    if sess.exists():
        out("Loading saved session...")
        try:
            cl.load_settings(sess)
            cl.login(username, password)
            cl.dump_settings(sess)
            out(f"Logged in as @{username} (session reused).")
            return cl
        except (LoginRequired, Exception):
            out("Session expired — logging in fresh...")
            cl = build_client(proxy)

    # Fresh login
    out(f"Logging in as @{username} ...")
    try:
        cl.login(username, password)
        cl.dump_settings(sess)
        out(f"Logged in as @{username} successfully.")
        return cl

    except TwoFactorRequired:
        return handle_2fa(cl, username, password, sess)

    except ChallengeRequired:
        return handle_challenge(cl, username, password, sess)

    except BadPassword:
        out("ERROR: Wrong password.")
        sys.exit(1)

    except (InvalidUser, UserNotFound):
        out("ERROR: Username not found.")
        sys.exit(1)

    except Exception as e:
        out(f"ERROR: {e}")
        sys.exit(1)

def handle_2fa(cl: Client, username: str, password: str, sess: Path) -> Client:
    out("Two-Factor Authentication required.")
    print("  [1] SMS")
    print("  [2] Email")
    print("  [3] Authenticator app (TOTP)")
    method = prompt_choice("2FA method", ["1", "2", "3"])

    if method in ("1", "2"):
        identifier = cl.two_factor_login_start(
            "sms" if method == "1" else "email"
        )
        code = prompt_required("Enter the 6-digit code you received")
        cl.two_factor_login(username, password, code, identifier)
    else:
        code = prompt_required("Enter TOTP code from your authenticator app")
        cl.two_factor_login(username, password, code)

    cl.dump_settings(sess)
    out(f"Logged in as @{username} via 2FA.")
    return cl

def handle_challenge(cl: Client, username: str, password: str, sess: Path) -> Client:
    out("Challenge required. Sending verification code...")
    try:
        cl.challenge_resolve(cl.last_json)
    except Exception:
        pass

    print("  [1] SMS")
    print("  [2] Email")
    method = prompt_choice("Verify via", ["1", "2"])
    cl.challenge_send_code(int(method))

    code = prompt_required("Enter the verification code")
    cl.challenge_login_with_code(code)
    cl.dump_settings(sess)
    out(f"Logged in as @{username} via challenge.")
    return cl

# ── Target resolution ─────────────────────────────────────────────────────────

def define_targets(cl: Client) -> list:
    while True:
        out("Enter target Instagram usernames separated by commas (without @).")
        out("Example: cristiano, kyliejenner, leomessi")
        raw     = prompt_required("Usernames")
        targets = list(set(t.strip() for t in raw.split(",") if t.strip()))

        if not targets:
            out("No usernames provided. Try again.")
            continue

        data = []
        for target in targets:
            for attempt in range(5):
                try:
                    user_id = cl.user_id_from_username(target)
                    out(f"@{target} — OK (pk: {user_id})")
                    data.append({"username": target, "pk": str(user_id)})
                    time.sleep(1)
                    break
                except (UserNotFound, InvalidUser):
                    out(f"@{target} not found or has blocked you. Skipping.")
                    break
                except PleaseWaitFewMinutes:
                    out("Rate-limited. Waiting 60s...")
                    time.sleep(60)
                except Exception as e:
                    out(f"Error for @{target}: {e}")
                    if attempt < 4:
                        time.sleep(7)
                    else:
                        out(f"Skipping @{target} after 5 failed attempts.")

        if data:
            return data

        out("No valid targets. Try again.")

# ── Masslooking ───────────────────────────────────────────────────────────────

def masslooking(data: list, cl: Client, delay: float):
    out(f"Starting masslooking — {len(data)} target(s), delay={delay:.4f}s/story.")

    story_queue  = []   # list of media_pk strings to mark seen
    view_count   = 0
    begin_time   = time.time()
    last_stat    = begin_time

    for target in data:
        username = target["username"]
        pk       = int(target["pk"])
        out(f"Processing @{username} (pk={pk}) ...")

        # Paginate followers
        follower_ids = []
        try:
            max_amount = 0  # 0 = all
            users = cl.user_followers(pk, amount=max_amount)
            follower_ids = list(users.keys())
            out(f"  Fetched {len(follower_ids)} followers.")
        except PleaseWaitFewMinutes:
            out("Rate-limited fetching followers. Waiting 60s...")
            time.sleep(60)
        except Exception as e:
            out(f"  Error fetching followers: {e}")
            continue

        # Batch fetch reels
        for i in range(0, len(follower_ids), BATCH_SIZE):
            batch = follower_ids[i:i + BATCH_SIZE]
            try:
                reels = cl.story_pk_from_highlight(batch) if False else None
                # Use proper reels fetch
                stories_map = cl.users_stories_bulk(batch)
                for uid, stories in stories_map.items():
                    for story in stories:
                        story_queue.append(story.pk)

                        # Flush when queue is large enough
                        if len(story_queue) >= FLUSH_AT:
                            view_count = flush(cl, story_queue, view_count, delay, begin_time)
                            story_queue = []

            except PleaseWaitFewMinutes:
                out("Rate-limited. Waiting 60s...")
                time.sleep(60)
            except (ClientConnectionError, ClientJSONDecodeError):
                out("Network error — retrying in 10s...")
                time.sleep(10)
            except Exception as e:
                out(f"  Batch error: {e}")
                time.sleep(5)

            # Stats every 5 min
            now = time.time()
            if now - last_stat >= STATS_EVERY:
                elapsed = now - begin_time
                rate    = view_count / (elapsed / 86400) if elapsed > 0 else 0
                out(f"  Speed: {rate:,.0f} stories/day | Total viewed: {view_count:,}")
                last_stat = now

    # Flush remainder
    if story_queue:
        view_count = flush(cl, story_queue, view_count, delay, begin_time)

    elapsed = time.time() - begin_time
    out(f"Round complete. Viewed: {view_count:,} stories in {elapsed/60:.1f} min.")

def flush(cl: Client, queue: list, view_count: int, delay: float, begin_time: float) -> int:
    try:
        cl.story_seen(queue)
        view_count += len(queue)
        out(f"  Marked {len(queue)} stories seen. Total: {view_count:,}")
        if delay > 0:
            time.sleep(delay * len(queue))
    except PleaseWaitFewMinutes:
        out("Rate-limited during flush. Waiting 60s...")
        time.sleep(60)
    except Exception as e:
        out(f"  Flush error: {e}")
    return view_count

# ── Main ──────────────────────────────────────────────────────────────────────

def main():
    banner()

    # Graceful Ctrl+C
    signal.signal(signal.SIGINT, lambda s, f: (print("\n\nStopped."), sys.exit(0)))

    # Credentials
    out("Enter your Instagram login credentials.")
    username = prompt_required("Username")
    password = prompt_required("Password")

    # Proxy
    out("(Optional) Set a proxy. Format: http://ip:port  or  socks5://ip:port")
    out("Press Enter to skip.")
    proxy_raw = prompt_optional("Proxy (Enter to skip)")
    proxy = proxy_raw if proxy_raw and is_valid_proxy(proxy_raw) else ""
    if proxy_raw and not proxy:
        out("Invalid proxy format — skipping proxy.")

    # Speed
    out("Set masslooking speed (1–500000 stories/day).")
    out("Enter 0 for maximum speed.")
    while True:
        speed_str = prompt_required("Speed (0 = max)")
        try:
            speed = int(speed_str)
            if 0 <= speed <= 500000:
                break
        except ValueError:
            pass
        out("Enter a number between 0 and 500000.")

    delay = (86400 / speed) if speed > 0 else 0
    out(f"Speed set to {speed if speed > 0 else 'MAX'} stories/day.")

    # Login
    cl = login(username, password, proxy)

    # Targets
    data = define_targets(cl)

    # Loop forever
    while True:
        try:
            masslooking(data, cl, delay)
            out("All targets done. Restarting in 30s...")
            time.sleep(30)
        except (LoginRequired, ChallengeRequired):
            out("Session lost — re-logging in...")
            cl = login(username, password, proxy)
        except (ClientConnectionError, ClientJSONDecodeError):
            out("Network error — retrying in 15s...")
            time.sleep(15)
        except Exception as e:
            out(f"ERROR: {e}")
            out("Restarting in 15s...")
            time.sleep(15)

if __name__ == "__main__":
    main()
