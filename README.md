# Turnstile — Cloudflare Turnstile on the Kanboard login form

Puts a [Cloudflare Turnstile](https://developers.cloudflare.com/turnstile/)
challenge on `/login` and verifies it server-side **before** the username and
password are looked at.

Kanboard already ships a CAPTCHA, but it only appears after a few failed
attempts, and it is a GD-rendered image that OCR reads without much trouble.
Turnstile runs on every login instead, is invisible for most visitors, and is
checked against Cloudflare rather than against a value in the session.

No core file is modified and no template is overridden.

---

## Install

A Kanboard plugin is a directory under `plugins/`, and the directory name is
the plugin's namespace — so this one has to land at `plugins/Turnstile`,
whichever way it gets there.

**From git:**

```
cd /path/to/kanboard/plugins
git clone https://github.com/kaiyichen0421/kanboard-cloudflare.git Turnstile
```

**From a zip** — for a host with no shell:

```
unzip Turnstile-x.y.z.zip -d /path/to/kanboard/plugins
```

The archive already contains the `Turnstile/` directory, so it unpacks straight
into `plugins/`. The same file works in **Settings → Plugins → Install from
URL** on an instance with `PLUGIN_INSTALLER` enabled.

Then reload the page. There is no cache to clear and no migration to run — the
plugin creates no table and writes two rows into Kanboard's own `settings`.

To uninstall, delete the directory. The two settings rows are all it leaves
behind, and they are inert without it.

### Requirements

Kanboard 1.2.0 or later, and outbound HTTPS from the server to
`challenges.cloudflare.com` — the verification is a server-to-server call, so
an instance behind an egress firewall needs that host allowed or every login
fails closed.

---

## Setup

**1. Create the widget.** In the Cloudflare dashboard, **Turnstile → Add
widget**. List every hostname the login page is served from — the widget
refuses any other one with error `110200`, and the list takes host names, not
IP addresses, so `127.0.0.1` can never be authorised. For a development
instance, add `localhost`.

**2. Paste the keys.** **Settings → Turnstile** (admin only). Site key and
secret key, then **Save**.

**3. Check it before you trust it.** **Test the connection** asks Cloudflare
whether the saved secret is usable, without going anywhere near the login form:

| Answer | Meaning |
|---|---|
| The secret key works. | Cloudflare recognised the secret. |
| Cloudflare rejected the secret key. | Wrong secret, or one from a deleted widget. |
| Could not reach Cloudflare: … | The server has no route to `challenges.cloudflare.com`. |

`siteverify` has no ping endpoint, so this posts a deliberately invalid token:
a working secret answers `invalid-input-response`, a broken one answers
`invalid-input-secret`. The two failures are told apart, which is the point —
"unreachable" and "rejected" need different fixes.

**Leaving both fields empty turns the challenge off.** The widget is not
rendered, the validator returns early, and the Content-Security-Policy is not
widened. That is also the state a fresh install is in, so installing the plugin
can never by itself lock anyone out.

### If you do lock yourself out

The check fails closed by design: once the keys are set, a login that Cloudflare
does not positively confirm is rejected. So a deleted widget, a key pasted with
a stray space, or a hostname that is not on the widget's list will keep
*everyone* out, admins included.

The way back in is the same two rows, cleared from a shell:

```sql
DELETE FROM settings WHERE option IN ('turnstile_site_key', 'turnstile_secret_key');
```

Or, with no database client at hand, delete `plugins/Turnstile/`. Either one
restores a plain login form on the next request.

The browser tells you which failure it was. Cloudflare's own error rendering is
a bare "Troubleshoot" link, so the plugin prints the code under the widget
instead: `110200` is a hostname that is not on the widget's list, `400xxx` an
invalid site key, `300xxx` a challenge that failed to execute, `600xxx` one
that timed out.

---

## How it works

### The gate

The challenge has to be checked before the credentials are, and Kanboard runs
exactly one chain for that — `AuthValidator::validateForm()`. `Plugin.php`
reassigns the container's `authValidator` service to a subclass that inserts one
more step into that chain:

```php
array('validateFields', 'validateLocking', 'validateCaptcha', 'validateTurnstile', 'validateCredentials')
```

Two things follow from the position. A request that fails the challenge never
reaches the password check, so posting straight to `/login/check` with the
widget skipped is refused — the token is not optional, it is a validator. And
because it sits *after* `validateLocking` but *before* `validateCredentials`, a
failed challenge neither counts towards Kanboard's brute-force lockout nor
resets it.

Anything other than a `"success": true` from Cloudflare is a rejected login,
including a request that could not be made at all. The user sees one message —
*Human verification failed, please try again.* — and the reason, with
Cloudflare's own error codes, goes to Kanboard's log rather than to the browser.

### The widget, and the input it needs

`template:auth:login-form:after` is the hook that renders under the password
field, and it fires **after** `</form>`. The token input Turnstile creates for
itself is therefore outside the form and is never posted.

Moving the widget into the form races against `api.js` rendering it, so
`Assets/js/turnstile.js` leaves it where it is and puts its own hidden input
inside the form instead. It fills that input twice over:

- from `data-callback`, which Turnstile fires as soon as the challenge is
  solved; and
- from the widget's own token when the form is submitted, if the callback never
  arrived.

The second one is not belt-and-braces, it is the fix for a real race. Kanboard
loads plugin scripts with `defer`, the widget loads `api.js` with `async`, and
`async` can win — Turnstile then resolves `data-callback` off `window` at render
time, finds nothing, and drops it silently. The symptom is the worst kind: a
widget that says **Success!** and a login that is rejected for having no token.
Reading the token back at submit time makes the load order irrelevant.

The script is a file rather than an inline block because Kanboard serves
`default-src 'self'`, which refuses inline scripts.

### Content-Security-Policy

Kanboard's default policy allows nothing off-origin, so `challenges.cloudflare.com`
is added to `script-src` and `frame-src` — but only when a site key is actually
saved. An installed-and-unconfigured plugin leaves the policy exactly as it
found it.

### Light and dark

The widget renders with `data-theme="auto"`, which follows the operating
system. Kanboard's own light/dark choice is per user and the login form is the
one screen where there is no user yet, so there is nothing else to follow.

---

## Translations

English is the source text; Traditional Chinese (`zh_TW`) ships in `Locale/`.
Any other language falls back to English, and a new one is a single
`Locale/<code>/translations.php` file.

---

## License

MIT — see [LICENSE](LICENSE).
