# Changelog

## 1.0.1 — 2026-09-15

- Resolve the organizer key via GoTo identity endpoints after OAuth; current GoTo OAuth clients no longer include it in the token response.
- Multiple-choice custom questions: match answers tolerantly (dashes, curly quotes, case, punctuation) and add an entry note listing the valid options when nothing matches.
- Daily keep-alive refresh so the GoTo refresh token does not expire on quiet sites; cleared on disconnect, deactivation and uninstall.
- Log every GoTo API call to the Gravity Forms log.

## 1.0.0 — 2026-09-15

- Initial release.
- OAuth 2.0 connection to GoTo Webinar from Forms > Settings > GoTo Webinar.
- Feed-based registration: pick a webinar, map registrant fields and custom questions, conditional logic.
- Entry meta and merge tags for the join URL, registrant key and status.
- Self-updates from GitHub releases with a "Check for updates" button.
