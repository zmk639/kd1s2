<?php
/**
 * Instagram Auto Story Viewer
 * ===========================
 * Mass-views stories of followers of target accounts.
 * Supports: 2FA (SMS/Email/TOTP), proxy, speed control, challenge flow.
 *
 * Requirements: PHP 7.4+, Composer, mgp25/instagram-php
 *
 * Usage:
 *   php kd1s.php
 */

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

// ─── Banner ──────────────────────────────────────────────────────────────────

echo PHP_EOL;
echo " ╔══════════════════════════════════════════════════╗" . PHP_EOL;
echo " ║        Instagram Auto Story Viewer               ║" . PHP_EOL;
echo " ║          Mass Story Views — kd1s Tool            ║" . PHP_EOL;
echo " ╚══════════════════════════════════════════════════╝" . PHP_EOL;
echo " Version : 10.0" . PHP_EOL;
echo " GitHub  : https://github.com/zmk639/kd1s2" . PHP_EOL;
echo PHP_EOL;

$ig = new \InstagramAPI\Instagram(false, false);
run($ig);

// ─── Main entry ──────────────────────────────────────────────────────────────

function run(\InstagramAPI\Instagram $ig): void
{
    try {
        // ── Credentials ──────────────────────────────────────────────────────
        output('Enter your Instagram login credentials.');

        $login    = promptRequired('Username');
        $password = promptRequired('Password');

        // ── Proxy (optional) ─────────────────────────────────────────────────
        output('(Optional) Set a proxy. Format: http://ip:port  or  http://user:pass@ip:port');
        output('Press Enter to skip.');

        $proxy = promptOptional('Proxy');

        if ($proxy !== '' && $proxy !== '3') {
            if (!isValidProxy($proxy)) {
                output('Proxy validation failed — continuing without proxy.');
                $proxy = '';
            } else {
                output('Proxy OK.');
                $ig->setProxy($proxy);
            }
        } else {
            $proxy = '';
        }

        // ── Speed ─────────────────────────────────────────────────────────────
        output('Set masslooking speed (1–500000 stories/day). Enter 0 for maximum speed.');
        $speed = (int) promptRequired('Speed (0 = max)');

        if ($speed < 0 || $speed > 500000) {
            output('Invalid speed. Defaulting to maximum.');
            $speed = 0;
        }

        $delay = ($speed === 0) ? 34 : (int) round(60 * 60 * 24 * 200 / $speed);
        output($speed === 0 ? 'Maximum speed enabled.' : "Speed set to {$speed} stories/day.");

        // ── Login ─────────────────────────────────────────────────────────────
        $ig = loginWithRetry($ig, $login, $password, $proxy);

        // ── Targets ───────────────────────────────────────────────────────────
        $data = defineTargets($ig);

        // ── Masslooking ───────────────────────────────────────────────────────
        masslooking($data, $ig, $delay);

    } catch (\Exception $e) {
        $msg = $e->getMessage();
        output('ERROR: ' . $msg);

        // Fatal errors — stop immediately, no restart
        $fatal = [
            'Incorrect password',
            'Username not found',
            'Account disabled',
            'Checkpoint required',
            'Instagram Terms',
            'Account flagged',
        ];
        foreach ($fatal as $f) {
            if (stripos($msg, $f) !== false) {
                output('Fatal error — stopping. Fix the issue and restart manually.');
                exit(1);
            }
        }

        output('Restarting in 10s...');
        sleep(10);
        run($ig);
    }
}

// ─── Login with retry ────────────────────────────────────────────────────────

