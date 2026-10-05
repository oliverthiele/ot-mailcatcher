# Mailcatcher — capture outgoing TYPO3 mails and check them before they go out

Captures every outgoing mail as a file instead of sending it, shows the result in
a backend module, and reports the usual mail configuration mistakes in wording an
editor can act on.

[![TYPO3](https://img.shields.io/badge/TYPO3-14.3-orange.svg)](https://typo3.org/)
[![Packagist Version](https://img.shields.io/packagist/v/oliverthiele/ot-mailcatcher.svg)](https://packagist.org/packages/oliverthiele/ot-mailcatcher)
[![PHP](https://img.shields.io/packagist/dependency-v/oliverthiele/ot-mailcatcher/php.svg)](https://php.net/)
[![License](https://img.shields.io/packagist/l/oliverthiele/ot-mailcatcher.svg)](LICENSE)
[![Changelog](https://img.shields.io/badge/Changelog-CHANGELOG.md-blue.svg)](CHANGELOG.md)

## Features

- **Nothing leaves the machine while it is on.** Mails are written to
  `var/mailcatcher/` as `.eml` files instead of being sent — also where
  `MAIL.dsn` or a spool is configured. A process that cannot capture refuses to
  send rather than falling back to real delivery.
- **And they are not lost.** Once the catcher is off, captured mails can be
  delivered to their original recipients, Bcc included — one at a time in the
  module, or in bulk from the command line.
- **One file per mail.** TYPO3's own `mbox` transport appends every message to a
  single file without a separator line, which leaves no reliable boundary to split
  them again — two mails sent within the same request then cannot be told apart.
  A form finisher sending a receiver notification and a sender confirmation hits
  exactly that case.
- **Rules in plain language.** Ten rules report the usual mistakes, each with one
  sentence stating the problem and one stating what correct looks like.
- **The same rules in CI.** A token-protected HTTP API returns the findings as
  stable identifiers, so an end-to-end test can assert on them.
- **A test can ask before it sends.** The API answers what the catcher's state
  actually is — including "switched on but not capturing" — so a test that
  triggers a mail can skip instead of delivering to real recipients. This is the
  answer no catcher that merely intercepts can give.
- **Impossible to forget.** While the catcher is active it says so in the system
  information toolbar, in the Reports module, and in a banner on every backend page.
- **It never claims more than it can deliver.** The switch in the backend module and
  the actual capturing are two different things — the latter needs one block in
  `additional.php`. If that block is missing, the extension says so instead of
  reporting that no mail is being sent.
- **Locked out of Production** unless explicitly allowed, so an administrator
  cannot silence a live site by accident.

---

## Requirements

| Requirement | Version |
|-------------|---------|
| TYPO3       | ^14.3   |
| PHP         | ^8.2    |

---

## Installation

```bash
composer require oliverthiele/ot-mailcatcher
```

Then add the transport switch at the **end** of `config/system/additional.php` —
after any block that rewrites the `MAIL` array:

```php
use OliverThiele\OtMailcatcher\Service\MailcatcherState;

if (class_exists(MailcatcherState::class)) {
    MailcatcherState::wireMailTransport();
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

`wireMailTransport()` does more than set the transport. TYPO3's
`TransportFactory` uses a configured `MAIL.dsn` and a `transport_spool_type`
before it looks at the transport class, so both are cleared too — a block that
only assigns the transport lets mail out for real wherever a DSN is configured.
Where the catcher is switched on but not permitted, it assigns the refusing
transport instead.

If the block is missing, or is still the older version that only assigned the
transport, the backend says so: normal mail is still captured through the
extension's own wiring, and the module reports that reduced bootstraps are not
covered.

While the catcher is switched on, **no mail sent through TYPO3's mail API leaves
the system, on any process**.
Where a process may not run the catcher — a Production context without
`MAILCATCHER_ALLOWED=1`, which the command line resolves even when the web server
sets a development context — mail is **refused with an exception** rather than
delivered. Loud beats silently wrong: the alternative is a scheduler task
delivering a bulk send to real recipients while the backend reports that nothing
is being sent.

Code that builds its own Symfony mailer transport instead of going through
TYPO3's `Mailer` is out of reach, and a long-running process — a queue worker,
a daemon — keeps the transport it built at its start until it is restarted.

---

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
context. Mail from a console command or a scheduler task is then refused with an
exception instead of being captured. If console runs should be captured too, set
the variable there.

Switch the catcher on and off in **System → Mailcatcher**. The state lives in
`var/mailcatcher/state.json`, not in `settings.php`, which is version-controlled in
most projects and rewritten by TYPO3 on its own.

---

## Usage

### On a live system

The catcher is a tool for development and staging. On a live system it is meant
for the exception: debugging an incident, or testing the forms automatically
right after a go-live while the maintenance mode is still on. It is not meant to
stay on there — every mail a visitor triggers waits on disk instead of reaching
its recipient.

### Backend module

**System → Mailcatcher** lists the captured mails with a finding count, 50 per
page, and shows headers, findings, HTML, plain text, source and attachments per mail. The HTML part
is served through its own route into a sandboxed iframe, so foreign mail content
never shares the backend document. Remote images stay blocked until you load
them for the one mail: a captured mail is often real customer mail, and its
images include tracking pixels that report who opened it, and when.

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

Requires `MAILCATCHER_API_TOKEN`; every request carries it in the
`X-Mailcatcher-Token` header. Without a configured token the routes answer 404
rather than 403 — an endpoint that does not exist reveals nothing about what it
would have guarded.

| Endpoint | Purpose |
|---|---|
| `GET /_mailcatcher/api/status` | The catcher's own state, answered whether it is on or off |
| `GET /_mailcatcher/api/messages` | List, optionally filtered by `to` (any recipient: To, Cc or Bcc) and `subject` |
| `GET /_mailcatcher/api/messages/{identifier}` | One mail including text, HTML and attachment metadata |
| `DELETE /_mailcatcher/api/messages/{identifier}` | Remove one captured mail |
| `DELETE /_mailcatcher/api/messages` | Remove all captured mails |

Deleting **all** mails is refused in a Production context: what the catcher
holds there may be real mail nobody has received yet. Deleting one mail stays
possible, so a form test after a go-live can remove exactly the mails it found
through the filters. Every answer carries `Cache-Control: no-store`,
so a cache in front of the system never keeps captured mail for the next caller.

The message routes additionally require an active catcher. `status` deliberately
does not: a route that only answers while the catcher is on could never report
the two states a caller most needs to hear — that it is off, or that it is on
but not wired up.

```bash
curl -H "X-Mailcatcher-Token: $MAILCATCHER_API_TOKEN" \
     https://example.ddev.site/_mailcatcher/api/messages
```

### Guarding a test that sends

A test that submits a form sends real mail whenever the catcher is not
capturing. On a staging system cloned from live those recipients are real
customers, so the question has to be asked *before* submitting:

```json
{
  "status": "notTakingEffect",
  "mailIsBeingSent": true,
  "enabled": true,
  "allowed": true,
  "wired": false,
  "enabledSince": "2026-08-30T18:04:12+02:00"
}
```

**`mailIsBeingSent` is the field to branch on.** The individual flags are there
so a failure message can say *why*; recombining them in the caller duplicates
logic that belongs in one place.

| `status` | Meaning | Safe for a test that sends |
|---|---|---|
| `active` | On and wired up | yes — the mail is captured |
| `notTakingEffect` | On, but the transport was never wired up | **no** — mail goes out while the backend claims otherwise |
| `locked` | On, but not permitted in this environment | **no** |
| `strayTransport` | Off, yet the transport points at the catcher | nothing is sent, but nothing is captured either |
| `inactive` | Off and not wired up | **no** — normal delivery |

Skipping is the right outcome rather than failing: a test that cannot run safely
has not found a defect.

```typescript
async function mailIsCaptured(request: APIRequestContext): Promise<boolean> {
    const response = await request.get('/_mailcatcher/api/status', {
        headers: { 'X-Mailcatcher-Token': process.env.MAILCATCHER_API_TOKEN ?? '' },
    });

    // 404 means no token configured or the wrong one — either way, do not send.
    if (!response.ok()) {
        return false;
    }

    const status = (await response.json()) as { mailIsBeingSent: boolean };

    return status.mailIsBeingSent === false;
}

test.beforeEach(async ({ request }) => {
    test.skip(!(await mailIsCaptured(request)), 'Mailcatcher is not capturing — refusing to send');
});
```

### After a live incident

Switching the catcher on during a live incident is defensible because nothing is
lost. Getting the mail out again afterwards:

1. **Switch the catcher off** — normal delivery resumes.
2. **Delete the test and debug mails** individually.
3. **Send** in a mail's row — delivers that one mail to its original recipients.

Per mail on purpose: what is captured during an incident is a mixture, and the
mails deserve to be judged separately. A three-day-old password reset belongs in
the bin; the order confirmation next to it belongs in the recipient's inbox.

For a list too long to click through, use the command line:

```bash
typo3 mailcatcher:resend --dry-run          # what would go, and to whom
typo3 mailcatcher:resend --limit=20 --force=7
```

The run reports how many recipients lie outside the site's own domain and names
them — the number that matters on a staging system cloned from live, where the
captured mail carries real customer addresses. Outside a development context that
count is also the confirmation: `--force=7` only works while seven external
recipients are pending, so it cannot be typed from memory.

A run stops after three failures in a row rather than working through the whole
list against a relay that is refusing; everything unsent stays in place.

Sending is refused while the catcher is still on, and while the mail transport
still points at it; the mails would go straight back into it. Delivered mails move to `var/mailcatcher/sent/` rather than being
deleted, so a delivery stays traceable and a failure never destroys the only copy.
Each mail keeps its original headers, so the `Date` the recipient sees is the
date it was captured; only the `X-Mailcatcher-Context` debugging header is
removed. Mails go to the recipients they were captured for: the envelope is
stored next to each mail, so Bcc recipients receive their copy too.

`mailcatcher:prune` removes delivered mails in `sent/` along with captured ones.
It requires `--force` in a Production context: what it holds there may be real
customer mail that nobody has received yet.

---

## CLI

```bash
typo3 mailcatcher:testmail address@example.org   # sends a receiver/sender pair in one run

typo3 mailcatcher:resend --dry-run               # what would go out, and to whom
typo3 mailcatcher:resend --limit=20 --force=7    # deliver, confirmed by the external count

typo3 mailcatcher:prune --days=30 --dry-run      # what the retention would remove
typo3 mailcatcher:prune --days=30 --force        # --force is required in a Production context
```

`--limit` and `--days` take whole numbers from 1 upwards; anything else is
rejected instead of being read as 0.

`mailcatcher:testmail` deliberately sends a **pair** of mails in a single run: that
is the case a single-file catcher loses, so it doubles as the check that this one
does not.

---

## License

GPL-2.0-or-later — see [LICENSE](LICENSE)

---

## Author

Oliver Thiele — [oliver-thiele.de](https://www.oliver-thiele.de/)
