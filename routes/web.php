<?php

use Illuminate\Support\Facades\Route;
use Paymenter\Extensions\Others\Discord\Http\Controllers\InteractionsController;
use Paymenter\Extensions\Others\Discord\Http\Controllers\OAuthController;
use Paymenter\Extensions\Others\Discord\Http\Middleware\VerifyDiscordRequest;

// Discord Interactions Endpoint (no auth, no CSRF — signature-verified by middleware)
Route::post('/discord/interactions', [InteractionsController::class, 'handle'])
    ->middleware(VerifyDiscordRequest::class)
    ->name('discord.interactions');

// OAuth account linking
Route::middleware(['web'])->group(function () {
    // Entry point – user clicks the link sent by the bot (no auth required yet)
    Route::get('/discord/oauth/redirect', [OAuthController::class, 'redirect'])
        ->name('discord.oauth.redirect');

    // Discord redirects here after the user authorises
    Route::get('/discord/oauth/callback', [OAuthController::class, 'callback'])
        ->name('discord.oauth.callback');
});