function loginWithRetry(
    \InstagramAPI\Instagram $ig,
    string $login,
    string $password,
    string $proxy,
    int    $maxAttempts = 10
): \InstagramAPI\Instagram {

    $failMsg = 'Could not connect to Instagram after ' . $maxAttempts . ' attempts. Please try again later.';

    for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
        try {
            if ($attempt === 0) {
                output('Emulating Instagram app — logging in as @' . $login . ' ...');
            }

            $loginResp = $ig->login($login, $password);

            // ── Two-Factor Auth ───────────────────────────────────────────────
            if ($loginResp !== null && $loginResp->isTwoFactorRequired()) {
                $ig = handleTwoFactor($ig, $login, $password, $loginResp, $proxy, $maxAttempts);
            }

            output('Logged in as @' . $login . ' successfully.');
            return $ig;

        } catch (\InstagramAPI\Exception\NetworkException $e) {
            output('Network error — retrying in 7s...');
            sleep(7);
        } catch (\InstagramAPI\Exception\EmptyResponseException $e) {
            output('Empty response — retrying in 7s...');
            sleep(7);
        } catch (\InstagramAPI\Exception\CheckpointRequiredException $e) {
            throw new \Exception('Checkpoint required. Open Instagram app and complete the checkpoint, then restart.');
        } catch (\InstagramAPI\Exception\ChallengeRequiredException $e) {
            $ig = handleChallenge($ig, $login, $password, $e, $proxy, $maxAttempts);
            output('Logged in as @' . $login . ' successfully (after challenge).');
            return $ig;
        } catch (\InstagramAPI\Exception\AccountDisabledException $e) {
            throw new \Exception('Account disabled. Visit Instagram to restore it.');
        } catch (\InstagramAPI\Exception\ConsentRequiredException $e) {
            throw new \Exception('Instagram Terms updated. Accept them in the app then restart.');
        } catch (\InstagramAPI\Exception\SentryBlockException $e) {
            throw new \Exception('Account flagged for spam. Try again from a different IP / use a proxy.');
        } catch (\InstagramAPI\Exception\IncorrectPasswordException $e) {
            throw new \Exception('Incorrect password. Please check and restart.');
        } catch (\InstagramAPI\Exception\InvalidUserException $e) {
            throw new \Exception('Username not found. Please check and restart.');
        } catch (\Exception $e) {
            throw $e;
        }
    }

    throw new \Exception($failMsg);
}

// ─── Two-Factor handler ──────────────────────────────────────────────────────

function handleTwoFactor(
    \InstagramAPI\Instagram $ig,
    string $login,
    string $password,
    $loginResp,
    string $proxy,
    int    $maxAttempts
): \InstagramAPI\Instagram {

    $twoFactorInfo = $loginResp->getTwoFactorInfo();
    $twoFactorId   = $twoFactorInfo->getTwoFactorIdentifier();

    // Detect TOTP vs SMS
    $respArray  = json_decode(json_encode($loginResp), true);
    $isTOTP     = !empty($respArray['two_factor_info']['totp_two_factor_on']);
    $method     = $isTOTP ? '3' : '1';

    if ($isTOTP) {
        output('2FA required — enter the code from your Authenticator app.');
    } else {
        output('2FA required — enter the code sent to: ' . $twoFactorInfo->getObfuscatedPhoneNumber());
    }

    $code = promptRequired('Two-factor code');

    for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
        try {
            output('Verifying 2FA...');
            $ig->finishTwoFactorLogin($login, $password, $twoFactorId, $code, $method);
            return $ig;
        } catch (\InstagramAPI\Exception\InvalidSmsCodeException $e) {
            output('Code incorrect. Try again.');
            $code = promptRequired('Two-factor code');
            $attempt = 0; // reset so we don't hit maxAttempts on bad codes
        } catch (\InstagramAPI\Exception\NetworkException $e) {
            sleep(7);
        } catch (\InstagramAPI\Exception\EmptyResponseException $e) {
            sleep(7);
        } catch (\Exception $e) {
            throw $e;
        }
    }

    throw new \Exception('2FA verification failed after multiple attempts.');
}

// ─── Challenge handler ───────────────────────────────────────────────────────

function handleChallenge(
    \InstagramAPI\Instagram $ig,
    string $login,
    string $password,
    \InstagramAPI\Exception\ChallengeRequiredException $e,
    string $proxy,
    int    $maxAttempts
): \InstagramAPI\Instagram {

    if (!$e->hasResponse() || !$e->getResponse()->isChallenge()) {
        throw new \Exception('Unexpected challenge response. Please try again.');
    }

    $challenge = $e->getResponse()->getChallenge();
    $apiPath   = is_array($challenge) ? $challenge['api_path'] : $challenge->getApiPath();

    output('Instagram requires identity verification.');
    output('1 - Email');
    output('2 - SMS');
    output('3 - Exit');

    $choice = promptChoice('Choice', ['1', '2', '3']);

    if ($choice === '3') {
        throw new \Exception('User exited during challenge.');
    }

    $challengeChoice = ($choice === '1') ? 1 : 0;

    // Send verification code
    for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
        try {
            $challengeResp = $ig->sendChallangeCode($apiPath, $challengeChoice);

            // Fallback to SMS if email fails
            if ($challengeResp->status !== 'ok') {
                $challengeChoice = 0;
                sleep(7);
                $challengeResp = $ig->sendChallangeCode($apiPath, $challengeChoice);
            }
            break;
        } catch (\InstagramAPI\Exception\NetworkException $ex) {
            sleep(7);
        } catch (\InstagramAPI\Exception\EmptyResponseException $ex) {
            sleep(7);
        } catch (\Exception $ex) {
            throw $ex;
        }
    }

    if (isset($challengeResp) && $challengeResp->status !== 'ok') {
        if (isset($challengeResp->message) && $challengeResp->message === 'This field is required.') {
            output('Instagram already sent you a code. Enter it below.');
        } else {
            throw new \Exception('Could not send verification code. Check if account has a phone number attached.');
        }
    }

    if (isset($challengeResp) && isset($challengeResp->step_data->contact_point)) {
        $cp = $challengeResp->step_data->contact_point;
        output(($choice === '2') ? "Enter code sent to number ending in: {$cp}" : "Enter 6-digit code sent to email: {$cp}");
    }

    output("Type 3 to exit.");
    $securityCode = promptRequired('Security code');

    if ($securityCode === '3') {
        throw new \Exception('User exited during challenge.');
    }

    // Finish challenge
    $ig = finishChallenge($ig, $login, $password, $apiPath, $securityCode, $proxy, $maxAttempts);
    return $ig;
}

