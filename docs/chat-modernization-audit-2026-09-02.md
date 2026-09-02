# PrintFlow chat modernization audit

Date: 2026-09-02

## Original architecture

- Customer UI: `customer/chat.php`, a two-pane order-conversation page with a large inline controller. It fetched `list_conversations.php`, then polled `fetch_messages.php?last_id=...` every 2 seconds while visible.
- Staff UI: `staff/chats.php`, a desktop split pane and mobile list/thread page with a separate large inline controller. It polled messages every 2 seconds and conversations every 10 seconds.
- Storage: conversations are orders (`orders.order_id`); history is stored in `order_messages`. Important compatibility columns include `reply_id`, `message_type`, media paths, `read_receipt`, `is_pinned`, `is_forwarded`, `meta_json`, and timestamps. Reactions use `message_reactions`, uniquely keyed per message/sender/sender ID. Presence uses `user_status`.
- Send path: `public/api/chat/send_message.php` inserted text and media rows and invoked `printflow_notify_chat_message()`. It accepted extensions for images and videos up to 50 MB and did not perform chat-specific content validation.
- Fetch path: `fetch_messages.php` selected every message after `last_id`. Initial `last_id=0` therefore downloaded the complete history. Every poll also fetched every reaction and every pinned row in the order.
- Images/media: paths were stored in multiple legacy columns. Images were exposed as upload paths. Audio/video used filename-based serving endpoints that checked login but not conversation participation.
- Realtime/calls: both global headers loaded the call client and optionally Socket.IO. `public/assets/js/printflow_call.js`, `socket/server.js`, and `server.backup.js` implemented Socket.IO/WebRTC calling. Call events were written to `order_messages`.
- Voice messages: both pages used `getUserMedia`/`MediaRecorder` and `send_voice.php`. Historical audio was displayed by a custom player.
- Archive: `orders.is_archived`, active/archived tabs, and `set_archived.php`. The schema helper created the flag at runtime.
- Delete: no normal customer/staff message-delete UI or active message-delete endpoint was found. Admin data-maintenance tools can delete whole customer/order datasets and remain separate.
- Replies: `order_messages.reply_id` self-join. The send API previously did not verify that the reply belonged to the same order.
- Reactions: `react_message.php` added, changed, or removed the caller's unique reaction, but previously lacked an allowlist and customer conversation authorization.
- Pins: `order_messages.is_pinned` toggled through `pin_message.php`; both current roles could pin. The same existing permission rule is retained.
- Seen/unread: `read_receipt` uses 0/1/2. Fetching with `is_active=1` performed a batch update to 2 as a GET side effect. Conversation lists counted opposite-sender rows below 2.
- Notifications: successful sends call the existing `printflow_notify_chat_message()` integration. It remains unchanged.

## Resulting architecture

- Browser -> same-origin PHP APIs -> MySQL -> adaptive incremental polling. No chat Socket.IO/WebSocket/Railway path remains.
- Initial fetch is capped at 40 messages. New deltas use `after_id`; older pages use `before_id`; the server clamps all pages to 50.
- Active message polling is 3 seconds; hidden-tab polling is 15 seconds. Conversation summaries poll at 8 seconds visible and 30 seconds hidden. Visibility restoration triggers an immediate refresh.
- Per-operation single-flight flags, separate abort controllers, and a conversation generation token prevent overlap, noisy expected aborts, and stale-conversation rendering.
- The shared controller preserves scroll position when prepending history and shows a New messages button instead of forcing a reader to the bottom.
- Text/image sends use a client idempotency token and a server-side rolling token guard. Enter sends; Shift+Enter adds a newline.
- New media is limited to four JPG/PNG/WebP images, 8 MB each. Validation uses `finfo`, `getimagesize`, dimensions, authenticated order access, random 40-hex filenames, and a non-executable/private chat-upload directory.
- Image/audio/video reads use message-ID endpoints that re-check conversation authorization. Voice/video remain read-only for historical compatibility.
- Reply IDs are validated against the target order. Reactions are allowlisted. All mutations use the existing CSRF token standard. Customer ownership and staff branch access are shared across APIs.
- Seen state moved to CSRF-protected `mark_seen.php`; polling GETs no longer write. The update is one bounded batch and only advances when a visible participant opens the conversation.
- Archive flags and rows are not changed or deleted. List queries ignore the flag so historical archived conversations are visible normally. `set_archived.php` returns 410.
- Normal message deletion is explicitly refused by `delete_message.php` with 405. No message/history rows or attachment files are deleted.
- Call, video-call, voice-recording, global signaling, Socket.IO packages, retry loops, and their page controls are removed. Retired call/voice endpoints return 410. Historical rows render as ordinary text/audio/video content.

## Performance comparison

| Item | Before | After |
|---|---:|---:|
| Initial message rows | Unbounded full order history | 40 (server maximum 50) |
| Recurring message interval, visible | 2 seconds | 3 seconds |
| Recurring message interval, hidden | 5 seconds customer / paused staff | 15 seconds |
| Conversation interval, visible | 10 seconds staff | 8 seconds both roles |
| Conversation interval, hidden | Paused staff | 30 seconds |
| Reactions queried per poll | Entire order | Current page or latest 50 message IDs |
| Chat WebSocket requests | Optional global Socket.IO connection/retries | 0 |
| Overlapping message polls | Possible | Single-flight |

Exact byte counts and SQL `EXPLAIN` comparisons could not be collected because this workspace has no configured database or authenticated browser target. No index migration was added without live query plans; the existing `order_id` secondary index includes the InnoDB primary message ID and supports the new range cursor.

## Verification completed

- PHP syntax checks for every changed/new PHP file.
- JavaScript syntax check for `chat_http.js`.
- Executable chat unit coverage for cursor clamping, reaction allowlist, real image inspection/SVG rejection, path traversal rejection, customer ownership, staff branch enforcement, and same-conversation replies.
- Existing completion, payment-page, notification UI, revision-notification, and payment-notification regression tests.
- Repository-wide removal audit for Socket.IO/WebRTC/Railway call code.
- `git diff --check`.

## Verification still required before release

- Authenticated customer/staff database-backed functional matrix (send, reply, reaction, pin, seen, unread, image in both directions, 500+ history).
- Browser console/network checks and responsive visual QA at 390, 768, and 1440 pixels.
- Live database `EXPLAIN`/payload measurements.

These checks are release blockers in the current environment; no commit or push should occur until they pass.
