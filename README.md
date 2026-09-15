# TwoTen GoTo Webinar for Gravity Forms

A standalone WordPress plugin that registers Gravity Forms submissions as GoTo Webinar registrants. It is a proper Gravity Forms feed add-on (per-form feeds, field mapping, conditional logic, entry notes and logging) and it updates itself from this repository's GitHub releases.

- **Plugin folder:** `twoten-goto-webinar-gravityforms`
- **Requires:** WordPress 6.0+, PHP 7.4+, Gravity Forms 2.5+
- **GoTo API:** OAuth 2.0 + GoTo Webinar REST v2

## Setup

### 1. Create a GoTo OAuth client

1. Sign in at [developer.goto.com](https://developer.goto.com/) with the GoTo account that organizes the webinars.
2. Create an OAuth client and give it access to the **GoTo Webinar** product/scopes.
3. Add the plugin's **Redirect URI** to the client. It is shown on the settings page and is normally:

   ```
   https://your-site.example/wp-json/twoten-gtw/v1/oauth
   ```

4. Copy the **Client ID** and **Client Secret**.

### 2. Connect WordPress

1. Install and activate the plugin (Gravity Forms must already be active).
2. Go to **Forms → Settings → GoTo Webinar**.
3. Paste the Client ID and Client Secret and click **Save Settings**.
4. Click **Connect to GoTo Webinar**, sign in as the organizer and approve access. You are returned to the settings page showing who is connected.

Tokens are refreshed automatically. Use **Disconnect** to revoke the stored connection.

### 3. Add a feed to a form

1. Open the form and go to **Settings → GoTo Webinar → Add New**.
2. Give the feed a name and choose the **Webinar**. The list shows upcoming webinars for the connected organizer (cached for 10 minutes; use *Refresh webinar list* after creating a new webinar in GoTo).
3. Map **Registrant Fields**. The list reflects the registration fields enabled on that webinar in GoTo. First name, last name and email are always required.
4. Map any **Custom Questions** defined on the webinar. For multiple-choice questions the submitted value must match one of the answer options (shown in brackets), so use a Drop Down or Radio field with matching choices.
5. Optionally enable **Conditional Logic** and the **re-send confirmation** option, then save.

Create several feeds on one form (with conditional logic) to route people to different webinars.

## What happens on submission

- A registrant is created in GoTo Webinar. GoTo sends its own confirmation email.
- If the email address is already registered, GoTo returns a conflict; the plugin records this as `ALREADY_REGISTERED` and does not treat it as an error.
- The result is written to the entry as meta (visible in the entry detail sidebar, entry list columns and exports) and as an entry note:

  | Entry meta               | Merge tag                         |
  | ------------------------ | --------------------------------- |
  | `tts_gtw_join_url`       | `{goto_webinar:join_url}`         |
  | `tts_gtw_registrant_key` | `{goto_webinar:registrant_key}`   |
  | `tts_gtw_status`         | `{goto_webinar:status}`           |
  | `tts_gtw_webinar_key`    | `{goto_webinar:webinar_key}`      |

  The merge tags work in notifications and confirmations because feeds are processed before they are sent.

- Failures are logged via Gravity Forms logging (**Forms → Settings → Logging**, enable *GoTo Webinar for Gravity Forms*) and added as an error note on the entry.

## Updates

The plugin uses the WordPress `Update URI` mechanism and reads the **latest release** of this repository. When a newer release exists it appears on **Plugins** and **Dashboard → Updates** and installs like any other plugin update.

There is a **Check for updates** button in two places: on the plugin row on the Plugins screen, and under *Plugin Updates* on the GoTo Webinar settings page. It bypasses the six-hour cache and reports whether a newer version is available.

### Publishing a release

1. Bump the version in three places: the `Version:` header and the `TTS_GTW_VERSION` constant in `twoten-goto-webinar-gravityforms.php`, and `Stable tag:` in `readme.txt`. Add a `CHANGELOG.md` entry.
2. Commit, then tag and push:

   ```bash
   git tag v1.1.0 && git push origin main --tags
   ```

3. The *Release* GitHub Action verifies the version, lints the PHP, builds `twoten-goto-webinar-gravityforms.zip` (with `.github` and dot-files excluded via `.gitattributes`) and publishes the release with generated notes.

Sites pick the release up within six hours, or immediately via **Check for updates**.

### Private repository

If this repository is private, each site needs a read-only token so it can see releases and download the package. Create a fine-grained personal access token with *Contents: Read* on this repository and add it to `wp-config.php`:

```php
define( 'TTS_GTW_GITHUB_TOKEN', 'github_pat_...' );
```

The token is only ever sent to `api.github.com` and `codeload.github.com` for this repository.

## Hooks

| Hook | Type | Purpose |
| --- | --- | --- |
| `tts_gtw_registrant_data` | filter | Modify the registrant payload before it is sent (`$registrant, $feed, $entry, $form`). |
| `tts_gtw_after_registration` | action | Runs after a successful registration (`$result, $registrant, $feed, $entry, $form`). |
| `tts_gtw_redirect_uri` | filter | Override the OAuth redirect URI. |
| `tts_gtw_oauth_scope` | filter | Add a `scope` parameter to the authorization request (empty by default). |
| `tts_gtw_webinar_lookahead` | filter | Seconds ahead to list webinars (default one year). |
| `tts_gtw_webinar_lookback` | filter | Seconds back to list webinars (default one day). |
| `tts_gtw_github_updates` | filter | Return `false` to disable GitHub update checks. |
| `tts_gtw_github_token` | filter | Supply the GitHub token programmatically. |

## Uninstall

The **Uninstall** button under Forms → Settings → GoTo Webinar removes feeds and add-on settings (Gravity Forms handles this) and clears the stored connection. Deleting the plugin from the Plugins screen also removes the stored connection and caches.
