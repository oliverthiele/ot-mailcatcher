# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
