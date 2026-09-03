<?php

namespace Paymenter\Extensions\Others\Discord;

use App\Attributes\ExtensionMeta;
use App\Classes\Extension\Extension;
use App\Helpers\ExtensionHelper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Paymenter\Extensions\Others\Discord\Models\DiscordUser;
use Paymenter\Extensions\Others\Discord\Services\DiscordRoleManager;

#[ExtensionMeta(
    name: 'Discord Integration',
    description: 'Full Discord bot integration: account linking via OAuth, service/invoice/ticket management via slash commands, auto role assignment, and admin credit management.',
    version: '1.7.0',
    author: 'Buster4126',
)]
class Discord extends Extension
{
    public function getConfig($values = []): array
    {
        return [
            [
                'name' => 'bot_token',
                'label' => 'Bot Token',
                'type' => 'password',
                'description' => 'Your Discord bot token from the Developer Portal.',
                'required' => true,
                'validation' => 'required|string',
            ],
            [
                'name' => 'application_id',
                'label' => 'Application ID',
                'type' => 'text',
                'description' => 'Your Discord application/client ID.',
                'required' => true,
                'validation' => 'required|string',
            ],
            [
                'name' => 'public_key',
                'label' => 'Public Key',
                'type' => 'text',
                'description' => 'Your Discord application public key (for interaction signature verification). Found in Developer Portal → General Information.',
                'required' => true,
                'validation' => 'required|string',
            ],
            [
                'name' => 'client_secret',
                'label' => 'OAuth2 Client Secret',
                'type' => 'password',
                'description' => 'Your Discord OAuth2 client secret. Found in Developer Portal → OAuth2.',
                'required' => true,
                'validation' => 'required|string',
            ],
            [
                'name' => 'guild_id',
                'label' => 'Guild (Server) ID',
                'type' => 'text',
                'description' => 'Your Discord server ID. Used for guild-scoped commands (instant registration) and role management.',
                'required' => false,
            ],
            [
                'name' => 'linked_role_id',
                'label' => 'Linked Role ID',
                'type' => 'text',
                'description' => 'Role ID to automatically assign when a user links their account, and remove when they unlink.',
                'required' => false,
            ],
            [
                'name' => 'customer_role_id',
                'label' => 'Customer Role ID',
                'type' => 'text',
                'description' => 'Role ID to assign to linked users who have active services, and remove when they no longer have active services.',
                'required' => false,
            ],
            [
                'name' => 'admin_role_id',
                'label' => 'Admin Role ID(s)',
                'type' => 'text',
                'description' => 'Discord role ID(s) whose members can use all admin commands including /credit. Comma-separated for multiple roles (e.g. 123456,789012).',
                'required' => false,
            ],
            [
                'name' => 'supporter_role_id',
                'label' => 'Supporter Role ID(s)',
                'type' => 'text',
                'description' => 'Discord role ID(s) whose members can view services, invoices and tickets of other users, but cannot manage credits. Comma-separated for multiple roles.',
                'required' => false,
            ],
            [
                'name' => 'currency_code',
                'label' => 'Credit Currency',
                'type' => 'text',
                'description' => 'Currency code to use when adding/removing credits (e.g. USD, EUR).',
                'required' => false,
                'default' => 'USD',
            ],
        ];
    }

