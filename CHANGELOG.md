# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

## [1.0.0] - 2026-10-09

First release.

### Added

- Comment form options ("Notify me of replies to my comment" and "Notify me of all new comments on this post"), for the classic form and the Comments block, plus "Subscribe without commenting" (form, Subscribe to Comments block and `[ruvelo_subscribe]` shortcode).
- Double opt-in for guests with expiring confirmation links and cleanup of unconfirmed requests; signed-in users subscribe straight away.
- Notifications for approved comments only, queued and sent in batches through WP-Cron, with a daily digest option, HTML and plain-text emails, `List-Unsubscribe` one-click headers and theme-overridable templates.
- A manage page reached from signed links (no account needed), a profile section for signed-in users, and an optional delivery through Ruvelo Inbox.
- The Comments → Subscriptions screen: subscribers, search, bulk delete, stats, email previews and settings; per-post counts and a per-post switch.
- An importer from Subscribe To Comments Reloaded and Subscribe to Comments, with a dry run.
- Rate limits, honeypot, hashed tokens, personal data export and erasure, and an uninstall that deletes data only when asked.
- REST API (`ruvelo-comment-subscriptions/v1`), WP-CLI (`wp ruvelo-comment-subscriptions list|add|delete|import|prune|send-test|queue|stats|rotate-secret|demo`), Abilities API registrations, actions and filters.
- Halyard's demo (`wp ruvelo-comment-subscriptions demo`) and a WordPress Playground blueprint.

[Unreleased]: https://github.com/Ruvelo/wp-comment-subscriptions/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/Ruvelo/wp-comment-subscriptions/releases/tag/v1.0.0
