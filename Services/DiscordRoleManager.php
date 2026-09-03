<?php

namespace Paymenter\Extensions\Others\Discord\Services;

use App\Models\User;
use Paymenter\Extensions\Others\Discord\Models\DiscordUser;

class DiscordRoleManager
{
    public function __construct(
        private DiscordApi $api,
        private ?string $guildId,
        private ?string $customerRoleId,
    ) {}

    /**
     * Build a role manager from the saved Discord extension settings.
     */
    public static function fromExtension(): ?self
    {
        $extension = \App\Models\Extension::where('extension', 'Discord')
            ->where('type', 'other')
            ->first();

        if (!$extension) {
            return null;
        }

        $settings = $extension->settings->pluck('value', 'key')->toArray();
        $token = $settings['bot_token'] ?? null;

        if (!$token) {
            return null;
        }

        $api = new DiscordApi(
            $token,
            $settings['application_id'] ?? '',
            $settings['client_secret'] ?? '',
        );

        return new self(
            $api,
            $settings['guild_id'] ?? null,
            $settings['customer_role_id'] ?? null,
        );
    }

    /**
     * Assign or remove the customer role based on active services.
     */
    public function syncForUser(User $user, ?string $discordId = null): void
    {
        if (!$this->guildId || !$this->customerRoleId) {
            return;
        }

        if ($discordId === null) {
            $link = DiscordUser::where('user_id', $user->id)->first();
            if (!$link) {
                return;
            }
            $discordId = $link->discord_id;
        }

        $hasActive = $this->hasActiveServices($user);

        \Illuminate\Support\Facades\Log::debug('Discord customer role sync', [
            'user_id' => $user->id,
            'discord_id' => $discordId,
            'guild_id' => $this->guildId,
            'role_id' => $this->customerRoleId,
            'has_active_services' => $hasActive,
        ]);

        if ($hasActive) {
            $this->api->assignRole($this->guildId, $discordId, $this->customerRoleId);
        } else {
            $this->api->removeRole($this->guildId, $discordId, $this->customerRoleId);
        }
    }

    /**
     * Convenience wrapper when only the Discord ID is known.
     */
    public function syncForDiscordId(string $discordId): void
    {
        if (!$this->guildId || !$this->customerRoleId) {
            return;
        }

        $link = DiscordUser::where('discord_id', $discordId)->first();
        if (!$link || !$link->user) {
            return;
        }

        $this->syncForUser($link->user, $discordId);
    }

    /**
     * Remove the customer role from a Discord user.
     */
    public function removeCustomerRole(string $discordId): void
    {
        if (!$this->guildId || !$this->customerRoleId) {
            return;
        }

        $this->api->removeRole($this->guildId, $discordId, $this->customerRoleId);
    }

    /**
     * Determine whether a user currently has active services.
     */
    private function hasActiveServices(User $user): bool
    {
        return $user->services()
            ->where('status', 'active')
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->exists();
    }
}