// ─── Finish challenge ────────────────────────────────────────────────────────

function finishChallenge(
    \InstagramAPI\Instagram $ig,
    string $login,
    string $password,
    string $apiPath,
    string $securityCode,
    string $proxy,
    int    $maxAttempts
): \InstagramAPI\Instagram {

    $failMsg = 'Could not complete challenge after ' . $maxAttempts . ' attempts.';

    for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
        try {
            output('Submitting verification code...');
            $ig->finishChallengeLogin($login, $password, $apiPath, $securityCode);
            return $ig;
        } catch (\InstagramAPI\Exception\NetworkException $e) {
            sleep(7);
        } catch (\InstagramAPI\Exception\EmptyResponseException $e) {
            sleep(7);
        } catch (\InstagramAPI\Exception\InstagramException $e) {
            $msg = $e->hasResponse()
                ? $e->getResponse()->getMessage()
                : trim(ltrim(explode(':', $e->getMessage(), 2)[1] ?? $e->getMessage()));
            output('Challenge error: ' . $msg);
            output('Type 3 to exit.');
            $securityCode = promptRequired('Security code');
            if ($securityCode === '3') {
                throw new \Exception('User exited during challenge.');
            }
        } catch (\Exception $e) {
            // Instagram may require full re-login after challenge
            if (str_contains($e->getMessage(), 'Invalid Login Response')) {
                sleep(7);
                return loginWithRetry($ig, $login, $password, $proxy, $maxAttempts);
            }
            throw $e;
        }
    }

    throw new \Exception($failMsg);
}

// ─── Define targets ──────────────────────────────────────────────────────────

function defineTargets(\InstagramAPI\Instagram $ig): array
{
    $failMsg = 'Connection to Instagram lost. Restarting...';

    while (true) {
        output('Enter target Instagram usernames separated by commas (without @).');
        output('Example: cristiano, kyliejenner, leomessi');

        $raw     = promptRequired('Usernames');
        $raw     = str_replace(' ', '', $raw);
        $targets = array_unique(array_filter(explode(',', trim($raw))));

        if (empty($targets)) {
            output('No usernames provided. Please try again.');
            continue;
        }

        $data = [];

        foreach ($targets as $target) {
            $resolved = false;

            for ($attempt = 0; $attempt < 10; $attempt++) {
                try {
                    $userId = $ig->people->getUserIdForName($target);
                    output('@' . $target . ' — OK (pk: ' . $userId . ')');
                    $data[] = ['username' => $target, 'pk' => $userId];
                    $resolved = true;
                    sleep(1);
                    break;
                } catch (\InstagramAPI\Exception\NetworkException $e) {
                    sleep(7);
                } catch (\InstagramAPI\Exception\EmptyResponseException $e) {
                    sleep(7);
                } catch (\InstagramAPI\Exception\NotFoundException $e) {
                    output('@' . $target . ' not found or has blocked you. Skipping.');
                    $resolved = true; // skip it, don't retry
                    break;
                } catch (\InstagramAPI\Exception\ChallengeRequiredException $e) {
                    output('Challenge required. Restarting...');
                    throw new \Exception('Challenge during target resolution.');
                } catch (\InstagramAPI\Exception\ThrottledException $e) {
                    output('Rate-limited by Instagram. Waiting 60s...');
                    sleep(60);
                } catch (\Exception $e) {
                    output('Error for @' . $target . ': ' . $e->getMessage());
                    $resolved = true;
                    break;
                }
            }

            if (!$resolved) {
                output('Could not resolve @' . $target . ' after 10 attempts. Skipping.');
            }
        }

        if (empty($data)) {
            output('No valid targets found. Please try again.');
            continue;
        }

        $names = implode(', @', array_column($data, 'username'));
        output('Selected ' . count($data) . ' target(s): @' . $names);
        output('1 - Confirm and start');
        output('2 - Re-enter targets');
        output('3 - Exit');

        $confirm = promptChoice('Choice', ['1', '2', '3']);

        if ($confirm === '3') {
            throw new \Exception('User exited.');
        } elseif ($confirm === '2') {
            continue;
        }

        return $data;
    }
}

