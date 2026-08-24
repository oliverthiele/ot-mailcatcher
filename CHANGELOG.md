# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
