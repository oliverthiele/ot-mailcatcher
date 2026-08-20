# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
