=== Ruvelo Comment Subscriptions ===
Contributors: francoisbultez
Tags: comments, subscribe to comments, comment notifications, email, replies
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Readers get an email when someone replies to them or comments on a post they follow. Double opt-in, one-click unsubscribe, no upsells.

== Description ==

Someone leaves a comment, you answer, and they never see it. Ruvelo Comment Subscriptions closes that loop: readers tick a box under the comment form and get an email when someone replies to them, or when anyone comments on the post. It's built for the thing a public form that sends email has to get right first: safety.

**Two simple choices in the comment form.** "Notify me of replies to my comment" and "Notify me of all new comments on this post", unticked by default, in your theme's own style. It works with the classic comment form and the Comments block in block themes.

**Subscribe without commenting.** A small form under the comments (or the Subscribe to Comments block, or the `[ruvelo_subscribe]` shortcode) lets readers follow a conversation without joining it.

**Double opt-in, done properly.** Guests confirm by email before anything is sent to them. Confirmation links expire, and unconfirmed requests are deleted after a few days. Signed-in users subscribe straight away. Nobody can find out who follows what: the answer is always the same.

**Only approved comments, never inline.** Held, spam and trashed comments are never sent, and the check happens again just before sending. Emails go out in the background, a batch at a time through WP-Cron, never while someone waits for a page. Editor notes, pingbacks and trackbacks don't count as comments.

**Clean, accessible emails.** HTML with a plain-text part, system fonts, light and dark, and the comment quoted as plain text (never its HTML). Every email carries `List-Unsubscribe` and `List-Unsubscribe-Post` headers, so mail clients show their own one-click Unsubscribe button. Change any email by copying its template into your theme, or with filters.

**A daily digest, if they prefer.** Each subscriber chooses: an email per comment, or one digest a day covering every post they follow.

**A page to manage it all, no account needed.** Every email links to a "Manage my subscriptions" page through a signed link that expires. From there readers switch between all comments and replies only, choose the digest, unsubscribe from one post or from everything. Signed-in users do the same from their profile.

**For you, the Subscriptions screen.** Comments → Subscriptions: who follows what, search by address or post, bulk delete, and the numbers that matter (active subscriptions, people, posts followed, requests waiting for confirmation, emails sent). Each post shows its subscriber count in the editor and the posts list, with a switch to turn subscriptions off for that post.

