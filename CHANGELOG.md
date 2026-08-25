# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.5.0] — 2026-08-25

### Removed

- **"Send remaining" is gone from the backend module.** One click for an
  unbounded, irreversible send is the wrong shape for the decision it carries: in
  a real incident the mails deserve to be judged one at a time — a three-day-old
  password reset belongs in the bin, the order confirmation next to it belongs in
  the recipient's inbox. The module keeps per-mail sending; bulk delivery moved to
  the command line, where it is deliberate, scriptable and throttleable.

### Added

- `mailcatcher:resend`, with `--limit`, `--dry-run` and `--force`.
- The run reports how many recipients lie **outside the site's own domain**, and
  names them. This is the number that matters before anything leaves: a staging
  system cloned from live holds real customer addresses, and nobody intends to
  write to them. Context alone cannot tell that apart — staging identifies as
  Production — so the addresses are shown instead of guessed at.
- Outside a development context the confirmation is that external recipient count,
  passed as `--force=N`. A number that has to be read off a dry run cannot be
  supplied by reflex, and it changes when the list does.

### Changed

- `confirmAction(string $operation)` became `confirmDeleteAllAction()`. With bulk
  sending gone there was one operation left and the parameter was dead weight.

## [0.4.0] — 2026-08-25

### Added

- **Send** per mail, next to Delete. With a long list and a host that limits how
  much may leave at once, sending everything in one run is the wrong tool — and
  some mails in the list are meant to be deleted, not delivered.
- A bulk run stops after three failures in a row. A relay that refuses the fourth
  message will refuse the fortieth, and hammering it is how an account gets
  throttled. Everything unsent stays in place, and the message says why the run
  ended early.

## [0.3.2] — 2026-08-25

### Fixed

- **No action in the module ever reported its result.** Switching the catcher on
  or off, deleting, resending — all of them queued a flash message that was never
  displayed, so every action looked like it had done nothing. The core module
  layout renders one specific queue,
  `<f:flashMessages queueIdentifier="{flashMessageQueueIdentifier}" />`, whose
  identifier comes from `ModuleTemplate`, while `addFlashMessage()` writes into
  Extbase's own plugin-namespaced queue. The two are now connected through
  `ModuleTemplate::setFlashMessageQueue()`, in a single place so it cannot be
  forgotten for a new action.

  This is why a failed resend appeared to do nothing at all: it had reported both
  the failure and its reason, and neither reached the screen. The 0.1.1 entry
  claiming this was fixed by declaring the `Module` layout was wrong — the layout
  restored the padding, not the messages.

### Changed

- The "sent %s mails" message is only shown when something was actually sent. A
  green "sent 0" next to a red error was more confusing than no message.

## [0.3.1] — 2026-08-25

### Fixed

- The install tool's mail test under **Environment** was not captured and
  delivered its mail for real. 0.3.0 moved the transport wiring into the
  extension's `ext_localconf.php` and dropped the block in
  `config/system/additional.php` as no longer needed — which was wrong.
  `EnvironmentController::mailTestAction()` calls
  `BootService::getContainer()` without
  `loadExtLocalconfDatabaseAndExtTables()`, so no extension configuration is
  loaded and the wiring never runs. `additional.php` is read by every bootstrap
  and does not have that gap.

  The block is therefore required again, and the README explains what each of the
  two layers covers. `ext_localconf.php` stays: it works without any project
  configuration and overrides project code that rewrites the `MAIL` array, so a
  missing block is now a narrow gap rather than a total one.

### Added

- Finding `projectBlockMissing`: while the catcher is on, the backend reports
  whether `config/system/additional.php` wired the transport before
  `ext_localconf.php` did — the only point at which the two layers can still be
  told apart.

## [0.3.0] — 2026-08-25

### Added

- Captured mail can be delivered after the fact. Switch the catcher off, delete
  the test and debug mails, then **Send remaining** — the rest goes to its
  original recipients. Delivered files move to `var/mailcatcher/sent/` instead of
  being deleted, so a failure never destroys the only copy. Sending is refused
  while the catcher is on, because the mails would be captured again.
