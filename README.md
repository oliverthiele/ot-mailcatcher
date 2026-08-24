# Mailcatcher — capture outgoing TYPO3 mails and check them before they go out

Captures every outgoing mail as a file instead of sending it, shows the result in
a backend module, and reports the usual mail configuration mistakes in wording an
editor can act on.

[![TYPO3](https://img.shields.io/badge/TYPO3-13.4%20%7C%2014.3-orange.svg)](https://typo3.org/)
[![Packagist Version](https://img.shields.io/packagist/v/oliverthiele/ot-mailcatcher.svg)](https://packagist.org/packages/oliverthiele/ot-mailcatcher)
[![PHP](https://img.shields.io/packagist/dependency-v/oliverthiele/ot-mailcatcher/php.svg)](https://php.net/)
[![License](https://img.shields.io/packagist/l/oliverthiele/ot-mailcatcher.svg)](LICENSE)
[![Changelog](https://img.shields.io/badge/Changelog-CHANGELOG.md-blue.svg)](CHANGELOG.md)

## Features

- **Nothing leaves the machine.** Mails are written to `var/mailcatcher/` as `.eml`
  files. There is no transport configured that could deliver them.
- **One file per mail.** TYPO3's own `mbox` transport appends every message to a
  single file without a separator line, which leaves no reliable boundary to split
  them again — two mails sent within the same request then cannot be told apart.
  A form finisher sending a receiver notification and a sender confirmation hits
  exactly that case.
- **Rules in plain language.** Ten rules report the usual mistakes, each with one
  sentence stating the problem and one stating what correct looks like.
- **The same rules in CI.** A token-protected HTTP API returns the findings as
  stable identifiers, so an end-to-end test can assert on them.
- **Impossible to forget.** While the catcher is active it says so in the system
  information toolbar, in the Reports module, and in a banner on every backend page.
- **It never claims more than it can deliver.** The switch in the backend module and
  the actual capturing are two different things — the latter needs one block in
  `additional.php`. If that block is missing, the extension says so instead of
  reporting that no mail is being sent.
- **Locked out of Production** unless explicitly allowed, so an administrator
  cannot silence a live site by accident.
- **Nothing escapes while it is on.** A process that may not capture refuses to
  send rather than falling back to real delivery.
- **Captured mail can still be delivered.** Delete the test and debug mails, then
  send what is left to the original recipients.

## Requirements

| | |
|---|---|
| TYPO3 | 13.4 LTS, 14.3 LTS |
| PHP | 8.2 or newer |

## Installation

```bash
composer require oliverthiele/ot-mailcatcher
```

Then add the transport switch at the **end** of `config/system/additional.php` —
after any block that rewrites the `MAIL` array:

```php
use OliverThiele\OtMailcatcher\Mail\FileTransport;
use OliverThiele\OtMailcatcher\Service\MailcatcherState;

if (class_exists(MailcatcherState::class) && MailcatcherState::isActive()) {
    $GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport'] = FileTransport::class;
}
```

**Why both this block and the extension's own wiring?** They cover different
bootstraps, and neither covers everything:

| | `ext_localconf.php` (in the extension) | `additional.php` (this block) |
|---|---|---|
| Normal frontend, backend, CLI | yes | yes |
| After project code rewrites `MAIL` | yes — loads later | depends on placement |
| Reduced bootstrap, e.g. the install tool's mail test | **no** | yes |

The install tool's mail test under **Environment** calls
`BootService::getContainer()` without loading extension configuration, so
`ext_localconf.php` never runs there and the mail would be delivered for real.
`config/system/additional.php` is read by every bootstrap and closes that gap.

If the block is missing, the backend says so: normal mail is still captured
through the extension's own wiring, and the module reports that reduced
bootstraps are not covered.

While the catcher is switched on, **no mail leaves the system, on any process**.
Where a process may not run the catcher — a Production context without
`MAILCATCHER_ALLOWED=1`, which the command line resolves even when the web server
sets a development context — mail is **refused with an exception** rather than
delivered. Loud beats silently wrong: the alternative is a scheduler task
delivering a bulk send to real recipients while the backend reports that nothing
is being sent.

## Configuration

Two environment variables, both optional:

| Variable | Effect |
|---|---|
| `MAILCATCHER_ALLOWED` | `1` permits the catcher in the `Production` context. Without it, Production refuses to switch on — a forgotten catcher there stops all mail silently. Only the literal `1` unlocks; `true` or `yes` do nothing. |
| `MAILCATCHER_API_TOKEN` | Enables the test API. While empty the route answers `404` and stays completely closed. Generate one with `openssl rand -hex 32`. |

Both are validated. The backend module and the Reports module report an
unlocked Production context, a `MAILCATCHER_ALLOWED` value that is silently
ignored, an API token on a Production system, and a token short enough to guess.

**The command line does not inherit the web server's context.** Where a web
server sets `TYPO3_CONTEXT=Development` through `fastcgi_param`, `SetEnv` or
similar, CLI runs still default to `Production` — and there the catcher stays
locked unless `MAILCATCHER_ALLOWED=1` is set in the `.env` loaded for that
context. Mail from a console command or a scheduler task is then delivered for
real while the backend reports that nothing is being sent. If console runs should
be captured too, set the variable; the extension reports the gap as
`allowedMissing` either way.

Switch the catcher on and off in **System → Mailcatcher**. The state lives in
`var/mailcatcher/state.json`, not in `settings.php`, which is version-controlled in
most projects and rewritten by TYPO3 on its own.

## Usage

### Backend module

**System → Mailcatcher** lists the captured mails with a finding count, and shows
headers, findings, HTML, plain text, source and attachments per mail. The HTML part
is served through its own route into a sandboxed iframe, so foreign mail content
never shares the backend document.

### Rules

| Identifier | Severity |
|---|---|
| `senderIsWebsiteVisitor` | error |
| `unresolvedTypo3Link` | error |
| `leftoverPlaceholder` | error |
| `emptySubject` | error |
| `missingReplyTo` | warning |
| `missingTextPart` | warning |
| `relativeLink` | warning |
| `insecureLink` | warning |
| `brokenEncoding` | warning |
| `recipientEqualsSender` | hint |

Add a project-specific rule by implementing `MailCheckInterface` — it is picked up
through the `ot_mailcatcher.check` service tag, no change to this package needed.

### Test API

Requires `MAILCATCHER_API_TOKEN` and an active catcher. Every request carries the
token in the `X-Mailcatcher-Token` header.

| Endpoint | Purpose |
|---|---|
| `GET /_mailcatcher/api/messages` | List, optionally filtered by `to` and `subject` |
| `GET /_mailcatcher/api/messages/{identifier}` | One mail including text, HTML and attachment metadata |
| `DELETE /_mailcatcher/api/messages` | Remove all captured mails |

```bash
curl -H "X-Mailcatcher-Token: $MAILCATCHER_API_TOKEN" \
     https://example.ddev.site/_mailcatcher/api/messages
```

### After a live incident

Switching the catcher on during a live incident is defensible because nothing is
lost. Getting the mail out again afterwards:

1. **Switch the catcher off** — normal delivery resumes.
2. **Delete the test and debug mails** individually.
3. **Send remaining** — delivers everything still captured to its original
   recipients, after a confirmation naming the count.

Sending is refused while the catcher is still on; the mails would go straight
back into it. Delivered mails move to `var/mailcatcher/sent/` rather than being
deleted, so a delivery stays traceable and a failure never destroys the only copy.
Each mail keeps its original headers, so the `Date` the recipient sees is the
date it was captured.

`mailcatcher:prune` requires `--force` in a Production context: what it holds
there may be real customer mail that nobody has received yet.

### Command line

```bash
typo3 mailcatcher:testmail address@example.org   # sends a receiver/sender pair in one run
typo3 mailcatcher:prune --days=30                # deletes captured mails beyond the retention
typo3 mailcatcher:prune --days=30 --dry-run
```

`mailcatcher:testmail` deliberately sends a **pair** of mails in a single run: that
is the case a single-file catcher loses, so it doubles as the check that this one
does not.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).

## Author

Oliver Thiele — [oliver-thiele.de](https://www.oliver-thiele.de/)
