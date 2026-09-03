# Discord Integration — Paymenter Extension

A full Discord bot integration for [Paymenter](https://paymenter.org), allowing users to link their accounts via OAuth2 and manage services, invoices, and tickets directly from Discord slash commands.

**Author:** Buster4126
**Version:** 1.5
**License:** Commercial — All rights reserved

> **This is a paid extension.** A valid license is required for use in production.
> Unauthorized redistribution or use without a valid license is strictly prohibited.

---

## Features

- **Account Linking** via Discord OAuth2
- **Auto Role Assignment** when a user links/unlinks their account
- **DM Notifications** for new invoices, ticket replies, and expiring services
- **Slash Commands** for users:
  - `/help` — List all available commands
  - `/link` — Link your Paymenter account
  - `/unlink` — Unlink your account
  - `/profile` — View your account summary
  - `/balance` — View your credit balance
  - `/services` — View your active services
  - `/invoices` — View your pending invoices
  - `/tickets` — View your open support tickets
  - `/createticket` — Create a support ticket from Discord
- **Admin Slash Commands** (restricted by role):
  - `/staffhelp` — List all staff commands
  - `/seeservices @user` — View services of a linked user
  - `/seeinvoices @user` — View invoices of a linked user
  - `/seetickets @user` — View tickets of a linked user
  - `/credit add @user <amount>` — Add credits to a user
  - `/credit remove @user <amount>` — Remove credits from a user
  - `/config list` — View all commands and their enabled/disabled status
  - `/config enable <command>` — Re-enable a disabled command
  - `/config disable <command>` — Disable a command
- **Admin Panel** — View and manage all linked Discord accounts

---

## Requirements

- Paymenter (latest version)
- PHP with the `sodium` extension enabled (standard on most VPS setups)
- A Discord application & bot ([Discord Developer Portal](https://discord.com/developers/applications))
- Your Paymenter instance must be publicly reachable over HTTPS
- A valid license key

---

## Step 1 — Create a Discord Application

1. Go to [discord.com/developers/applications](https://discord.com/developers/applications) and click **New Application**
2. Give it a name (e.g. your company name) and confirm

---

## Step 2 — Create a Bot

1. In your application, go to **Bot** in the left sidebar
2. Click **Add Bot** (or the bot is already created)
3. Under **Token**, click **Reset Token** and copy it — this is your **Bot Token**
4. Scroll down to **Privileged Gateway Intents** and enable:
   - **Server Members Intent**
5. Save changes

---

## Step 3 — Collect Your Credentials

Open **General Information** in the left sidebar and copy:
- **Application ID**
- **Public Key**

Open **OAuth2** in the left sidebar and copy:
- **Client Secret** (click Reset Secret if needed)

You now have all 4 credentials needed for the extension.

---

## Step 4 — Install the Extension in Paymenter

1. Copy the `Discord` folder into your Paymenter extensions directory:
   ```
   extensions/Others/Discord/
   ```
2. Go to **Admin Panel → Extensions → Others**
3. Find **Discord Integration** and click **Install**
4. Fill in all configuration fields:

| Field | Where to find it |
|---|---|
| **Bot Token** | Discord Developer Portal → Bot → Token |
| **Application ID** | Discord Developer Portal → General Information |
| **Public Key** | Discord Developer Portal → General Information |
| **OAuth2 Client Secret** | Discord Developer Portal → OAuth2 → Client Secret |
| **Guild ID** | Right-click your Discord server → Copy Server ID *(optional but recommended for instant command registration)* |
| **Linked Role ID** | Right-click the role in your server → Copy Role ID *(optional)* |
| **Admin Role ID** | Right-click the role in your server → Copy Role ID *(optional)* |
| **Supporter Role ID** | Right-click the role in your server → Copy Role ID *(optional)* |
| **Credit Currency** | e.g. `USD` or `EUR` *(optional, defaults to USD)* |

> **Note:** To copy IDs in Discord, you must have **Developer Mode** enabled.
> Go to Discord **Settings → Advanced → Developer Mode** and toggle it on.

5. Click **Save** — the slash commands are now registered automatically

---

## Step 5 — Add the OAuth2 Redirect URL

1. In the Discord Developer Portal, go to **OAuth2**
2. Under **Redirects**, click **Add Redirect** and enter:
   ```
   https://YOUR_DOMAIN/discord/oauth/callback
   ```
   Replace `YOUR_DOMAIN` with your actual domain (e.g. `panel.example.com`)
3. Click **Save Changes**

---

## Step 6 — Set the Interactions Endpoint URL

> **Important:** Complete Step 4 (save the extension settings including the Public Key) **before** doing this step. Discord will immediately verify the URL using the Public Key — if it is not saved yet, validation will fail.

1. In the Discord Developer Portal, go to **General Information**
2. Under **Interactions Endpoint URL**, enter:
   ```
   https://YOUR_DOMAIN/discord/interactions
   ```
3. Click **Save Changes** — Discord will send a test request to verify the endpoint

---

## Step 7 — Invite the Bot to Your Server

1. In the Discord Developer Portal, go to **OAuth2 → URL Generator**
2. Under **Scopes**, select:
   - `bot`
   - `applications.commands`
3. Under **Bot Permissions**, select:
   - `Manage Roles` *(only needed if you use the Linked Role feature)*
   - `Send Messages`
4. Copy the generated URL, open it in your browser, and invite the bot to your server

---

## Troubleshooting

**"The provided interactions endpoint URL could not be verified"**
→ Make sure you have saved the extension settings with the correct **Public Key** in Paymenter *before* entering the URL in the Developer Portal.

**"Bot didn't respond in time"**
→ Your Paymenter instance must be reachable over HTTPS from the internet. Check that port 443 is open and your SSL certificate is valid.

**Slash commands don't appear in Discord**
→ If you set a **Guild ID**, commands appear instantly. Without a Guild ID, global commands can take up to 1 hour to propagate.
→ Make sure the bot has been invited with the `applications.commands` scope (see Step 7).
→ You can manually re-register all commands via SSH from your Paymenter directory:
```bash
php artisan tinker --execute="
\$settings = \App\Models\Extension::where('extension', 'Discord')->where('type', 'other')->first()->settings->pluck('value', 'key')->toArray();
\$discord = new \Paymenter\Extensions\Others\Discord\Discord(\$settings);
\$discord->registerDiscordCommands();
echo 'Done';
"
```

**`/link` gives an error or the OAuth flow doesn't work**
→ Double-check that the redirect URL in **OAuth2 → Redirects** matches exactly:
`https://YOUR_DOMAIN/discord/oauth/callback`

**"Permission denied" error on storage/logs when installing/updating**
→ Run this in your Paymenter directory via SSH to fix the file ownership:
```bash
chown -R www-data:www-data storage/
```
Then try installing/updating the extension again.
> This happens when Artisan commands were previously run as `root`. To avoid it in the future, prefix artisan commands with `sudo -u www-data`.

**"Permission denied" when uploading the extension (rename ... Permission denied)**
→ The web server has no write access to the `extensions/` folder. Fix it with:
```bash
chown -R www-data:www-data extensions/
```
Then upload the extension again.

---

## Updating the Extension

1. Go to **Admin Panel → Extensions → Others → Discord Integration** and click **Uninstall**
2. Replace the `Discord` folder with the new version
3. Go back to **Admin Panel → Extensions → Others** and click **Install**
4. Re-enter your configuration and save — slash commands will be re-registered automatically

**After updating, clear the Laravel cache** via SSH from your Paymenter directory:
```bash
sudo -u www-data php artisan cache:clear
```

Additionally, if applicable:
- **Queue Worker** running (`QUEUE_CONNECTION=redis` or `database`) → restart it:
  ```bash
  sudo -u www-data php artisan queue:restart
  ```
- **PHP Opcache** enabled → reload PHP-FPM or the web server so the new code is picked up:
  ```bash
  sudo systemctl reload php8.x-fpm
  ```

---

## Changelog

### v1.5
- Added `/config list|enable|disable` — Admins can now enable/disable individual commands without editing code
- Fixed migration path bug that prevented the `ext_discord_users` table from being created on install

### v1.4
- Initial public release
