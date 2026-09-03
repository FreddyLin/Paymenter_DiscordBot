<?php

namespace Paymenter\Extensions\Others\Discord\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DiscordApi
{
    private const BASE = 'https://discord.com/api/v10';

    public function __construct(
        private string $botToken,
        private string $clientId,
        private string $clientSecret,
    ) {}

    public function getUser(string $accessToken): ?array
    {
        $response = Http::withToken($accessToken, 'Bearer')
            ->get(self::BASE . '/users/@me');

        return $response->successful() ? $response->json() : null;
    }

    public function exchangeCode(string $code, string $redirectUri): ?array
    {
        $response = Http::asForm()->post(self::BASE . '/oauth2/token', [
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => $redirectUri,
        ]);

        return $response->successful() ? $response->json() : null;
    }

    public function assignRole(string $guildId, string $userId, string $roleId): bool
    {
        $response = Http::withToken($this->botToken, 'Bot')
            ->put(self::BASE . "/guilds/{$guildId}/members/{$userId}/roles/{$roleId}");

        if (!$response->successful()) {
            Log::warning('Discord API: failed to assign role', [
                'guild_id' => $guildId,
                'user_id' => $userId,
                'role_id' => $roleId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        }

        return $response->successful();
    }

    public function removeRole(string $guildId, string $userId, string $roleId): bool
    {
        $response = Http::withToken($this->botToken, 'Bot')
            ->delete(self::BASE . "/guilds/{$guildId}/members/{$userId}/roles/{$roleId}");

        if (!$response->successful()) {
            Log::warning('Discord API: failed to remove role', [
                'guild_id' => $guildId,
                'user_id' => $userId,
                'role_id' => $roleId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        }

        return $response->successful();
    }

    public function sendDm(string $discordUserId, array $payload): bool
    {
        // Open DM channel
        $channel = Http::withToken($this->botToken, 'Bot')
            ->post(self::BASE . '/users/@me/channels', ['recipient_id' => $discordUserId]);

        if (!$channel->successful()) {
            return false;
        }

        $channelId = $channel->json('id');

        $response = Http::withToken($this->botToken, 'Bot')
            ->post(self::BASE . "/channels/{$channelId}/messages", $payload);

        return $response->successful();
    }

    public function getMember(string $guildId, string $userId): ?array
    {
        $response = Http::withToken($this->botToken, 'Bot')
            ->get(self::BASE . "/guilds/{$guildId}/members/{$userId}");

        return $response->successful() ? $response->json() : null;
    }

    public function sendMessage(string $channelId, array $payload): bool
    {
        $response = Http::withToken($this->botToken, 'Bot')
            ->post(self::BASE . "/channels/{$channelId}/messages", $payload);

        return $response->successful();
    }
}
