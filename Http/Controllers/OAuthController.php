<?php

namespace Paymenter\Extensions\Others\Discord\Http\Controllers;

use App\Models\Extension;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Paymenter\Extensions\Others\Discord\Models\DiscordUser;
use Paymenter\Extensions\Others\Discord\Services\DiscordApi;
use Paymenter\Extensions\Others\Discord\Services\DiscordRoleManager;

class OAuthController extends Controller
{
    private const DISCORD_OAUTH_URL = 'https://discord.com/oauth2/authorize';

    public function redirect(Request $request): RedirectResponse
    {
        $state = $request->query('state');

        if (!$state || !Cache::has("discord_link:{$state}")) {
            abort(400, 'Invalid or expired link token. Please run /link again in Discord.');
        }

        // Store state in session so callback can retrieve it
        session(['discord_link_state' => $state]);

        $params = http_build_query([
            'client_id'     => $this->cfg('application_id'),
            'redirect_uri'  => route('discord.oauth.callback'),
            'response_type' => 'code',
            'scope'         => 'identify',
            'state'         => $state,
        ]);

        return redirect(self::DISCORD_OAUTH_URL . '?' . $params);
    }

    public function callback(Request $request): RedirectResponse
    {
        $code  = $request->query('code');
        $state = $request->query('state');

        if (!$code || !$state) {
            return redirect()->route('dashboard')
                ->with('error', 'Discord OAuth was cancelled or failed.');
        }

        // Validate state matches what we stored in session and cache
        $sessionState = session('discord_link_state');
        if ($sessionState !== $state || !Cache::has("discord_link:{$state}")) {
            return redirect()->route('dashboard')
                ->with('error', 'Invalid or expired link token. Please run /link again in Discord.');
        }

        $discordId = Cache::get("discord_link:{$state}");

        // User must be authenticated in Paymenter
        if (!Auth::check()) {
            // Store state in session and redirect to login
            session(['discord_link_pending_state' => $state]);
            return redirect()->route('login')
                ->with('info', 'Please log in to link your Discord account.');
        }

        // Check if Discord account is already linked to a different user
        $existingLink = DiscordUser::where('discord_id', $discordId)->first();
        if ($existingLink && $existingLink->user_id !== Auth::id()) {
            Cache::forget("discord_link:{$state}");
            return redirect()->route('dashboard')
                ->with('error', 'This Discord account is already linked to a different Paymenter account.');
        }

        // Exchange code for access token
        $api      = $this->makeApi();
        $tokens   = $api->exchangeCode($code, route('discord.oauth.callback'));

        if (!$tokens || empty($tokens['access_token'])) {
            return redirect()->route('dashboard')
                ->with('error', 'Failed to exchange Discord OAuth code. Please try again.');
        }

        // Get Discord user profile
        $discordUser = $api->getUser($tokens['access_token']);

        if (!$discordUser || empty($discordUser['id'])) {
            return redirect()->route('dashboard')
                ->with('error', 'Failed to retrieve Discord user information. Please try again.');
        }

        // Create or update the link
        DiscordUser::updateOrCreate(
            ['user_id' => Auth::id()],
            [
                'discord_id'            => $discordUser['id'],
                'discord_username'      => $discordUser['username'],
                'discord_discriminator' => $discordUser['discriminator'] ?? '0',
                'discord_avatar'        => $discordUser['avatar'],
            ]
        );

        // Assign the linked role if configured
        $this->assignLinkedRole($discordUser['id']);

        // Sync customer role based on active services
        $this->syncCustomerRole(Auth::user(), $discordUser['id']);

        // Cleanup
        Cache::forget("discord_link:{$state}");
        session()->forget('discord_link_state');

        return redirect()->route('dashboard')
            ->with('success', "Discord account **{$discordUser['username']}** has been successfully linked!");
    }

    private function assignLinkedRole(string $discordId): void
    {
        $guildId = $this->cfg('guild_id');
        $roleId  = $this->cfg('linked_role_id');

        if ($guildId && $roleId) {
            try {
                $this->makeApi()->assignRole($guildId, $discordId, $roleId);
            } catch (\Throwable $e) {
                Log::warning('Discord: failed to assign linked role', ['error' => $e->getMessage()]);
            }
        }
    }

    private function syncCustomerRole(User $user, string $discordId): void
    {
        try {
            $manager = DiscordRoleManager::fromExtension();
            if ($manager) {
                $manager->syncForUser($user, $discordId);
            }
        } catch (\Throwable $e) {
            Log::warning('Discord: failed to sync customer role on link', ['error' => $e->getMessage()]);
        }
    }

    private function makeApi(): DiscordApi
    {
        return new DiscordApi(
            botToken:     $this->cfg('bot_token') ?? '',
            clientId:     $this->cfg('application_id') ?? '',
            clientSecret: $this->cfg('client_secret') ?? '',
        );
    }

    private function cfg(string $key): ?string
    {
        static $extension = null;
        $extension ??= Extension::where('extension', 'Discord')->where('type', 'other')->first();
        return $extension?->settings()->where('key', $key)->first()?->value;
    }
}