// ─── Masslooking loop ────────────────────────────────────────────────────────

function masslooking(array $originalData, \InstagramAPI\Instagram $ig, int $delay): void
{
    $viewCount    = 0;
    $stCount      = 0;
    $stories      = [];
    $counter1     = 0;  // getReelsMediaFeed calls
    $counter2     = 0;  // markMediaSeen calls
    $beginMs      = time();
    $beginSpeed   = time();
    $delitel      = 0;

    output('Masslooking started.');

    $data = $originalData;

    // Attach pagination state to each target
    foreach ($data as $i => $d) {
        $data[$i]['rank_token'] = \InstagramAPI\Signatures::generateUUID();
        $data[$i]['max_id']     = null;
        $data[$i]['users_count'] = 0;
        $data[$i]['last_gf']    = 0;
    }

    // Continuous loop — iterates through each target's followers pages
    do {
        foreach ($data as $key => $d) {
            try {
                // Minimum 7s between follower page fetches per target
                $elapsed = time() - $d['last_gf'];
                if ($d['last_gf'] > 0 && $elapsed < 7) {
                    sleep(7 - $elapsed);
                }

                $followers = $ig->people->getFollowers(
                    $d['pk'],
                    $d['rank_token'],
                    null,
                    $d['max_id'] ?: null
                );

                $data[$key]['last_gf'] = time();

                if (empty($followers->getUsers())) {
                    output('@' . $d['username'] . ' has no followers. Removing from targets.');
                    unset($data[$key]);
                    continue;
                }

                $data[$key]['max_id']      = $followers->getNextMaxId();
                $followerIds               = array_map(
                    fn($u) => $u->getPk(),
                    $followers->getUsers()
                );

                $data[$key]['users_count'] += count($followerIds);
                $isFirstPage               = ($d['max_id'] === null);

                if ($isFirstPage) {
                    output(count($followerIds) . ' followers of @' . $d['username'] . ' collected.');
                } else {
                    output('Next ' . count($followerIds) . ' followers of @' . $d['username'] . ' collected. Total: ' .
                        number_format($data[$key]['users_count']) . '.');
                }

                // Process followers in batches of 13 (Instagram API limit for reels feed)
                $batches = array_chunk($followerIds, 13);

                foreach ($batches as $batchIndex => $batch) {
                    try {
                        $reelsResp = $ig->story->getReelsMediaFeed($batch);
                        $counter1++;

                        if ($reelsResp->isOk() && count($reelsResp->getReels()->getData()) > 0) {
                            foreach ($reelsResp->getReels()->getData() as $reel) {
                                foreach ($reel->getItems() as $item) {
                                    if (!$item->getId()) continue;
                                    $stories[] = $item;
                                    $stCount++;
                                    $viewCount++;
                                }
                            }

                            // Speed stats every 5 minutes
                            if (time() - $beginSpeed > 299) {
                                $beginSpeed = time();
                                $delitel++;
                                $estSpeed = (int)($viewCount * 12 * 24 / $delitel);
                                output('Estimated speed: ' . number_format($estSpeed) . ' stories/day.');
                            }

                            output($stCount . ' stories queued.');

                            // Flush batch of 200+ stories
                            if ($stCount >= 200) {
                                flushStories($ig, $stories, $stCount, $viewCount, $counter2, $delay, $beginMs);
                                $stories  = [];
                                $stCount  = 0;
                                $beginMs  = time();
                                $counter2++;
                            }
                        }

                        // Final flush when we've processed all followers for this page
                        $isLastBatch = ($batchIndex === count($batches) - 1);
                        if ($isLastBatch && $data[$key]['max_id'] === null && $stCount > 0) {
                            flushStories($ig, $stories, $stCount, $viewCount, $counter2, $delay, $beginMs);
                            $stories  = [];
                            $stCount  = 0;
                            $beginMs  = time();
                            $counter2++;
                        }

                    } catch (\InstagramAPI\Exception\NetworkException $e) {
                        output('Network error fetching stories — retrying...');
                        sleep(7);
                    } catch (\InstagramAPI\Exception\EmptyResponseException $e) {
                        output('Empty response — retrying...');
                        sleep(7);
                    } catch (\InstagramAPI\Exception\LoginRequiredException $e) {
                        output('Login required. Please restart.');
                        throw $e;
                    } catch (\InstagramAPI\Exception\ThrottledException $e) {
                        output('Rate-limited. Waiting 12 hours...');
                        sleep(43200);
                    } catch (\Exception $e) {
                        output('Story error: ' . $e->getMessage() . ' — retrying in 7s...');
                        sleep(7);
                    }
                }

                // If we've finished all pages for this target, remove it
                if ($data[$key]['max_id'] === null) {
                    output('All followers of @' . $d['username'] . ' have been processed.');
                    unset($data[$key]);
                }

            } catch (\InstagramAPI\Exception\NetworkException $e) {
                output('Network error — retrying...');
                sleep(7);
            } catch (\InstagramAPI\Exception\EmptyResponseException $e) {
                output('Empty response — retrying...');
                sleep(7);
            } catch (\InstagramAPI\Exception\ThrottledException $e) {
                output('Rate-limited. Waiting 12 hours. Take a break.');
                sleep(43200);
            } catch (\Exception $e) {
                output('Error: ' . $e->getMessage());
                sleep(7);
            }
        }

    } while (!empty($data));

    output('All stories for selected targets seen.');
    output('Total stories seen this session: ' . number_format($viewCount) . '.');
    output('Restarting masslooking loop...');
    echo PHP_EOL;

    // Restart with original targets
    masslooking($originalData, $ig, $delay);
}

