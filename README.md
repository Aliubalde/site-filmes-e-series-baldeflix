# BALDÉ FLIX on Cloudflare

This is the Cloudflare migration workspace. It is separate from the original PHP/MySQL application in `../xampp`; do not upload `xampp/config.php` as a public asset.

## Current migration status

The first Worker/D1 migration phase includes:

- Authenticated catalog and category read endpoints.
- Account registration, login, logout, and cookie-backed sessions.
- A one-time, secret-protected SUPER ADMIN setup.
- Basic admin title creation and publish/unpublish.
- Favorites, ratings, comments, personal lists, watch history, content requests, calendar reminders, and notifications API actions.
- Basic watch-progress storage and access checks for paid titles.

This is not feature parity with the PHP site yet. The new list, history, request, calendar, reminder, notification, rating, comment, and watch-progress endpoints do not yet have their full user interfaces. Profile settings, subscription/referral management, complete series playback and season/episode administration, video uploads/storage, password recovery email, moderation tools, and the rest of the admin panel still need to be migrated. Do not treat this version as production-ready until those features, the data import, and their tests are complete.

## Local setup

1. Install Node.js LTS.
2. From this directory, run `npm install`.
3. Create a D1 database with `npx wrangler d1 create balde-flix`.
4. Copy the `database_id` returned by Wrangler into `wrangler.jsonc`.
5. Apply the schema locally with `npm run db:local`.
6. Start the local Worker with `npm run dev`.

## Cloudflare deployment

Do not drag the repository or the `cloudflare` folder into the Pages dashboard uploader. This project is a Worker with D1, not a static-only Pages site; the folder also contains local dependencies and Wrangler state that should not be uploaded. Use Wrangler from this directory instead. It bundles the Worker and only the static assets configured in `wrangler.jsonc` (`./public`).

1. Sign in to Wrangler with `npx wrangler login`.
2. Create the D1 database and set its ID in `wrangler.jsonc`.
3. Apply the schema to that database with `npm run db:remote`.
4. Set a random setup secret of at least 32 characters with `npx wrangler secret put SETUP_TOKEN`. Do not put the value in source code or send it in chat.
5. Deploy with `npm run deploy`. Cloudflare assigns a `workers.dev` URL; a custom domain is optional.
6. On the first visit, use the protected setup form to create the SUPER ADMIN. Then remove the setup secret with `npx wrangler secret delete SETUP_TOKEN`.

The current Worker uses D1 (SQLite). The original MySQL database cannot be attached directly; any existing user or catalog data needs an explicit export and conversion before importing it into D1. The local XAMPP/phpMyAdmin export has not yet been created, so no existing accounts or catalog data have been imported. Never deploy the original MySQL credentials.
