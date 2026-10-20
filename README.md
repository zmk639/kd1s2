# kd1s — Instagram Auto Story Viewer

Mass-views stories of followers belonging to target Instagram accounts.

## Features

- Mass story views across followers of target accounts  
- Automatic speed control with delay protection  
- Full 2FA support: SMS, Email, Authenticator (TOTP)  
- Instagram challenge / checkpoint handling  
- Optional proxy support  
- Continuous loop — restarts automatically after each round  

## Requirements

- PHP 7.4 or higher  
- [Composer](https://getcomposer.org/)  
- A working Instagram account  

## Installation

```bash
git clone https://github.com/zmk639/kd1s2.git
cd kd1s2
composer install
```

## Usage

```bash
php kd1s.php
```

The script will prompt you for:

1. **Username / Password** — your Instagram account  
2. **Proxy** (optional) — `http://ip:port` or `http://user:pass@ip:port`  
3. **Speed** — stories per day (0 = maximum)  
4. **Target usernames** — comma-separated, no `@` symbol  

## Notes

- Recommended speed: 200,000–400,000 stories/day to stay within Instagram limits  
- Use a proxy from the same country as your account for best results  
- If Instagram challenges your login, complete it in the app first  

## Disclaimer

This tool is for educational and personal use only. Use responsibly and in accordance with Instagram's Terms of Service.
