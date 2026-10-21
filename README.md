# Exact Social connect relay

Connects social accounts to the Exact Social WordPress plugin through Exact Social's own Meta apps. Networks only redirect to registered URIs and can't list every WordPress site, so the apps' redirect URIs are this relay. It swaps the network's code for tokens with the app secret, which never leaves this server, and keeps them, encrypted, until the site that started the connect collects them (at most 10 minutes). After that the site talks to the network directly; the relay is only used to connect and reconnect.

Plain PHP 8.0+ with the `curl` and `sodium` extensions. No dependencies.

## Networks

| Route | Login | Sets (`?set=`, first is the default) | Settings |
|---|---|---|---|
| `/facebook/…` | Facebook Login for Business; each set is one configuration | `pages` (free), `instagram` (Pages with their linked Instagram accounts), `inbox` (everything, plus messages and comments) | `FB_APP_ID`, `FB_APP_SECRET`, `FB_CONFIG_ID` (required); `FB_CONFIG_ID_INSTAGRAM`, `FB_CONFIG_ID_INBOX` |
| `/instagram/…` | Instagram API with Instagram Login, for professional accounts not linked to a Page | `publish`, `inbox` (adds messages) | `INSTAGRAM_APP_ID`, `INSTAGRAM_APP_SECRET` |
| `/threads/…` | Threads API | `publish`, `inbox` (adds replies and mentions) | `THREADS_APP_ID`, `THREADS_APP_SECRET` |

A network or set switches on when its settings are present; asking for one that isn't gives a 404 (network) or 400 (set). Only Facebook's `pages` set is required.

Instagram-login and Threads tokens last 60 days. The site refreshes them itself (`graph.instagram.com/refresh_access_token`, `graph.threads.net/refresh_access_token`), which needs no app secret, so the relay has no refresh route. Facebook Page tokens don't expire.

## Flow

1. **Site → browser → relay.** The plugin makes a random `verifier`, keeps it, and sends the browser to
   `GET /{network}/start?return=<url>&challenge=<base64url(sha256(verifier))>&set=<set>`.
   `return` is the address on the site to come back to.
2. **Relay → network.** The relay shows which site the account will go to and links to the network's login dialog. A cookie ties the callback to this browser, so a login link made by someone else can't send an account to their site.
3. **Network → relay.** `GET /{network}/callback` exchanges the code for tokens, reads the account (and for Facebook the Pages, each with a Page token), stores them encrypted under a one-time `relay_code`, and redirects to `return` with `&relay_code=…`, or `&relay_error=cancelled|denied|failed`.
4. **Site → relay, server to server.** `POST /collect` with `code` and `verifier` (form-encoded). The relay removes the record, then answers `200` with the JSON below when the verifier matches, or `404 {"error":"invalid_code"}`. A code works once; a wrong verifier burns it.

Facebook:

```json
{
  "network": "facebook",
  "set": "instagram",
  "user": { "id": "…", "name": "…" },
  "user_token": "…",
  "expires_in": 5183944,
  "pages": [
    {
      "id": "…", "name": "…", "access_token": "…", "picture": "https://…",
      "tasks": ["CREATE_CONTENT", "MANAGE", "MODERATE"],
      "instagram": { "id": "…", "username": "…", "name": "…", "picture": "https://…" }
    }
  ]
}
```

`instagram` is `null` on the `pages` set, or for a Page without a linked account.

Instagram and Threads:

```json
{
  "network": "threads",
  "set": "publish",
  "user": { "id": "…", "username": "…", "name": "…", "picture": "https://…" },
  "user_token": "…",
  "expires_in": 5183944
}
```

Tokens never appear in a URL or pass through the browser; only the one-time code does, and it's useless without the verifier.

## Meta app

Register each callback as a redirect URI (Strict Mode on):

- Facebook Login for Business → Settings → Valid OAuth Redirect URIs: `<RELAY_URL>/facebook/callback`
- Instagram (API setup with Instagram login) → business login: `<RELAY_URL>/instagram/callback`
- Threads use case → Settings → Redirect callback URLs: `<RELAY_URL>/threads/callback`

## Deploy with Coolify

1. New resource → public Git repository, branch `main`, **Build Pack: Dockerfile** (the `Dockerfile` here: PHP 8.3 with Apache, web root `public/`, `store/` inside the container).
2. Ports Exposes `80`; domain `https://connect.exactwp.com` (Coolify's proxy handles HTTPS).
3. Environment variables, as **runtime** variables (not build variables): the settings in the table above, plus `RELAY_URL` (`https://connect.exactwp.com`) and `RELAY_KEY` (`openssl rand -base64 32`). Lock every `*_SECRET` and `RELAY_KEY` so they're hidden in the UI.
4. Health check path `/` (answers `ok`). Keep one replica: records sit on the container's disk.

## Deploy on a plain server

1. Upload the folder to the server and point the web root of the relay's host (HTTPS only) at `public/`. Everything else stays outside the web root.
2. Create `store/` beside `public/`, writable by PHP and nothing else (`chmod 700`).
3. Settings as environment variables or in `config.php` beside `public/` (copy `config.example.php`; it's gitignored). `RELAY_STORE` optionally moves the store.
4. `GET /` answers `ok`. Missing or wrong settings give a 500 page and a line in the PHP error log naming what's missing.

Rewrite every path to `public/index.php` if the server doesn't already (nginx: `try_files $uri /index.php?$query_string;`; Apache: `public/.htaccess` has `FallbackResource /index.php`).

## Check

```bash
php tests/check.php
```

Covers the verifier check, return-address rules, the store (encrypted on disk, read once, expiry, sweep), and what each network's login asks for (configuration per Facebook set, permissions per Instagram and Threads set, no secret in a login URL).

## Try a deployed relay

```bash
php tests/try.php https://connect.exactwp.com facebook pages
php tests/try.php https://connect.exactwp.com facebook instagram
php tests/try.php https://connect.exactwp.com instagram publish
php tests/try.php https://connect.exactwp.com threads publish
```

The script plays the WordPress site: it starts a connect with a fresh verifier, opens the link (on a Mac), waits for the browser on `http://127.0.0.1:8899`, collects the tokens, makes one read-only call with them (a Page's name, or the account's username), and checks that a second `/collect` with the same code is refused. Tokens are masked in its output.

While the Meta app is in Development mode only people with a role can log in: the app's admins and developers for Facebook, and accepted Instagram or Threads testers (App roles) for those logins. `facebook instagram` needs a Page with a linked Instagram professional account.