- Both destructive actions now go through a confirmation step naming the count,
  with an extra note in a Production context. It is a rendered step rather than a
  JavaScript dialog: it works regardless of what the backend loads, and the
  friction is the point.
- The backend shows since when the catcher has been on. A tool meant for a short
  incident window reads differently after three days than after ten minutes, and
  the people who notice the missing mail are website visitors, who never see the
  banner.
- `mailcatcher:prune` requires `--force` in a Production context.

### Changed

- **The transport is wired in the extension's own `ext_localconf.php`.** It used
  to require a block in `config/system/additional.php`, and forgetting it meant
  the backend reported that no mail was being sent while every mail went out.
  `ext_localconf.php` loads after `additional.php`, so this also overrides
  project code that rewrites the `MAIL` array. The project block is no longer
  needed; leaving it in place is harmless.
- **A process that may not capture now refuses to send.** Previously it fell back
  to real delivery, which is how a command line resolving a `Production` context
  came to deliver mail while the backend reported the opposite. `RefusingTransport`
  throws instead. This is a behaviour change on misconfigured systems: mail that
  used to go out now fails loudly.

### Removed

- Finding `allowedMissing`, obsolete: the divergence it warned about is no longer
  a silent failure but an abort.

## [0.2.3] — 2026-08-25

### Fixed

- The list of captured mails was not rendered. Reworking the status box in 0.2.0
  left an orphaned fragment behind — the tail of the old markup, including three
  stray `</div>` tags that closed the module body early, so the table was emitted
  outside its container and never became visible. A second, unreachable
  "Delete all" button below the status box was the visible symptom.

## [0.2.2] — 2026-08-25

### Added

- A fifth state, **Switched on, but locked in this environment**. Previously this
  reported as *Inactive*, which is true of the transport but hides that somebody
  switched the catcher on and expects mail to be captured. It is not, and every
  mail is delivered.
- Finding `allowedMissing`: the catcher is on and unlocked by context alone,
  because `MAILCATCHER_ALLOWED` is not set. Every process running in a Production
  context then sends for real — and the command line defaults to that context
  even where the web server sets a development one. A bulk send from a console
  command or a scheduler task therefore reaches real recipients while the backend
  reports that no mail is being sent. This was observed on a live installation,
  not constructed.

## [0.2.1] — 2026-08-25

### Fixed

- The module's status box was barely readable in the backend's dark theme. It
  used Bootstrap's `bg-*-subtle` utilities, which carry their dark values under
  `[data-bs-theme=dark]` — a selector the v14 backend never sets, because it
  themes through `color-scheme` and `light-dark()`. The box therefore kept its
  light background while every text colour followed the dark theme. It is now
  rendered as a backend `callout`, whose `.callout-*` variants set background
  and text colour from the same `light-dark()` tokens.
- The API token length check no longer fires in development contexts. A
  throwaway token is the norm on a developer machine and nothing reaches it from
  outside; a warning that stands permanently during normal work is one people
  learn to look past, including the ones next to it that do matter.

### Changed

- `configuration.productionUnlocked.hint` no longer reads as if every occurrence
  were a live system. Staging commonly runs in a `Production` context, where
  `MAILCATCHER_ALLOWED=1` is intended — the hint now says where the variable
  belongs and where it does not, instead of guessing from the context name.

## [0.2.0] — 2026-08-25

### Added

- `ConfigurationValidator`, checking that the extension is wired up and configured
  the way it claims. It answers the only question that matters — does the mail
  transport actually point at the catcher — instead of merely reading the switch.
- Two new states, reported in the backend module, the system information toolbar
  and the Reports module. **Switched on but ineffective** means the block in
  `config/system/additional.php` is missing and mails are being sent although the
  catcher is on. **Switched off, but the transport points at the catcher** is the
  reverse: an assignment made without the `MailcatcherState::isActive()` guard, or
  left behind in `settings.php`, silently stops delivery.
