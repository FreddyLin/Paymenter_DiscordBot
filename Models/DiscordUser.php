<?php

namespace Paymenter\Extensions\Others\Discord\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DiscordUser extends Model
{
    protected $table = 'ext_discord_users';

    protected $fillable = [
        'user_id',
        'discord_id',
        'discord_username',
        'discord_discriminator',
        'discord_avatar',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getAvatarUrlAttribute(): string
    {
        if ($this->discord_avatar) {
            return "https://cdn.discordapp.com/avatars/{$this->discord_id}/{$this->discord_avatar}.png";
        }

        $index = (int) ((int) $this->discord_discriminator === 0
            ? (((int) $this->discord_id >> 22) % 6)
            : ($this->discord_discriminator % 5));

        return "https://cdn.discordapp.com/embed/avatars/{$index}.png";
    }

    public function getDisplayNameAttribute(): string
    {
        $discriminator = $this->discord_discriminator;
        return ($discriminator && $discriminator !== '0')
            ? "{$this->discord_username}#{$discriminator}"
            : $this->discord_username;
    }
}