// ─── Flush (mark as seen) ────────────────────────────────────────────────────

function flushStories(
    \InstagramAPI\Instagram $ig,
    array $stories,
    int   $stCount,
    int   $viewCount,
    int   $counter2,
    int   $delay,
    int   $beginMs
): void {

    // Respect delay between markMediaSeen calls
    $elapsed = time() - $beginMs;
    if ($elapsed < $delay) {
        $wait = $delay - $elapsed;
        output("Waiting {$wait}s before marking as seen (rate-limit protection)...");
        sleep($wait);
    }

    try {
        $ig->story->markMediaSeen($stories);
        output(number_format($stCount) . ' stories marked as seen. Total this session: ' . number_format($viewCount) . '.');
    } catch (\Exception $e) {
        output('markMediaSeen error: ' . $e->getMessage());
    }
}

// ─── Proxy validation ────────────────────────────────────────────────────────

function isValidProxy(string $proxy): bool
{
    output('Validating proxy...');
    try {
        $client = new \GuzzleHttp\Client(['timeout' => 15]);
        $res    = $client->request('GET', 'https://www.instagram.com', ['proxy' => $proxy]);
        return $res->getStatusCode() === 200;
    } catch (\Exception $e) {
        output('Proxy error: ' . $e->getMessage());
        return false;
    }
}

// ─── I/O helpers ─────────────────────────────────────────────────────────────

/**
 * Prompt user for a required (non-empty) value.
 */
function promptRequired(string $label): string
{
    do {
        echo $label . ': ';
        $val = trim((string) fgets(STDIN));
    } while ($val === '');
    return $val;
}

/**
 * Prompt user for an optional value (may be empty).
 */
function promptOptional(string $label): string
{
    echo $label . ' (Enter to skip): ';
    return trim((string) fgets(STDIN));
}

/**
 * Prompt user and enforce one of the allowed choices.
 */
function promptChoice(string $label, array $allowed): string
{
    do {
        echo $label . ' [' . implode('/', $allowed) . ']: ';
        $val = trim((string) fgets(STDIN));
        if (!in_array($val, $allowed, true)) {
            output('Invalid choice. Please type ' . implode(', ', $allowed) . '.');
        }
    } while (!in_array($val, $allowed, true));
    return $val;
}

/**
 * Timestamped console output.
 */
function output(string $message): void
{
    echo '[', date('H:i:s'), '] ', $message, PHP_EOL;
}