- Validation of both environment variables, reported in the module and in the
  Reports module: `MAILCATCHER_ALLOWED=1` in the Production context, a
  `MAILCATCHER_ALLOWED` value other than `1` or `0` that is silently ignored,
  `MAILCATCHER_API_TOKEN` on a Production system, and a token shorter than 32
  characters.
- `MailcatcherState::isWired()` and the `MailcatcherStatus` enum, which derives
  every label key from its case so a new state cannot render an empty string in
  one of the four places that report it.

### Fixed

- The backend claimed *no mail is being sent* whenever the catcher was switched
  on, regardless of whether the transport had ever been wired up. With the block
  in `additional.php` forgotten, that was the most damaging wrong answer the
  extension could give: it invites deliberate test mails to real addresses.

### Changed

- The banner and toolbar wording drops the implementation detail. Editors are told
  where their mail can be found — "Every mail only lands in the Mailcatcher module"
  — instead of that it is written to a file. `var/mailcatcher/` stays in the
  Reports module, where the audience is the administration.
- `MailcatcherApiMiddleware` reads its token through
  `MailcatcherState::readEnvironmentVariable()` rather than its own copy of the
  same `getenv()`/`$_ENV` fallback.

## [0.1.5] — 2026-08-24

### Fixed

- The HTML preview stayed blank: an entirely empty `sandbox` attribute kept the
  framed document from rendering. It now uses `sandbox="allow-same-origin"`,
  which is inert without `allow-scripts` — scripts, forms and popups remain
  denied, and the response still carries its own Content-Security-Policy.

## [0.1.4] — 2026-08-24

### Fixed

- The detail view's tabs did nothing: the backend has Bootstrap in its importmap
  but never loads it, so `data-bs-toggle="tab"` was unwired and every pane except
  the first stayed hidden — the HTML, text, source and attachment tabs appeared
  empty. The module now loads the core's own tab module, `@typo3/backend/tab.js`
  on v14 and `@typo3/backend/tabs.js` on v13, where the former does not exist.
  The `fade` class was dropped so display no longer depends on a transition.

## [0.1.3] — 2026-08-24

### Added

- Headers tab in the detail view, listing every header of the captured mail
  (Content-Type, Date, Message-Id, Return-Path, X-Mailer and the rest). The test
  API returns them as `headers` on a single message.

## [0.1.2] — 2026-08-20

### Fixed

- The HTML preview inherited the backend policy `img-src 'self'`, so every logo
  and every remote image in a captured mail was blocked — the one thing the
  preview exists to show. The preview response now carries its own
  Content-Security-Policy, scoped to that route; the backend policy is untouched
  and scripts stay denied.

### Changed

- Label `show.remoteImagesBlocked` replaced by `show.previewNote`, which now
  states that images load and scripts do not.

## [0.1.1] — 2026-08-20

### Fixed

- The backend module templates did not declare the core `Module` layout, so the
  module content was rendered without the `module-body` wrapper — no padding, and
  flash messages from the toggle and delete actions were never displayed.

## [0.1.0] — 2026-08-20

### Added

- `FileTransport`, writing every outgoing mail to its own `.eml` file below
  `var/mailcatcher/` instead of sending it.
- Backend module **System → Mailcatcher** with list, findings, HTML, plain text,
  source and attachments. The HTML part is served through its own route into a
  sandboxed iframe.
- Ten check rules reporting the usual mail configuration mistakes, extensible
  through the `ot_mailcatcher.check` service tag.
- Token-protected test API at `/_mailcatcher/api/messages`, returning the findings
  as stable identifiers for end-to-end tests.
- Origin stamping through `BeforeMailerSentMessageEvent`, recording request URL,
  page and form identifier on each captured mail.
- Three warning layers while the catcher is active: system information toolbar,
  Reports status and a banner on every backend page.
- Console commands `mailcatcher:testmail` and `mailcatcher:prune`.
- Production lock, releasable through `MAILCATCHER_ALLOWED=1`.

### Requirements

- TYPO3 13.4 LTS or 14.3 LTS, PHP 8.2 or newer.
- Requires `typo3/cms-reports` for the Reports status entry.