**Coming from Subscribe To Comments Reloaded?** It was closed on WordPress.org in April 2026 for a security issue. The importer reads its data (and the original Subscribe to Comments'), shows a dry run first, keeps confirmed subscriptions confirmed, and never changes the old data. Also from WP-CLI: `wp ruvelo-comment-subscriptions import stcr --dry-run`.

**Works with Ruvelo Inbox.** When an inbox is installed, signed-in subscribers can get notifications there instead of, or as well as, by email.

**For developers.** A REST API (`ruvelo-comment-subscriptions/v1`), WP-CLI commands (list, add, delete, import, prune, send-test, queue), Abilities API registrations for AI agents (count and list a post's subscribers, for site managers only), template overrides, and actions and filters for everything that happens. Settings can be defined in code.

= Security and privacy =

* Nonces on every form, capability checks on every admin action, `$wpdb->prepare()` for every query, everything escaped on output.
* Rate limits per IP address and per email address, and a honeypot, so nobody can use your site to flood a stranger's inbox.
* Links in emails are random tokens (only a hash is stored) or HMAC-signed with a secret of their own: no guessable IDs. A GET only ever shows a page with a button, so link scanners can't confirm or unsubscribe anyone.
* Only what's needed is stored: the address, the post, what to send and how often, and when the subscription started and was confirmed. Unsubscribing deletes the record.
* Personal data export and erasure (Tools → Export/Erase Personal Data), and a paragraph for your privacy policy.

= Our promises =

* **No nags, no upsells, no "pro" version.** No admin notices outside the plugin's own screens, no review requests, no ads, no redirect after activation. It's complete and free, and it will stay that way.
* **No tracking and no remote requests.** The plugin never phones home and loads nothing from anywhere else: no fonts, scripts, images or banners. The only emails it sends are the ones your readers ask for.
* **Updates come only from WordPress.org and Composer**, through WordPress's own updater. Ruvelo will never sell this plugin, its WordPress.org account or its users.
* **Your data stays yours.** Deleting the plugin keeps subscriptions unless you tick "Also delete every subscription and setting". Scheduled jobs are always cleared.
* **Accessible and light.** Labels, focus states, keyboard support and live announcements, aiming at WCAG 2.2 AA. Every form works without JavaScript. Respects reduced motion, and offers light, dark or system colour schemes.

Ruvelo Comment Subscriptions is built by [Valtry](https://valtry.co.uk/?utm_source=ruvelo&utm_medium=wordpress&utm_campaign=wp-comment-subscriptions), the website security scanner, as part of Ruvelo, its free and open-source extensions for WordPress and Laravel. The code, issues and roadmap are on [GitHub](https://github.com/Ruvelo/wp-comment-subscriptions).

== Installation ==

1. Install and activate the plugin from Plugins → Add New, or with `wp plugin install ruvelo-comment-subscriptions --activate`.
2. That's it: the options appear under your comment forms. Adjust them in Comments → Subscriptions → Settings.
3. Moving from Subscribe To Comments Reloaded? Go to Comments → Subscriptions → Import, run a dry run, then import. Then deactivate the old plugin.

Emails go out through WordPress's own `wp_mail()`. For reliable delivery, most sites need an SMTP plugin or a transactional email service.

With Composer: `composer require ruvelo/wp-comment-subscriptions`.

== Frequently Asked Questions ==

= Why do guests have to confirm? =

Because anyone can type anyone's address into a form. Without confirmation, your site could be used to send unwanted email to strangers. Signed-in users have already proved their address, so they subscribe straight away.

= What does "replies to my comment" cover? =

Replies to any of the subscriber's comments on that post, including replies further down the same thread.

= When are emails sent? =

When a comment is approved (straight away, or later from the moderation queue) it's queued, and WP-Cron sends it in batches of 50 about a minute apart. Daily digests go out once a day, at the hour you choose. If your site has little traffic, set up a real cron job for WP-Cron, or run `wp ruvelo-comment-subscriptions queue run`.

= Does it send email for comments held for moderation, or spam? =

Never. Only approved comments are sent, and each comment is checked again just before its emails go out.

= Where do the links in the emails go? =

To `/comment-subscriptions/` on your site. It isn't a page you need to create: block themes show it through a template you can edit in the Site Editor (Templates → Comment subscriptions), classic themes inside their header and footer. You can also put the Manage Comment Subscriptions block on a page of your own and point links there with the `ruvelo_comment_subscriptions_page_url` filter.

= How does one-click unsubscribe work? =

Every notification and digest carries `List-Unsubscribe` and `List-Unsubscribe-Post: List-Unsubscribe=One-Click` headers (RFC 8058), and a visible link. Mail clients that support it show an Unsubscribe button that works without opening the site. With PHP's built-in `mail()` transport, PHPMailer encodes long headers, which some mail clients then ignore; an SMTP plugin avoids that, and the link in the email always works.

= Can I preview the emails? =

Yes: Comments → Subscriptions → Preview emails shows each one with a post from your site, without sending anything, and can send you a test. `wp ruvelo-comment-subscriptions send-test you@example.com` does the same from the command line.

= Can I change how the emails look? =

Copy any file from the plugin's `templates/emails/` folder to `ruvelo-comment-subscriptions/emails/` in your theme and edit it. The `ruvelo_comment_subscriptions_email` filter can change the subject, HTML, text or headers of any email, or stop it.

= How do I import from Subscribe To Comments Reloaded? =

Comments → Subscriptions → Import. The dry run shows how many subscriptions would be imported and what would be left out (requests that were never confirmed or were suspended, invalid addresses, deleted posts). Confirmed subscriptions stay confirmed, keep their original date, and nobody gets an email. The old data isn't changed, so you can run it again safely: nobody is added twice. Big sites can use `wp ruvelo-comment-subscriptions import stcr`.

= Is it accessible? =

That's the aim: WCAG 2.2 AA. Every control has a label and a visible focus state, results are announced to screen readers, and everything works with a keyboard and without JavaScript. If you find a barrier, please open an issue.

= What about privacy? =

The plugin stores the address, the post, the kind of subscription, how often to send, and when it started and was confirmed. Unconfirmed requests are deleted after a few days, and unsubscribing deletes the record. Rate limits keep short-lived counts per IP address and per email address as keyed hashes. It adds a paragraph to your privacy policy guide and works with WordPress's personal data export and erasure tools.

= Can AI agents use it? =

Through the WordPress Abilities API, agents can count and list a post's subscribers, only for users with the `ruvelo_manage_comment_subscriptions` capability (administrators by default). With the MCP adapter installed, the abilities are available to MCP clients too.

== Screenshots ==

1. The comment form: notify me of replies, or of all new comments, and "Subscribe without commenting".
2. The confirmation email (double opt-in).
3. A new-reply email with one-click unsubscribe.
4. The manage page, reached from a signed link: change, digest or unsubscribe.
5. Comments → Subscriptions: who follows what, search, bulk delete and stats.
6. Importing from Subscribe To Comments Reloaded, after a dry run.
7. Preview every email without sending it.
8. Settings.
9. On a phone.
10. In dark mode.

== Changelog ==

= 1.0.0 - 2026-10-09 =

* First release: comment form options, subscribe without commenting, double opt-in, notifications and daily digests, one-click unsubscribe, the manage page, the Subscriptions screen, and an importer from Subscribe To Comments Reloaded.

== Upgrade Notice ==

= 1.0.0 =

First release.