    public function boot(): void
    {
        require __DIR__ . '/routes/web.php';

        Event::listen('permissions', function () {
            return [
                'admin.discord.view' => 'View Discord Links',
                'admin.discord.delete' => 'Delete Discord Links',
            ];
        });

        // DM notification: new invoice (after items are added)
        Event::listen(\App\Events\Invoice\Finalized::class, function (\App\Events\Invoice\Finalized $event) {
            $invoice = $event->invoice;
            if (!$invoice->user_id) return;

            $link = DiscordUser::where('user_id', $invoice->user_id)->first();
            if (!$link) return;

            $api = $this->makeApi();
            if (!$api) return;

            $total = $invoice->items->sum(fn ($item) => $item->price * $item->quantity);
            $api->sendDm($link->discord_id, [
                'embeds' => [[
                    'title'       => '📄 New Invoice',
                    'description' => sprintf(
                        'Invoice **#%s** has been created for **%s %s**.',
                        $invoice->number ?: $invoice->id,
                        number_format($total, 2),
                        strtoupper($invoice->currency_code)
                    ),
                    'color'  => 0xFEE75C,
                    'fields' => [
                        ['name' => 'Status', 'value' => ucfirst($invoice->status), 'inline' => true],
                        ['name' => 'Due', 'value' => $invoice->due_at ? $invoice->due_at->format('Y-m-d') : 'N/A', 'inline' => true],
                        ['name' => 'Action', 'value' => sprintf('[Pay Now](%s)', route('invoices.show', $invoice->getRouteKey())), 'inline' => true],
                    ],
                    'footer' => ['text' => config('app.name')],
                ]],
            ]);
        });

        // DM notification: staff replied to a ticket
        Event::listen(\App\Events\TicketMessage\Created::class, function (\App\Events\TicketMessage\Created $event) {
            $message = $event->ticketMessage;
            $ticket  = $message->ticket;

            if (!$ticket) return;
            // Only notify the ticket owner when someone else replies
            if ($message->user_id === $ticket->user_id) return;

            $link = DiscordUser::where('user_id', $ticket->user_id)->first();
            if (!$link) return;

            $api = $this->makeApi();
            if (!$api) return;

            $api->sendDm($link->discord_id, [
                'embeds' => [[
                    'title'  => '🎫 New Reply on Your Ticket',
                    'color'  => 0xEB459E,
                    'fields' => [
                        ['name' => 'Ticket', 'value' => sprintf('#%d — %s', $ticket->id, \Illuminate\Support\Str::limit($ticket->subject, 50)), 'inline' => false],
                        ['name' => 'View', 'value' => sprintf('[🔗 Open Ticket](%s)', route('tickets.show', $ticket->id)), 'inline' => false],
                    ],
                    'footer' => ['text' => config('app.name')],
                ]],
            ]);
        });

        // DM notification: service suspended
        Event::listen('eloquent.updated: App\Models\Service', function (\App\Models\Service $service) {
            if (!$service->wasChanged('status') || $service->status !== 'suspended') return;
            if (!$service->user_id) return;

            $link = DiscordUser::where('user_id', $service->user_id)->first();
            if (!$link) return;

            $api = $this->makeApi();
            if (!$api) return;

            $api->sendDm($link->discord_id, [
                'embeds' => [[
                    'title'       => '⚠️ Service Suspended',
                    'description' => sprintf(
                        'Your service **%s** (#%d) has been **suspended**.',
                        $service->product->name ?? 'Unknown',
                        $service->id
                    ),
                    'color'  => 0xED4245,
                    'fields' => [
                        ['name' => 'View', 'value' => sprintf('[🔗 View Service](%s)', route('services.show', $service->id)), 'inline' => false],
                    ],
                    'footer' => ['text' => config('app.name')],
                ]],
            ]);
        });

        // DM notification: invoice paid
        Event::listen('eloquent.updated: App\Models\Invoice', function (\App\Models\Invoice $invoice) {
            if (!$invoice->wasChanged('status') || $invoice->status !== 'paid') return;
            if (!$invoice->user_id) return;

            $link = DiscordUser::where('user_id', $invoice->user_id)->first();
            if (!$link) return;

            $api = $this->makeApi();
            if (!$api) return;

            $total = $invoice->items->sum(fn ($item) => $item->price * $item->quantity);

            $api->sendDm($link->discord_id, [
                'embeds' => [[
                    'title'       => '✅ Payment Received',
                    'description' => sprintf(
                        'Invoice **#%s** has been paid. Thank you!',
                        $invoice->number ?: $invoice->id
                    ),
                    'color'  => 0x57F287,
                    'fields' => [
                        ['name' => 'Amount', 'value' => number_format($total, 2) . ' ' . strtoupper($invoice->currency_code), 'inline' => true],
                        ['name' => 'View', 'value' => sprintf('[🔗 View Invoice](%s)', route('invoices.show', $invoice->getRouteKey())), 'inline' => true],
                    ],
                    'footer' => ['text' => config('app.name')],
                ]],
            ]);
        });

        // DM notification: ticket closed
        Event::listen('eloquent.updated: App\Models\Ticket', function (\App\Models\Ticket $ticket) {
            if (!$ticket->wasChanged('status') || $ticket->status !== 'closed') return;
            if (!$ticket->user_id) return;

            $link = DiscordUser::where('user_id', $ticket->user_id)->first();
            if (!$link) return;

            $api = $this->makeApi();
            if (!$api) return;

            $api->sendDm($link->discord_id, [
                'embeds' => [[
                    'title'  => '🔒 Ticket Closed',
                    'color'  => 0x99AAB5,
                    'fields' => [
                        ['name' => 'Ticket', 'value' => sprintf('#%d — %s', $ticket->id, \Illuminate\Support\Str::limit($ticket->subject, 50)), 'inline' => false],
                        ['name' => 'View', 'value' => sprintf('[🔗 View Ticket](%s)', route('tickets.show', $ticket->id)), 'inline' => false],
                    ],
                    'footer' => ['text' => config('app.name')],
                ]],
            ]);
        });

        // DM notification: credit added (positive amount only)
        Event::listen('eloquent.created: App\Models\Credit', function (\App\Models\Credit $credit) {
            if ($credit->amount <= 0) return;
            if (!$credit->user_id) return;

            $link = DiscordUser::where('user_id', $credit->user_id)->first();
            if (!$link) return;

            $api = $this->makeApi();
            if (!$api) return;

            $api->sendDm($link->discord_id, [
                'embeds' => [[
                    'title'       => '💰 Credit Added',
                    'description' => sprintf(
                        '**%s %s** has been added to your account.',
                        number_format($credit->amount, 2),
                        strtoupper($credit->currency_code)
                    ),
                    'color'  => 0x57F287,
                    'footer' => ['text' => config('app.name')],
                ]],
            ]);
        });

        // Daily check: DM users whose services expire within 3 days
        \Illuminate\Support\Facades\Schedule::call(function () {
            $api = $this->makeApi();
            if (!$api) return;

            \App\Models\Service::where('status', 'active')
                ->whereBetween('expires_at', [now(), now()->addDays(3)])
                ->with(['user', 'product'])
                ->get()
                ->each(function ($service) use ($api) {
                    $cacheKey = "discord_expiry_notified:{$service->id}";
                    if (\Illuminate\Support\Facades\Cache::has($cacheKey)) return;

                    $link = DiscordUser::where('user_id', $service->user_id)->first();
                    if (!$link) return;

                    $sent = $api->sendDm($link->discord_id, [
                        'embeds' => [[
                            'title'       => '⚠️ Service Expiring Soon',
                            'description' => sprintf(
                                'Your service **%s** (#%d) expires <t:%d:R>.',
                                $service->product->name ?? 'Unknown',
                                $service->id,
                                $service->expires_at->timestamp
                            ),
                            'color'  => 0xED4245,
                            'fields' => [
                                ['name' => 'View', 'value' => sprintf('[🔗 View Service](%s)', route('services.show', $service->id)), 'inline' => false],
                            ],
                            'footer' => ['text' => config('app.name')],
                        ]],
                    ]);

                    if ($sent) {
                        \Illuminate\Support\Facades\Cache::put($cacheKey, true, now()->addDays(2));
                    }
                });
        })->daily();

        // Daily check: assign/remove customer role based on active services
        \Illuminate\Support\Facades\Schedule::call(function () {
            $manager = $this->makeRoleManager();
            if (!$manager) {
                return;
            }

            DiscordUser::with('user')->chunk(100, function ($links) use ($manager) {
                foreach ($links as $link) {
                    if (!$link->user) {
                        continue;
                    }

                    try {
                        $manager->syncForUser($link->user, $link->discord_id);
                    } catch (\Throwable $e) {
                        Log::warning('Discord: failed to sync customer role', [
                            'discord_id' => $link->discord_id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });
        })->daily();
    }

    private function makeApi(): ?\Paymenter\Extensions\Others\Discord\Services\DiscordApi
    {
        $token    = $this->config('bot_token');
        $clientId = $this->config('application_id');
        $secret   = $this->config('client_secret');

        if (!$token) return null;

        return new \Paymenter\Extensions\Others\Discord\Services\DiscordApi(
            $token,
            $clientId ?? '',
            $secret ?? ''
        );
    }

    private function makeRoleManager(): ?DiscordRoleManager
    {
        $api = $this->makeApi();
        if (!$api) {
            return null;
        }

        return new DiscordRoleManager(
            $api,
            $this->config('guild_id'),
            $this->config('customer_role_id'),
        );
    }

    public function installed(): void
    {
        ExtensionHelper::runMigrations('extensions/Others/Discord/database/migrations');
        // Commands are registered after settings are saved — see boot()
    }

    public function uninstalled(): void
    {
        $this->deleteDiscordCommands();

        // Directly drop the table instead of relying on file-based migration rollback,
        // which can fail if base_path() misconstructs the absolute path or the folder
        // has already been removed before this method is called.
        Schema::dropIfExists('ext_discord_users');
        DB::table('migrations')
            ->where('migration', '2024_01_01_000000_create_ext_discord_users_table')
            ->delete();
    }

    public function updated(): void
    {
        $this->registerDiscordCommands();
    }

    public function upgraded($oldVersion = null): void
    {
        ExtensionHelper::runMigrations('extensions/Others/Discord/database/migrations');
        $this->registerDiscordCommands();
    }

    public function registerDiscordCommands(): void
    {
        $appId = $this->config('application_id');
        $token = $this->config('bot_token');

        if (!$appId || !$token) {
            return;
        }

        $commands = [
            [
                'name'        => 'help',
                'description' => 'Show all available commands',
                'type'        => 1,
            ],
            [
                'name'        => 'staffhelp',
                'description' => 'Show all staff commands (Supporter & Admin only)',
                'type'        => 1,
            ],
            [
                'name' => 'link',
                'description' => 'Link your Paymenter account to your Discord account',
                'type' => 1,
            ],
            [
                'name' => 'unlink',
                'description' => 'Unlink your Paymenter account from Discord',
                'type' => 1,
            ],
            [
                'name' => 'refresh',
                'description' => 'Manually refresh your customer role based on active services',
                'type' => 1,
            ],
            [
                'name' => 'services',
                'description' => 'View your Paymenter services',
                'type' => 1,
                'options' => [
                    [
                        'type'        => 4,
                        'name'        => 'page',
                        'description' => 'Page number',
                        'required'    => false,
                        'min_value'   => 1,
                    ],
                ],
            ],
            [
                'name' => 'invoices',
                'description' => 'View your pending invoices',
                'type' => 1,
            ],
            [
                'name' => 'tickets',
                'description' => 'View your open support tickets',
                'type' => 1,
            ],
            [
                'name' => 'seeservices',
                'description' => 'View active services of a linked user (admin only)',
                'type' => 1,
                'default_member_permissions' => '0',
                'options' => [
                    ['type' => 6, 'name' => 'user', 'description' => 'The Discord user', 'required' => true],
                ],
            ],
            [
                'name' => 'seeinvoices',
                'description' => 'View invoices of a linked user (admin only)',
                'type' => 1,
                'default_member_permissions' => '0',
                'options' => [
                    ['type' => 6, 'name' => 'user', 'description' => 'The Discord user', 'required' => true],
                ],
            ],
            [
                'name' => 'seetickets',
                'description' => 'View support tickets of a linked user (admin only)',
                'type' => 1,
                'default_member_permissions' => '0',
                'options' => [
                    ['type' => 6, 'name' => 'user', 'description' => 'The Discord user', 'required' => true],
                ],
            ],
            [
                'name'        => 'balance',
                'description' => 'View your current credit balance',
                'type'        => 1,
            ],
            [
                'name'        => 'profile',
                'description' => 'View your Paymenter account summary',
                'type'        => 1,
            ],
            [
                'name'        => 'createticket',
                'description' => 'Create a support ticket from Discord',
                'type'        => 1,
                'options'     => [
                    ['type' => 3, 'name' => 'subject', 'description' => 'Ticket subject', 'required' => true],
                    ['type' => 3, 'name' => 'message', 'description' => 'Initial message', 'required' => true],
                    [
                        'type'        => 3,
                        'name'        => 'priority',
                        'description' => 'Priority level (default: normal)',
                        'required'    => false,
                        'choices'     => [
                            ['name' => 'Low',    'value' => 'low'],
                            ['name' => 'Medium', 'value' => 'medium'],
                            ['name' => 'High',   'value' => 'high'],
                        ],
                    ],
                ],
            ],
            [
                'name'        => 'config',
                'description' => 'Manage bot command configuration (Admin only)',
                'type'        => 1,
                'default_member_permissions' => '0',
                'options'     => [
                    [
                        'type'        => 1,
                        'name'        => 'list',
                        'description' => 'Show all commands and their enabled/disabled status',
                    ],
                    [
                        'type'        => 1,
                        'name'        => 'enable',
                        'description' => 'Enable a command',
                        'options'     => [
                            [
                                'type'        => 3,
                                'name'        => 'command',
                                'description' => 'The command to enable',
                                'required'    => true,
                                'choices'     => [
                                    ['name' => 'link',         'value' => 'link'],
                                    ['name' => 'unlink',       'value' => 'unlink'],
                                    ['name' => 'refresh',      'value' => 'refresh'],
                                    ['name' => 'balance',      'value' => 'balance'],
                                    ['name' => 'profile',      'value' => 'profile'],
                                    ['name' => 'services',     'value' => 'services'],
                                    ['name' => 'invoices',     'value' => 'invoices'],
                                    ['name' => 'tickets',      'value' => 'tickets'],
                                    ['name' => 'createticket', 'value' => 'createticket'],
                                    ['name' => 'staffhelp',    'value' => 'staffhelp'],
                                    ['name' => 'credit',       'value' => 'credit'],
                                    ['name' => 'seeservices',  'value' => 'seeservices'],
                                    ['name' => 'seeinvoices',  'value' => 'seeinvoices'],
                                    ['name' => 'seetickets',   'value' => 'seetickets'],
                                ],
                            ],
                        ],
                    ],
                    [
                        'type'        => 1,
                        'name'        => 'disable',
                        'description' => 'Disable a command',
                        'options'     => [
                            [
                                'type'        => 3,
                                'name'        => 'command',
                                'description' => 'The command to disable',
                                'required'    => true,
                                'choices'     => [
                                    ['name' => 'link',         'value' => 'link'],
                                    ['name' => 'unlink',       'value' => 'unlink'],
                                    ['name' => 'refresh',      'value' => 'refresh'],
                                    ['name' => 'balance',      'value' => 'balance'],
                                    ['name' => 'profile',      'value' => 'profile'],
                                    ['name' => 'services',     'value' => 'services'],
                                    ['name' => 'invoices',     'value' => 'invoices'],
                                    ['name' => 'tickets',      'value' => 'tickets'],
                                    ['name' => 'createticket', 'value' => 'createticket'],
                                    ['name' => 'staffhelp',    'value' => 'staffhelp'],
                                    ['name' => 'credit',       'value' => 'credit'],
                                    ['name' => 'seeservices',  'value' => 'seeservices'],
                                    ['name' => 'seeinvoices',  'value' => 'seeinvoices'],
                                    ['name' => 'seetickets',   'value' => 'seetickets'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            [
                'name' => 'credit',
                'description' => 'Manage user credits (admin only)',
                'type' => 1,
                'default_member_permissions' => '0',
                'options' => [
                    [
                        'type' => 1,
                        'name' => 'add',
                        'description' => 'Add credits to a user',
                        'options' => [
                            ['type' => 6, 'name' => 'user', 'description' => 'The Discord user', 'required' => true],
                            ['type' => 10, 'name' => 'amount', 'description' => 'Amount to add', 'required' => true],
                        ],
                    ],
                    [
                        'type' => 1,
                        'name' => 'remove',
                        'description' => 'Remove credits from a user',
                        'options' => [
                            ['type' => 6, 'name' => 'user', 'description' => 'The Discord user', 'required' => true],
                            ['type' => 10, 'name' => 'amount', 'description' => 'Amount to remove', 'required' => true],
                        ],
                    ],
                ],
            ],
            [
                'name' => 'eventcreate',
                'description' => 'Create and manage event embeds (admin only)',
                'type' => 1,
                'default_member_permissions' => '0',
            ],
        ];

        $guildId = $this->config('guild_id');
        $endpoint = $guildId
            ? "applications/{$appId}/guilds/{$guildId}/commands"
            : "applications/{$appId}/commands";

        Http::withToken($token, 'Bot')
            ->put("https://discord.com/api/v10/{$endpoint}", $commands);
    }

    private function deleteDiscordCommands(): void
    {
        $appId = $this->config('application_id');
        $token = $this->config('bot_token');

        if (!$appId || !$token) {
            return;
        }

        $guildId = $this->config('guild_id');
        $endpoint = $guildId
            ? "applications/{$appId}/guilds/{$guildId}/commands"
            : "applications/{$appId}/commands";

        Http::withToken($token, 'Bot')
            ->put("https://discord.com/api/v10/{$endpoint}", []);
    }
}
