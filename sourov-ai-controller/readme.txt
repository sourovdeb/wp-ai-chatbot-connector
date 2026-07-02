=== Sourov AI Controller ===
Version: 1.3
Requires at least: 5.6
Requires PHP: 7.4

Remote AI control of posts on sourovdeb.com: create, edit, schedule, bulk-create,
and delete via a small REST API under /wp-json/sourov/v1/.

== Authentication ==
Two supported methods, both over HTTPS only:

1. Application Password (preferred). Create one under Users -> Profile ->
   Application Passwords in wp-admin, then send it as HTTP Basic auth. Revocable
   per key, scoped to the user's capabilities.

2. Secret key. Define it in wp-config.php (recommended):
       define('SOUROV_AI_SECRET_KEY', 'a-long-random-value');
   or set it in Tools -> Sourov AI. Send it in the X-Sourov-Key request header.
   Never place the key in a URL/query string.

Comparison is timing-safe (hash_equals). If no key is configured and the request
is not an authenticated user, access is denied.

== Endpoints ==
POST   /wp-json/sourov/v1/ai-post          Create or schedule one post (+ SEO meta)
GET    /wp-json/sourov/v1/drafts           List drafts (paginated: page, per_page)
POST   /wp-json/sourov/v1/schedule-drafts  Batch-schedule drafts (interval, offset, limit)
GET    /wp-json/sourov/v1/scheduled        List future posts
POST   /wp-json/sourov/v1/bulk             Create many posts (JSON array)
DELETE /wp-json/sourov/v1/post/{id}        Trash a post (?force=true to delete permanently)
GET    /wp-json/sourov/v1/status           Public health + counts (no secrets)
GET    /wp-json/sourov/v1/health           Public health

== Notes ==
- Delete now defaults to trash (reversible); pass force=true for permanent delete.
- For exact scheduling, add a 5-minute real cron in hPanel hitting wp-cron.php.
