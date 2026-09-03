<?php

namespace Paymenter\Extensions\Others\Discord\Http\Controllers;

use App\Models\Credit;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Paymenter\Extensions\Others\Discord\Models\DiscordUser;
use Paymenter\Extensions\Others\Discord\Services\DiscordApi;
use Paymenter\Extensions\Others\Discord\Services\DiscordRoleManager;

class InteractionsController extends Controller
{
    private array $interaction;
    private ?array $extensionSettings = null;

    private function makeApi(): ?\Paymenter\Extensions\Others\Discord\Services\DiscordApi
    {
        $extension = \App\Models\Extension::where('extension', 'Discord')->where('type', 'other')->first();
        if (!$extension) return null;

        $settings = $extension->settings->pluck('value', 'key')->toArray();
        $token = $settings['bot_token'] ?? null;
        $clientId = $settings['application_id'] ?? null;
        $secret = $settings['client_secret'] ?? null;

        if (!$token) return null;

        return new \Paymenter\Extensions\Others\Discord\Services\DiscordApi(
            $token,
            $clientId ?? '',
            $secret ?? ''
        );
    }

    public function handle(Request $request): JsonResponse
    {
        $this->interaction = $request->all();
        $type = $this->interaction['type'];

        // Respond to Discord's PING verification
        if ($type === 1) {
            return response()->json(['type' => 1]);
        }

        // Slash commands
        if ($type === 2) {
            return $this->handleCommand();
        }

        // Button / component interactions
        if ($type === 3) {
            return $this->handleComponent();
        }

        // Modal interactions
        if ($type === 5) {
            return $this->handleModalInteraction();
        }

        return response()->json(['type' => 1]);
    }

    private function handleComponent(): JsonResponse
    {
        $customId = $this->interaction['data']['custom_id'] ?? '';

        if (str_starts_with($customId, 'services_page_')) {
            $page = (int) substr($customId, strlen('services_page_'));
            return $this->cmdServices($page, updateMessage: true);
        }

        if (str_starts_with($customId, 'set_') || str_starts_with($customId, 'add_') || str_starts_with($customId, 'remove_') || $customId === 'load_template' || $customId === 'save_template' || $customId === 'delete_template' || $customId === 'post_embed' || $customId === 'send_event') {
            return $this->handleEventCreateComponent();
        }

        return response()->json(['type' => 1]);
    }

    private const CONFIGURABLE_COMMANDS = [
        'link', 'unlink', 'refresh', 'balance', 'profile', 'services',
        'invoices', 'tickets', 'createticket', 'staffhelp',
        'credit', 'seeservices', 'seeinvoices', 'seetickets',
    ];

    private function handleCommand(): JsonResponse
    {
        $name = $this->interaction['data']['name'];

        if (!in_array($name, ['help', 'config']) && $this->isCommandDisabled($name)) {
            return $this->reply('❌ This command is currently disabled.');
        }

        return match ($name) {
            'help'         => $this->cmdHelp(),
            'staffhelp'    => $this->cmdStaffHelp(),
            'link'         => $this->cmdLink(),
            'unlink'       => $this->cmdUnlink(),
            'refresh'      => $this->cmdRefresh(),
            'balance'      => $this->cmdBalance(),
            'profile'      => $this->cmdProfile(),
            'services'     => $this->cmdServices(),
            'invoices'     => $this->cmdInvoices(),
            'tickets'      => $this->cmdTickets(),
            'createticket' => $this->cmdCreateTicket(),
            'credit'       => $this->cmdCredit(),
            'seeservices'  => $this->cmdSeeServices(),
            'seeinvoices'  => $this->cmdSeeInvoices(),
            'seetickets'   => $this->cmdSeeTickets(),
            'config'       => $this->cmdConfig(),
            'eventcreate'  => $this->cmdEventCreate(),
            default        => $this->reply('Unknown command.'),
        };
    }

    private function handleModalInteraction(): JsonResponse
    {
        $customId = $this->interaction['data']['custom_id'];

        if (str_starts_with($customId, 'modal_set_') || str_starts_with($customId, 'modal_add_') || $customId === 'modal_save_template' || $customId === 'modal_delete_template' || $customId === 'modal_remove_field' || $customId === 'modal_remove_link') {
            if (str_starts_with($customId, 'modal_remove_')) {
                return $this->handleModalRemove();
            } else {
                return $this->handleModalSubmit();
            }
        }

        return $this->reply('❌ Unknown modal interaction.');
    }

    // -------------------------------------------------------------------------
    // /help
    // -------------------------------------------------------------------------
    private function cmdHelp(): JsonResponse
    {
        return $this->replyEmbed([
            'title'       => '📖 Available Commands',
            'description' => 'Here is a list of all commands you can use:',
            'color'       => 0x5865F2,
            'fields'      => [
                [
                    'name'   => '🔗 `/link`',
                    'value'  => 'Link your Paymenter account to your Discord account.',
                    'inline' => false,
                ],
                [
                    'name'   => '🔓 `/unlink`',
                    'value'  => 'Unlink your Paymenter account from Discord.',
                    'inline' => false,
                ],
                [
                    'name'   => '👤 `/profile`',
                    'value'  => 'View your account summary: credits, services, tickets, and invoices.',
                    'inline' => false,
                ],
                [
                    'name'   => '💰 `/balance`',
                    'value'  => 'View your current credit balance.',
                    'inline' => false,
                ],
                [
                    'name'   => '🖥️ `/services [page]`',
                    'value'  => 'View your Paymenter services. Use the optional `page` number to browse through multiple pages.',
                    'inline' => false,
                ],
                [
                    'name'   => '📄 `/invoices`',
                    'value'  => 'View your pending invoices.',
                    'inline' => false,
                ],
                [
                    'name'   => '🎫 `/tickets`',
                    'value'  => 'View your open support tickets.',
                    'inline' => false,
                ],
                [
                    'name'   => '🎫 `/createticket <subject> <message> [priority]`',
                    'value'  => 'Create a support ticket directly from Discord.',
                    'inline' => false,
                ],
                [
                    'name'   => '🔔 Automatic DM Notifications',
                    'value'  => implode("\n", [
                        '• New invoice created',
                        '• Invoice paid',
                        '• Credits added to your account',
                        '• Staff reply on your ticket',
                        '• Ticket closed',
                        '• Service suspended',
                        '• Service expiring within 3 days',
                    ]),
                    'inline' => false,
                ],
            ],
            'footer' => ['text' => config('app.name') . ' · Use /link to get started'],
        ]);
    }

    // -------------------------------------------------------------------------
    // /staffhelp
    // -------------------------------------------------------------------------
    private function cmdStaffHelp(): JsonResponse
    {
        if (!$this->isSupporter()) {
            return $this->reply('❌ You do not have permission to use this command.');
        }

        $fields = [
            [
                'name'   => '🎧 Supporter Commands',
                'value'  => implode("\n", [
                    '`/seeservices @user` — View services of a linked user',
                    '`/seeinvoices @user` — View invoices of a linked user',
                    '`/seetickets @user` — View tickets of a linked user',
                ]),
                'inline' => false,
            ],
        ];

        if ($this->isAdmin()) {
            $fields[] = [
                'name'   => '🛡️ Admin Commands',
                'value'  => implode("\n", [
                    '`/credit add @user <amount>` — Add credits to a user',
                    '`/credit remove @user <amount>` — Remove credits from a user',
                ]),
                'inline' => false,
            ];
        }

        return $this->replyEmbed([
            'title'       => '🛡️ Staff Commands',
            'description' => 'Commands available to staff members:',
            'color'       => 0xEB459E,
            'fields'      => $fields,
            'footer'      => ['text' => config('app.name') . ' · Staff only'],
        ]);
    }

    // -------------------------------------------------------------------------
    // /link
    // -------------------------------------------------------------------------
    private function cmdLink(): JsonResponse
    {
        $discordId = $this->getDiscordUserId();

        if (DiscordUser::where('discord_id', $discordId)->exists()) {
            return $this->reply('Your account is already linked. Use `/unlink` first if you want to re-link.');
        }

        // Generate a short-lived state token
        $token = Str::random(32);
        Cache::put("discord_link:{$token}", $discordId, now()->addMinutes(10));

        $redirectUri = route('discord.oauth.redirect', ['state' => $token]);

        return $this->reply(
            "Click the link below to connect your Paymenter account:\n🔗 {$redirectUri}\n\n*This link expires in 10 minutes.*"
        );
    }

    // -------------------------------------------------------------------------
    // /unlink
    // -------------------------------------------------------------------------
    private function cmdUnlink(): JsonResponse
    {
        $discordId = $this->getDiscordUserId();
        $link = DiscordUser::where('discord_id', $discordId)->first();

        if (!$link) {
            return $this->reply('Your Discord account is not linked to any Paymenter account.');
        }

        $this->removeLinkedRole($discordId);
        $this->removeCustomerRole($discordId);
        $link->delete();

        return $this->reply('Your account has been successfully unlinked.');
    }

    // -------------------------------------------------------------------------
    // /refresh
    // -------------------------------------------------------------------------
    private function cmdRefresh(): JsonResponse
    {
        $discordId = $this->getDiscordUserId();
        $link = DiscordUser::where('discord_id', $discordId)->first();

        if (!$link) {
            return $this->reply('Your Discord account is not linked to any Paymenter account. Use `/link` first.');
        }

        $manager = DiscordRoleManager::fromExtension();
        if (!$manager) {
            return $this->reply('❌ Discord role management is not configured.');
        }

        try {
            $manager->syncForDiscordId($discordId);
        } catch (\Throwable $e) {
            Log::warning('Discord: failed to refresh customer role', ['error' => $e->getMessage()]);
            return $this->reply('❌ Failed to refresh your role. Please try again later.');
        }

        return $this->reply('✅ Your customer role has been refreshed.');
    }

    // -------------------------------------------------------------------------
    // /balance
    // -------------------------------------------------------------------------
    private function cmdBalance(): JsonResponse
    {
        $user = $this->getLinkedUser();

        if (!$user) {
            return $this->reply('Your Discord account is not linked. Use `/link` to connect your Paymenter account.');
        }

        $credits = $user->credits()
            ->selectRaw('currency_code, SUM(amount) as total')
            ->groupBy('currency_code')
            ->get();

        if ($credits->isEmpty()) {
            return $this->replyEmbed([
                'title'       => '💰 Credit Balance',
                'description' => 'You have no credits on your account.',
                'color'       => 0x57F287,
                'footer'      => ['text' => config('app.name')],
            ]);
        }

        $fields = $credits->map(fn ($c) => [
            'name'   => strtoupper($c->currency_code),
            'value'  => number_format($c->total, 2),
            'inline' => true,
        ])->toArray();

        return $this->replyEmbed([
            'title'  => '💰 Credit Balance',
            'color'  => 0x57F287,
            'fields' => $fields,
            'footer' => ['text' => config('app.name')],
        ]);
    }

    // -------------------------------------------------------------------------
    // /profile
    // -------------------------------------------------------------------------
    private function cmdProfile(): JsonResponse
    {
        $discordId = $this->getDiscordUserId();
        $link      = DiscordUser::where('discord_id', $discordId)->first();

        if (!$link) {
            return $this->reply('Your Discord account is not linked. Use `/link` to connect your Paymenter account.');
        }

        $user            = $link->user;
        $activeServices  = $user->services()->where('status', 'active')->count();
        $openTickets     = $user->tickets()->whereIn('status', ['open', 'replied'])->count();
        $pendingInvoices = $user->invoices()->where('status', 'pending')->count();

        $currencyCode = $this->getConfig('currency_code') ?? 'USD';
        $totalCredits = $user->credits()->where('currency_code', $currencyCode)->sum('amount');

        // Mask email: show first 2 chars + *** + @domain
        $email  = $user->email;
        $atPos  = strpos($email, '@');
        $masked = substr($email, 0, min(2, $atPos))
            . str_repeat('*', max(0, $atPos - 2))
            . substr($email, $atPos);

        return $this->replyEmbed([
            'title'  => '👤 Your Profile',
            'color'  => 0x5865F2,
            'fields' => [
                ['name' => '📧 Email',            'value' => $masked,                                                  'inline' => true],
                ['name' => '🎮 Discord',           'value' => $link->discord_username,                                  'inline' => true],
                ['name' => '💰 Credits',           'value' => number_format($totalCredits, 2) . ' ' . strtoupper($currencyCode), 'inline' => true],
                ['name' => '🖥️ Active Services',  'value' => (string) $activeServices,                                  'inline' => true],
                ['name' => '🎫 Open Tickets',      'value' => (string) $openTickets,                                    'inline' => true],
                ['name' => '📄 Pending Invoices',  'value' => (string) $pendingInvoices,                                 'inline' => true],
            ],
            'footer' => ['text' => config('app.name') . ' · Account #' . $user->id],
        ]);
    }

    // -------------------------------------------------------------------------
    // /createticket
    // -------------------------------------------------------------------------
    private function cmdCreateTicket(): JsonResponse
    {
        $user = $this->getLinkedUser();

        if (!$user) {
            return $this->reply('Your Discord account is not linked. Use `/link` to connect your Paymenter account.');
        }

        $opts     = collect($this->interaction['data']['options'] ?? [])->keyBy('name');
        $subject  = $opts['subject']['value'] ?? null;
        $message  = $opts['message']['value'] ?? null;
        $priority = $opts['priority']['value'] ?? 'medium';

        if (!$subject || !$message) {
            return $this->reply('❌ Subject and message are required.');
        }

        $ticket = \App\Models\Ticket::create([
            'subject'    => $subject,
            'status'     => 'open',
            'priority'   => $priority,
            'department' => 'Support',
            'user_id'    => $user->id,
        ]);

        \App\Models\TicketMessage::create([
            'ticket_id' => $ticket->id,
            'user_id'   => $user->id,
            'message'   => $message,
        ]);

        return $this->replyEmbed([
            'title'       => '✅ Ticket Created',
            'description' => sprintf('[🔗 View Ticket](%s)', route('tickets.show', $ticket->id)),
            'color'       => 0x57F287,
            'fields'      => [
                ['name' => '🎫 Ticket ID', 'value' => "#{$ticket->id}",   'inline' => true],
                ['name' => '📋 Subject',   'value' => $subject,            'inline' => true],
                ['name' => '⚡ Priority',  'value' => ucfirst($priority),  'inline' => true],
            ],
            'footer' => ['text' => config('app.name')],
        ]);
    }

    // -------------------------------------------------------------------------
    // /services [page]
    // -------------------------------------------------------------------------
    private function cmdServices(int $forcePage = 0, bool $updateMessage = false): JsonResponse
    {
        $user = $this->getLinkedUser();

        if (!$user) {
            return $this->reply('Your Discord account is not linked. Use `/link` to connect your Paymenter account.');
        }

        $opts = collect($this->interaction['data']['options'] ?? [])->keyBy('name');
        $page = $forcePage > 0
            ? $forcePage
            : max(1, (int) ($opts['page']['value'] ?? 1));
        $perPage = 6;

        $allServices = $user->services()
            ->with(['product', 'plan'])
            ->where('status', '!=', 'cancelled')
            ->latest()
            ->get();

        if ($allServices->isEmpty()) {
            return $this->reply('You have no services.');
        }

        $totalPages = max(1, (int) ceil($allServices->count() / $perPage));
        $page = min(max(1, $page), $totalPages);
        $services = $allServices->forPage($page, $perPage);

        $fields = $services->map(fn ($s) => [
            'name'  => sprintf('`#%d` %s', $s->id, $s->product?->name ?? 'Unknown Product'),
            'value' => implode("\n", array_filter([
                sprintf('🔘 **Status:** %s', $this->formatStatus($s->status)),
                $this->formatPrice($s) === 'Free'
                    ? '💸 **Price:** Free'
                    : sprintf('💸 **Price:** %s / %s', $this->formatPrice($s), $this->formatBillingCycle($s->plan)),
                $s->expires_at ? sprintf('📅 **Expires:** <t:%d:D>', $s->expires_at->timestamp) : null,
                sprintf('[🔗 View Service](%s)', route('services.show', $s->id)),
            ])),
            'inline' => false,
        ])->toArray();

        $embed = [
            'title'  => '🖥️ Your Services',
            'color'  => 0x5865F2,
            'fields' => $fields,
            'footer' => ['text' => sprintf('%s · Page %d of %d', config('app.name'), $page, $totalPages)],
        ];

        // Build navigation buttons
        $buttons = array_values(array_filter([
            $page > 1 ? [
                'type'      => 2,
                'label'     => '← Previous',
                'style'     => 2,
                'custom_id' => 'services_page_' . ($page - 1),
            ] : null,
            $page < $totalPages ? [
                'type'      => 2,
                'label'     => 'Next →',
                'style'     => 1,
                'custom_id' => 'services_page_' . ($page + 1),
            ] : null,
        ]));

        $components = !empty($buttons) ? [['type' => 1, 'components' => $buttons]] : [];

        if ($updateMessage) {
            return $this->updateEmbed($embed, $components);
        }

        return $this->replyEmbed($embed, true, $components);
    }

    private function formatStatus(string $status): string
    {
        return match ($status) {
            'active'    => '🟢 Active',
            'suspended' => '🟡 Suspended',
            'cancelled' => '🔴 Cancelled',
            'pending'   => '🔵 Pending',
            default     => ucfirst($status),
        };
    }

    private function formatPrice($service): string
    {
        $price = $service->price * ($service->quantity ?? 1);
        if ($price <= 0) return 'Free';
        return number_format($price, 2) . ' ' . strtoupper($service->currency_code ?? '');
    }

    private function formatBillingCycle($plan): string
    {
        if (!$plan) return 'Unknown';
        if ($plan->type === 'free') return 'Free';
        if ($plan->type === 'one-time') return 'One-time';

        $period = (int) $plan->billing_period;
        $unit   = $plan->billing_unit;

        if ($unit === 'month') {
            return match ($period) {
                1  => 'Monthly',
                3  => 'Quarterly',
                6  => 'Semi-annually',
                12 => 'Annually',
                default => "Every {$period} months",
            };
        }

        if ($unit === 'year') {
            return match ($period) {
                1  => 'Annually',
                default => "Every {$period} years",
            };
        }

        return ucfirst($unit) . 'ly';
    }

    // -------------------------------------------------------------------------
    // /invoices
    // -------------------------------------------------------------------------
    private function cmdInvoices(): JsonResponse
    {
        $user = $this->getLinkedUser();

        if (!$user) {
            return $this->reply('Your Discord account is not linked. Use `/link` to connect your Paymenter account.');
        }

        $invoices = $user->invoices()
            ->where('status', 'pending')
            ->latest()
            ->take(10)
            ->get();

        if ($invoices->isEmpty()) {
            return $this->replyEmbed([
                'title'       => '✅ No Pending Invoices',
                'description' => 'You have no pending invoices. You\'re all caught up!',
                'color'       => 0x57F287,
            ]);
        }

        $fields = $invoices->map(fn ($inv) => [
            'name'   => "Invoice #{$inv->number}",
            'value'  => sprintf(
                "Amount: **%s**\nDue: %s\n[Pay Now](%s)",
                $inv->formattedTotal ?? number_format($inv->items->sum('price'), 2) . ' ' . $inv->currency_code,
                $inv->due_at ? $inv->due_at->format('Y-m-d') : 'N/A',
                route('invoices.show', $inv->id)
            ),
            'inline' => true,
        ])->toArray();

        return $this->replyEmbed([
            'title'  => '📄 Pending Invoices',
            'color'  => 0xFEE75C,
            'fields' => $fields,
            'footer' => ['text' => config('app.name') . ' · ' . now()->format('Y-m-d')],
        ]);
    }

    // -------------------------------------------------------------------------
    // /tickets
    // -------------------------------------------------------------------------
    private function cmdTickets(): JsonResponse
    {
        $user = $this->getLinkedUser();

        if (!$user) {
            return $this->reply('Your Discord account is not linked. Use `/link` to connect your Paymenter account.');
        }

        $tickets = $user->tickets()
            ->whereIn('status', ['open', 'replied'])
            ->latest()
            ->take(10)
            ->get();

        if ($tickets->isEmpty()) {
            return $this->replyEmbed([
                'title'       => '✅ No Open Tickets',
                'description' => 'You have no open support tickets.',
                'color'       => 0x57F287,
            ]);
        }

        $fields = $tickets->map(fn ($t) => [
            'name'  => '🎫 #' . $t->id . ' — ' . Str::limit($t->subject, 40),
            'value' => implode("\n", array_filter([
                sprintf('🔘 **Status:** %s', ucfirst($t->status)),
                sprintf('⚡ **Priority:** %s', ucfirst($t->priority ?? 'normal')),
                $t->department ? sprintf('📂 **Department:** %s', $t->department) : null,
                sprintf('📅 **Created:** <t:%d:f>', $t->created_at->timestamp),
                sprintf('🔄 **Last Update:** <t:%d:R>', $t->updated_at->timestamp),
                sprintf('[🔗 View Ticket](%s)', route('tickets.show', $t->id)),
            ])),
            'inline' => false,
        ])->toArray();

        return $this->replyEmbed([
            'title'  => '🎫 Open Support Tickets',
            'color'  => 0xEB459E,
            'fields' => $fields,
            'footer' => ['text' => config('app.name') . ' · ' . now()->format('Y-m-d')],
        ]);
    }

    // -------------------------------------------------------------------------
    // /seeservices {user}  /seeinvoices {user}  /seetickets {user}
    // -------------------------------------------------------------------------

    private function cmdSeeServices(): JsonResponse
    {
        if (!$this->isSupporter()) {
            return $this->reply('❌ You do not have permission to use this command.');
        }

        $user = $this->resolveTargetUser();
        if (!$user) {
            return $this->reply('❌ That Discord user has not linked their Paymenter account.');
        }

        $services = $user->services()
            ->where('status', 'active')
            ->with('product')
            ->latest()
            ->take(10)
            ->get();

        if ($services->isEmpty()) {
            return $this->replyEmbed([
                'title'       => "🖥️ Services — {$user->name}",
                'description' => 'This user has no active services.',
                'color'       => 0x57F287,
            ]);
        }

        $fields = $services->map(fn ($s) => [
            'name'   => "#{$s->id} — " . ($s->product->name ?? 'Unknown Product'),
            'value'  => sprintf(
                "Status: **%s**%s",
                ucfirst($s->status),
                $s->expires_at ? "\nExpires: " . $s->expires_at->format('Y-m-d') : ''
            ),
            'inline' => true,
        ])->toArray();

        return $this->replyEmbed([
            'title'  => "🖥️ Services — {$user->name}",
            'color'  => 0x5865F2,
            'fields' => $fields,
            'footer' => ['text' => "Paymenter User #{$user->id} · " . now()->format('Y-m-d')],
        ]);
    }

    private function cmdSeeInvoices(): JsonResponse
    {
        if (!$this->isSupporter()) {
            return $this->reply('❌ You do not have permission to use this command.');
        }

        $user = $this->resolveTargetUser();
        if (!$user) {
            return $this->reply('❌ That Discord user has not linked their Paymenter account.');
        }

        $invoices = $user->invoices()
            ->whereIn('status', ['pending', 'paid'])
            ->latest()
            ->take(10)
            ->get();

        if ($invoices->isEmpty()) {
            return $this->replyEmbed([
                'title'       => "📄 Invoices — {$user->name}",
                'description' => 'This user has no invoices.',
                'color'       => 0x57F287,
            ]);
        }

        $fields = $invoices->map(fn ($inv) => [
            'name'   => "Invoice #{$inv->number}",
            'value'  => sprintf(
                "Status: **%s**\nAmount: %s\nDue: %s",
                ucfirst($inv->status),
                $inv->formattedTotal ?? number_format($inv->items->sum('price'), 2) . ' ' . $inv->currency_code,
                $inv->due_at ? $inv->due_at->format('Y-m-d') : 'N/A'
            ),
            'inline' => true,
        ])->toArray();

        return $this->replyEmbed([
            'title'  => "📄 Invoices — {$user->name}",
            'color'  => 0xFEE75C,
            'fields' => $fields,
            'footer' => ['text' => "Paymenter User #{$user->id} · " . now()->format('Y-m-d')],
        ]);
    }

    private function cmdSeeTickets(): JsonResponse
    {
        if (!$this->isSupporter()) {
            return $this->reply('❌ You do not have permission to use this command.');
        }

        $user = $this->resolveTargetUser();
        if (!$user) {
            return $this->reply('❌ That Discord user has not linked their Paymenter account.');
        }

        $tickets = $user->tickets()
            ->latest()
            ->take(10)
            ->get();

        if ($tickets->isEmpty()) {
            return $this->replyEmbed([
                'title'       => "🎫 Tickets — {$user->name}",
                'description' => 'This user has no support tickets.',
                'color'       => 0x57F287,
            ]);
        }

        $fields = $tickets->map(fn ($t) => [
            'name'   => "#{$t->id} — " . Str::limit($t->subject, 35),
            'value'  => sprintf(
                "Status: **%s**\nPriority: %s",
                ucfirst($t->status),
                ucfirst($t->priority ?? 'normal')
            ),
            'inline' => true,
        ])->toArray();

        return $this->replyEmbed([
            'title'  => "🎫 Tickets — {$user->name}",
            'color'  => 0xEB459E,
            'fields' => $fields,
            'footer' => ['text' => "Paymenter User #{$user->id} · " . now()->format('Y-m-d')],
        ]);
    }

    // -------------------------------------------------------------------------
    // /credit add|remove
    // -------------------------------------------------------------------------
    private function cmdCredit(): JsonResponse
    {
        if (!$this->isAdmin()) {
            return $this->reply('❌ You do not have permission to use this command.');
        }

        $subcommand = $this->interaction['data']['options'][0] ?? null;
        if (!$subcommand) {
            return $this->reply('Invalid subcommand.');
        }

        $opts = collect($subcommand['options'] ?? [])
            ->keyBy('name')
            ->map(fn ($o) => $o['value']);

        $targetDiscordId = $this->resolveDiscordUserId($opts['user'] ?? null);
        $amount          = (float) ($opts['amount'] ?? 0);
        $action          = $subcommand['name']; // 'add' or 'remove'

        if ($amount <= 0) {
            return $this->reply('❌ Amount must be greater than 0.');
        }

        if (!$targetDiscordId) {
            return $this->reply('❌ Could not resolve target user.');
        }

        $link = DiscordUser::where('discord_id', $targetDiscordId)->first();

        if (!$link) {
            return $this->reply("❌ That Discord user has not linked their Paymenter account.");
        }

        $user         = $link->user;
        $currencyCode = $this->getConfig('currency_code') ?? 'USD';

        if ($action === 'add') {
            Credit::create([
                'user_id'       => $user->id,
                'amount'        => $amount,
                'currency_code' => $currencyCode,
            ]);

            $msg = "✅ Added **{$amount} {$currencyCode}** credit to **{$user->name}**";
        } else {
            Credit::create([
                'user_id'       => $user->id,
                'amount'        => -$amount,
                'currency_code' => $currencyCode,
            ]);

            $msg = "✅ Removed **{$amount} {$currencyCode}** credit from **{$user->name}**";
        }

        return $this->reply($msg);
    }

    // -------------------------------------------------------------------------
    // /config list|enable|disable
    // -------------------------------------------------------------------------
    private function cmdConfig(): JsonResponse
    {
        if (!$this->isAdmin()) {
            return $this->reply('❌ You do not have permission to use this command.');
        }

        $subcommand = $this->interaction['data']['options'][0] ?? null;
        if (!$subcommand) {
            return $this->reply('Invalid subcommand.');
        }

        return match ($subcommand['name']) {
            'list'    => $this->cmdConfigList(),
            'enable'  => $this->cmdConfigToggle($subcommand, true),
            'disable' => $this->cmdConfigToggle($subcommand, false),
            default   => $this->reply('Unknown subcommand.'),
        };
    }

    private function cmdConfigList(): JsonResponse
    {
        $disabled = $this->getDisabledCommands();

        $fields = array_map(fn ($cmd) => [
            'name'   => "/{$cmd}",
            'value'  => in_array($cmd, $disabled) ? '🔴 Disabled' : '🟢 Enabled',
            'inline' => true,
        ], self::CONFIGURABLE_COMMANDS);

        return $this->replyEmbed([
            'title'  => '⚙️ Command Configuration',
            'color'  => 0x5865F2,
            'fields' => $fields,
            'footer' => ['text' => config('app.name') . ' · Admin only'],
        ]);
    }

    private function cmdConfigToggle(array $subcommand, bool $enable): JsonResponse
    {
        $opts    = collect($subcommand['options'] ?? [])->keyBy('name');
        $command = $opts['command']['value'] ?? null;

        if (!$command || !in_array($command, self::CONFIGURABLE_COMMANDS)) {
            return $this->reply('❌ Invalid command name.');
        }

        $disabled = $this->getDisabledCommands();

        if ($enable) {
            $disabled = array_values(array_filter($disabled, fn ($c) => $c !== $command));
        } else {
            if (!in_array($command, $disabled)) {
                $disabled[] = $command;
            }
        }

        $this->setConfig('disabled_commands', implode(',', $disabled));

        $icon = $enable ? '🟢' : '🔴';
        $word = $enable ? 'enabled' : 'disabled';
        return $this->reply("{$icon} Command `/{$command}` has been {$word}.");
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function getDiscordUserId(): string
    {
        return $this->interaction['member']['user']['id']
            ?? $this->interaction['user']['id']
            ?? '';
    }

    private function getLinkedUser(): ?User
    {
        $discordId = $this->getDiscordUserId();
        return DiscordUser::where('discord_id', $discordId)->first()?->user;
    }

    private function isAdmin(): bool
    {
        $adminRoleConfig = $this->getConfig('admin_role_id');

        if (!$adminRoleConfig) {
            return false;
        }

        $allowedRoles = array_map('trim', explode(',', $adminRoleConfig));
        $memberRoles  = $this->interaction['member']['roles'] ?? [];

        return !empty(array_intersect($allowedRoles, $memberRoles));
    }

    private function isSupporter(): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        $supporterRoleConfig = $this->getConfig('supporter_role_id');

        if (!$supporterRoleConfig) {
            return false;
        }

        $allowedRoles = array_map('trim', explode(',', $supporterRoleConfig));
        $memberRoles  = $this->interaction['member']['roles'] ?? [];

        return !empty(array_intersect($allowedRoles, $memberRoles));
    }

    /** Resolve the Paymenter user ID from the Discord interaction resolved user data */
    private function resolveDiscordUserId(?string $value): ?string
    {
        if (!$value) {
            return null;
        }

        // The option value for USER type (6) is the Discord user ID
        return $value;
    }

    /** Resolve the target Paymenter User from the first USER option in the current command */
    private function resolveTargetUser(): ?User
    {
        $opts = collect($this->interaction['data']['options'] ?? [])
            ->keyBy('name')
            ->map(fn ($o) => $o['value']);

        $discordId = $opts['user'] ?? null;

        if (!$discordId) {
            return null;
        }

        return DiscordUser::where('discord_id', $discordId)->first()?->user;
    }

    private function getConfig(string $key): ?string
    {
        if ($this->extensionSettings === null) {
            $extension = \App\Models\Extension::where('extension', 'Discord')
                ->where('type', 'other')
                ->first();

            $this->extensionSettings = $extension
                ? $extension->settings->pluck('value', 'key')->toArray()
                : [];
        }

        return $this->extensionSettings[$key] ?? null;
    }

    private function setConfig(string $key, string $value): void
    {
        $extension = \App\Models\Extension::where('extension', 'Discord')
            ->where('type', 'other')
            ->first();

        if (!$extension) {
            return;
        }

        $extension->settings()->updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'type' => 'text']
        );

        $this->extensionSettings = null;
    }

    private function getDisabledCommands(): array
    {
        $raw = $this->getConfig('disabled_commands');
        if (!$raw) {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    private function isCommandDisabled(string $name): bool
    {
        return in_array($name, $this->getDisabledCommands());
    }

    private function removeLinkedRole(string $discordId): void
    {
        $guildId  = $this->getConfig('guild_id');
        $roleId   = $this->getConfig('linked_role_id');
        $botToken = $this->getConfig('bot_token');
        $clientId = $this->getConfig('application_id');
        $clientSecret = $this->getConfig('client_secret');

        if ($guildId && $roleId && $botToken) {
            $api = new DiscordApi($botToken, $clientId ?? '', $clientSecret ?? '');
            $api->removeRole($guildId, $discordId, $roleId);
        }
    }

    private function removeCustomerRole(string $discordId): void
    {
        try {
            $manager = DiscordRoleManager::fromExtension();
            if ($manager) {
                $manager->removeCustomerRole($discordId);
            }
        } catch (\Throwable $e) {
            Log::warning('Discord: failed to remove customer role on unlink', ['error' => $e->getMessage()]);
        }
    }

    private function reply(string $content, bool $ephemeral = true): JsonResponse
    {
        return response()->json([
            'type' => 4,
            'data' => [
                'content' => $content,
                'flags'   => $ephemeral ? 64 : 0,
            ],
        ]);
    }

    private function replyEmbed(array $embed, bool $ephemeral = true, array $components = []): JsonResponse
    {
        $data = [
            'embeds' => [$embed],
            'flags'  => $ephemeral ? 64 : 0,
        ];

        if (!empty($components)) {
            $data['components'] = $components;
        }

        return response()->json(['type' => 4, 'data' => $data]);
    }

    // Update the original message in-place (used by button interactions)
    private function updateEmbed(array $embed, array $components = []): JsonResponse
    {
        $data = [
            'embeds'     => [$embed],
            'components' => $components, // empty array clears buttons on last/first page
        ];

        return response()->json(['type' => 7, 'data' => $data]);
    }

    // -------------------------------------------------------------------------
    // /eventcreate
    // -------------------------------------------------------------------------
    private function cmdEventCreate(): JsonResponse
    {
        if (!$this->isAdmin()) {
            return $this->reply('❌ You do not have permission to use this command.');
        }

        $templates = \DB::table('discord_templates')->get();
        $templateOptions = [];
        foreach ($templates as $template) {
            $templateOptions[] = [
                'label' => $template->name,
                'value' => $template->id,
            ];
        }

        $embed = [
            'title' => '📝 Embed Builder',
            'description' => 'Use the buttons below to customize your embed.',
            'color' => 0x5865F2,
        ];

        $components = [];

        if (!empty($templateOptions)) {
            $components[] = [
                'type' => 1,
                'components' => [
                    [
                        'type' => 3,
                        'custom_id' => 'load_template',
                        'placeholder' => 'Load a template...',
                        'options' => $templateOptions,
                    ],
                ],
            ];
        }

        $components = array_merge($components, [
            [
                'type' => 1,
                'components' => [
                    [
                        'type' => 2,
                        'label' => 'Title',
                        'style' => 1,
                        'custom_id' => 'set_title',
                    ],
                    [
                        'type' => 2,
                        'label' => 'Description',
                        'style' => 1,
                        'custom_id' => 'set_description',
                    ],
                    [
                        'type' => 2,
                        'label' => 'Author',
                        'style' => 1,
                        'custom_id' => 'set_author',
                    ],
                    [
                        'type' => 2,
                        'label' => 'Footer',
                        'style' => 1,
                        'custom_id' => 'set_footer',
                    ],
                ],
            ],
            [
                'type' => 1,
                'components' => [
                    [
                        'type' => 2,
                        'label' => 'Thumbnail',
                        'style' => 1,
                        'custom_id' => 'set_thumbnail',
                    ],
                    [
                        'type' => 2,
                        'label' => 'Image',
                        'style' => 1,
                        'custom_id' => 'set_image',
                    ],
                    [
                        'type' => 2,
                        'label' => 'Color',
                        'style' => 1,
                        'custom_id' => 'set_color',
                    ],
                    [
                        'type' => 2,
                        'label' => 'Add Field',
                        'style' => 1,
                        'custom_id' => 'add_field',
                    ],
                    [
                        'type' => 2,
                        'label' => 'Remove Field',
                        'style' => 1,
                        'custom_id' => 'remove_field',
                    ],
                ],
            ],
            [
                'type' => 1,
                'components' => [
                    [
                        'type' => 2,
                        'label' => 'Add Link',
                        'style' => 1,
                        'custom_id' => 'add_link',
                    ],
                    [
                        'type' => 2,
                        'label' => 'Remove Link',
                        'style' => 1,
                        'custom_id' => 'remove_link',
                    ],
                ],
            ],
            [
                'type' => 1,
                'components' => [
                    [
                        'type' => 2,
                        'label' => 'Save Template',
                        'style' => 3,
                        'custom_id' => 'save_template',
                    ],
                    [
                        'type' => 2,
                        'label' => 'Delete Template',
                        'style' => 4,
                        'custom_id' => 'delete_template',
                    ],
                    [
                        'type' => 2,
                        'label' => 'Post Embed',
                        'style' => 3,
                        'custom_id' => 'post_embed',
                    ],
                    [
                        'type' => 2,
                        'label' => 'Send Event',
                        'style' => 1,
                        'custom_id' => 'send_event',
                    ],
                ],
            ],
        ]);

        return $this->replyEmbed($embed, true, $components);
    }

    private function handleEventCreateComponent(): JsonResponse
    {
        $customId = $this->interaction['data']['custom_id'];
        $userId = $this->interaction['member']['user']['id'];

        // Initialize or retrieve the current embed data from cache
        $cacheKey = "discord_embed_builder_{$userId}";
        $embedData = Cache::get($cacheKey, [
            'title' => null,
            'description' => null,
            'author' => null,
            'footer' => null,
            'timestamp' => null,
            'thumbnail' => null,
            'image' => null,
            'color' => 0x5865F2,
            'fields' => [],
            'links' => [],
        ]);

        if (str_starts_with($customId, 'set_')) {
            return $this->showModal($customId, $embedData);
        }

        if (str_starts_with($customId, 'add_')) {
            return $this->showModal($customId, $embedData);
        }

        if (str_starts_with($customId, 'remove_')) {
            $field = str_replace('remove_', '', $customId);
            return $this->handleRemove($field, $embedData, $cacheKey);
        }

        if ($customId === 'load_template') {
            $templateId = $this->interaction['data']['values'][0];
            $template = \DB::table('discord_templates')->find($templateId);
            if ($template) {
                $embedData = json_decode($template->embed_data, true);
                Cache::put($cacheKey, $embedData, now()->addHours(2));
                return $this->updateEmbedBuilder($embedData);
            }
        }

        if ($customId === 'save_template') {
            return $this->showModal('save_template', $embedData);
        }

        if ($customId === 'delete_template') {
            $templates = \DB::table('discord_templates')->get();
            $options = [];
            foreach ($templates as $template) {
                $options[] = [
                    'label' => $template->name,
                    'value' => $template->id,
                ];
            }

            return $this->replyModal('delete_template', 'Delete Template', [
                [
                    'type' => 3,
                    'custom_id' => 'template_id_to_delete',
                    'label' => 'Select Template to Delete',
                    'placeholder' => 'Choose a template...',
                    'options' => $options,
                    'required' => true,
                ],
            ]);
        }

        if ($customId === 'post_embed') {
            return $this->postEmbed($embedData);
        }

        if ($customId === 'send_event') {
            return $this->sendEventToAll($embedData);
        }

        return $this->reply('❌ Unknown action.');
    }

    private function showModal(string $field, array $embedData): JsonResponse
    {
        $title = ucfirst(str_replace('_', ' ', $field));
        $customId = "modal_{$field}";

        switch ($field) {
            case 'set_title':
                $components = [[
                    'type' => 1,
                    'components' => [[
                        'type' => 4,
                        'custom_id' => 'embed_title',
                        'label' => 'Title',
                        'style' => 1,
                        'value' => $embedData['title'] ?? '',
                        'required' => true,
                        'max_length' => 256,
                    ]],
                ]];
                break;

            case 'set_description':
                $components = [[
                    'type' => 1,
                    'components' => [[
                        'type' => 4,
                        'custom_id' => 'embed_description',
                        'label' => 'Description',
                        'style' => 2,
                        'value' => $embedData['description'] ?? '',
                        'required' => false,
                        'max_length' => 4000,
                    ]],
                ]];
                break;

            case 'set_author':
                $components = [
                    [
                        'type' => 1,
                        'components' => [[
                            'type' => 4,
                            'custom_id' => 'embed_author_name',
                            'label' => 'Author Name',
                            'style' => 1,
                            'value' => $embedData['author']['name'] ?? '',
                            'required' => true,
                            'max_length' => 256,
                        ]],
                    ],
                    [
                        'type' => 1,
                        'components' => [[
                            'type' => 4,
                            'custom_id' => 'embed_author_url',
                            'label' => 'Author URL (optional)',
                            'style' => 1,
                            'value' => $embedData['author']['url'] ?? '',
                            'required' => false,
                            'max_length' => 512,
                        ]],
                    ],
                    [
                        'type' => 1,
                        'components' => [[
                            'type' => 4,
                            'custom_id' => 'embed_author_icon',
                            'label' => 'Author Icon URL (optional)',
                            'style' => 1,
                            'value' => $embedData['author']['icon_url'] ?? '',
                            'required' => false,
                            'max_length' => 512,
                        ]],
                    ],
                ];
                break;

            case 'set_footer':
                $components = [
                    [
                        'type' => 1,
                        'components' => [[
                            'type' => 4,
                            'custom_id' => 'embed_footer_text',
                            'label' => 'Footer Text',
                            'style' => 1,
                            'value' => $embedData['footer']['text'] ?? '',
                            'required' => true,
                            'max_length' => 2048,
                        ]],
                    ],
                    [
                        'type' => 1,
                        'components' => [[
                            'type' => 4,
                            'custom_id' => 'embed_footer_icon',
                            'label' => 'Footer Icon URL (optional)',
                            'style' => 1,
                            'value' => $embedData['footer']['icon_url'] ?? '',
                            'required' => false,
                            'max_length' => 512,
                        ]],
                    ],
                ];
                break;

            case 'set_thumbnail':
                $components = [[
                    'type' => 1,
                    'components' => [[
                        'type' => 4,
                        'custom_id' => 'embed_thumbnail',
                        'label' => 'Thumbnail URL',
                        'style' => 1,
                        'value' => $embedData['thumbnail'] ?? '',
                        'required' => true,
                        'max_length' => 512,
                    ]],
                ]];
                break;

            case 'set_image':
                $components = [[
                    'type' => 1,
                    'components' => [[
                        'type' => 4,
                        'custom_id' => 'embed_image',
                        'label' => 'Image URL',
                        'style' => 1,
                        'value' => $embedData['image'] ?? '',
                        'required' => true,
                        'max_length' => 512,
                    ]],
                ]];
                break;

            case 'set_color':
                $components = [[
                    'type' => 1,
                    'components' => [[
                        'type' => 4,
                        'custom_id' => 'embed_color',
                        'label' => 'Color (Hex Code)',
                        'style' => 1,
                        'value' => is_string($embedData['color'] ?? null) ? ltrim($embedData['color'], '#') : dechex($embedData['color'] ?? 0x5865F2),
                        'required' => true,
                        'max_length' => 6,
                    ]],
                ]];
                break;

            case 'add_field':
                $components = [
                    [
                        'type' => 1,
                        'components' => [[
                            'type' => 4,
                            'custom_id' => 'field_name',
                            'label' => 'Field Name',
                            'style' => 1,
                            'required' => true,
                            'max_length' => 256,
                        ]],
                    ],
                    [
                        'type' => 1,
                        'components' => [[
                            'type' => 4,
                            'custom_id' => 'field_value',
                            'label' => 'Field Value',
                            'style' => 2,
                            'required' => true,
                            'max_length' => 1024,
                        ]],
                    ],
                    [
                        'type' => 1,
                        'components' => [[
                            'type' => 4,
                            'custom_id' => 'field_inline',
                            'label' => 'Inline? (yes / no)',
                            'style' => 1,
                            'required' => true,
                            'max_length' => 3,
                        ]],
                    ],
                ];
                break;

            case 'add_link':
                $components = [
                    [
                        'type' => 1,
                        'components' => [[
                            'type' => 4,
                            'custom_id' => 'link_label',
                            'label' => 'Link Label',
                            'style' => 1,
                            'required' => true,
                            'max_length' => 80,
                        ]],
                    ],
                    [
                        'type' => 1,
                        'components' => [[
                            'type' => 4,
                            'custom_id' => 'link_url',
                            'label' => 'Link URL',
                            'style' => 1,
                            'required' => true,
                            'max_length' => 512,
                        ]],
                    ],
                ];
                break;

            case 'save_template':
                $components = [[
                    'type' => 1,
                    'components' => [[
                        'type' => 4,
                        'custom_id' => 'template_name',
                        'label' => 'Template Name',
                        'style' => 1,
                        'required' => true,
                        'max_length' => 100,
                    ]],
                ]];
                break;

            default:
                return $this->reply('❌ Unknown field.');
        }

        return $this->replyModal($customId, $title, $components);
    }

    private function replyModal(string $customId, string $title, array $components): JsonResponse
    {
        return response()->json([
            'type' => 9,
            'data' => [
                'custom_id' => $customId,
                'title' => $title,
                'components' => $components,
            ],
        ]);
    }

    private function handleModalSubmit(): JsonResponse
    {
        $customId = $this->interaction['data']['custom_id'];
        $userId = $this->interaction['member']['user']['id'];
        $cacheKey = "discord_embed_builder_{$userId}";
        $embedData = Cache::get($cacheKey, [
            'title' => null,
            'description' => null,
            'author' => null,
            'footer' => null,
            'timestamp' => null,
            'thumbnail' => null,
            'image' => null,
            'color' => 0x5865F2,
            'fields' => [],
            'links' => [],
        ]);

        if (str_starts_with($customId, 'modal_set_')) {
            $field = str_replace('modal_set_', '', $customId);
            if ($field === 'author') {
                $embedData['author'] = [
                    'name'     => $this->interaction['data']['components'][0]['components'][0]['value'],
                    'url'      => $this->interaction['data']['components'][1]['components'][0]['value'] ?: null,
                    'icon_url' => $this->interaction['data']['components'][2]['components'][0]['value'] ?: null,
                ];
            } elseif ($field === 'footer') {
                $embedData['footer'] = [
                    'text'     => $this->interaction['data']['components'][0]['components'][0]['value'],
                    'icon_url' => $this->interaction['data']['components'][1]['components'][0]['value'] ?: null,
                ];
            } elseif ($field === 'color') {
                $hex = ltrim(trim($this->interaction['data']['components'][0]['components'][0]['value']), '#');
                $embedData['color'] = hexdec($hex) ?: 0x5865F2;
            } else {
                $embedData[$field] = $this->interaction['data']['components'][0]['components'][0]['value'];
            }
        }

        if (str_starts_with($customId, 'modal_add_')) {
            $field = str_replace('modal_add_', '', $customId);
            if ($field === 'field') {
                $name = $this->interaction['data']['components'][0]['components'][0]['value'];
                $value = $this->interaction['data']['components'][1]['components'][0]['value'];
                $inline = strtolower(trim($this->interaction['data']['components'][2]['components'][0]['value'])) === 'yes';
                $embedData['fields'][] = [
                    'name' => $name,
                    'value' => $value,
                    'inline' => $inline,
                ];
            } elseif ($field === 'link') {
                $label = $this->interaction['data']['components'][0]['components'][0]['value'];
                $url = $this->interaction['data']['components'][1]['components'][0]['value'];
                $embedData['links'][] = [
                    'label' => $label,
                    'url' => $url,
                ];
            }
        }

        if ($customId === 'modal_save_template') {
            $templateName = $this->interaction['data']['components'][0]['components'][0]['value'];
            \DB::table('discord_templates')->insert([
                'name' => $templateName,
                'embed_data' => json_encode($embedData),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            return $this->reply('✅ Template saved successfully!');
        }

        if ($customId === 'modal_delete_template') {
            $templateId = $this->interaction['data']['components'][0]['components'][0]['value'];
            \DB::table('discord_templates')->where('id', $templateId)->delete();
            return $this->reply('✅ Template deleted successfully!');
        }

        Cache::put($cacheKey, $embedData, now()->addHours(2));
        return $this->updateEmbedBuilder($embedData, false);
    }

    private function handleRemove(string $field, array $embedData, string $cacheKey): JsonResponse
    {
        if ($field === 'field') {
            $options = [];
            foreach ($embedData['fields'] as $index => $fieldData) {
                $options[] = [
                    'label' => $fieldData['name'],
                    'value' => $index,
                ];
            }

            if (empty($options)) {
                return $this->reply('❌ No fields to remove.');
            }

            return $this->replyModal('modal_remove_field', 'Remove Field', [
                [
                    'type' => 1,
                    'components' => [[
                        'type' => 3,
                        'custom_id' => 'field_index',
                        'label' => 'Select Field to Remove',
                        'placeholder' => 'Choose a field...',
                        'options' => $options,
                        'required' => true,
                    ]],
                ],
            ]);
        }

        if ($field === 'link') {
            $options = [];
            foreach ($embedData['links'] as $index => $linkData) {
                $options[] = [
                    'label' => $linkData['label'],
                    'value' => $index,
                ];
            }

            if (empty($options)) {
                return $this->reply('❌ No links to remove.');
            }

            return $this->replyModal('modal_remove_link', 'Remove Link', [
                [
                    'type' => 1,
                    'components' => [[
                        'type' => 3,
                        'custom_id' => 'link_index',
                        'label' => 'Select Link to Remove',
                        'placeholder' => 'Choose a link...',
                        'options' => $options,
                        'required' => true,
                    ]],
                ],
            ]);
        }

        return $this->reply('❌ Unknown field.');
    }

    private function handleModalRemove(): JsonResponse
    {
        $customId = $this->interaction['data']['custom_id'];
        $userId = $this->interaction['member']['user']['id'];
        $cacheKey = "discord_embed_builder_{$userId}";
        $embedData = Cache::get($cacheKey, [
            'title' => null,
            'description' => null,
            'author' => null,
            'footer' => null,
            'timestamp' => null,
            'thumbnail' => null,
            'image' => null,
            'color' => 0x5865F2,
            'fields' => [],
            'links' => [],
        ]);

        if ($customId === 'modal_remove_field') {
            $index = (int) $this->interaction['data']['components'][0]['components'][0]['value'];
            if (isset($embedData['fields'][$index])) {
                array_splice($embedData['fields'], $index, 1);
                Cache::put($cacheKey, $embedData, now()->addHours(2));
                return $this->updateEmbedBuilder($embedData, false);
            }
        }

        if ($customId === 'modal_remove_link') {
            $index = (int) $this->interaction['data']['components'][0]['components'][0]['value'];
            if (isset($embedData['links'][$index])) {
                array_splice($embedData['links'], $index, 1);
                Cache::put($cacheKey, $embedData, now()->addHours(2));
                return $this->updateEmbedBuilder($embedData, false);
            }
        }

        return $this->reply('❌ Unknown action.');
    }

    private function updateEmbedBuilder(array $embedData, bool $update = true): JsonResponse
    {
        $embed = [
            'title' => $embedData['title'] ?? '📝 Embed Builder',
            'description' => $embedData['description'] ?? 'Use the buttons below to customize your embed.',
            'color' => is_string($embedData['color'] ?? null) ? hexdec(ltrim($embedData['color'], '#')) : ($embedData['color'] ?? 0x5865F2),
        ];

        if (!empty($embedData['author'])) {
            $embed['author'] = array_filter($embedData['author'], fn($v) => $v !== null && $v !== '');
        }

        if (!empty($embedData['footer'])) {
            $embed['footer'] = array_filter($embedData['footer'], fn($v) => $v !== null && $v !== '');
        }

        if (!empty($embedData['timestamp'])) {
            $embed['timestamp'] = $embedData['timestamp'];
        }

        if (!empty($embedData['thumbnail'])) {
            $embed['thumbnail'] = ['url' => $embedData['thumbnail']];
        }

        if (!empty($embedData['image'])) {
            $embed['image'] = ['url' => $embedData['image']];
        }

        if (!empty($embedData['fields'])) {
            $embed['fields'] = $embedData['fields'];
        }

        $templates = \DB::table('discord_templates')->get();
        $templateOptions = [];
        foreach ($templates as $template) {
            $templateOptions[] = [
                'label' => $template->name,
                'value' => $template->id,
            ];
        }

        $components = [];

        if (!empty($templateOptions)) {
            $components[] = [
                'type' => 1,
                'components' => [
                    [
                        'type' => 3,
                        'custom_id' => 'load_template',
                        'placeholder' => 'Load a template...',
                        'options' => $templateOptions,
                    ],
                ],
            ];
        }

        $components = array_merge($components, [
            [
                'type' => 1,
                'components' => [
                    [
                        'type' => 2,
                        'label' => 'Title',
                        'style' => 1,
                        'custom_id' => 'set_title',
                    ],
                    [
                        'type' => 2,
                        'label' => 'Description',
                        'style' => 1,
                        'custom_id' => 'set_description',
                    ],
                    [
                        'type' => 2,
                        'label' => 'Author',
                        'style' => 1,
                        'custom_id' => 'set_author',
                    ],
                    [
                        'type' => 2,
                        'label' => 'Footer',
                        'style' => 1,
                        'custom_id' => 'set_footer',
                    ],
                ],
            ],
            [
                'type' => 1,
                'components' => [
                    [
                        'type' => 2,
                        'label' => 'Thumbnail',
                        'style' => 1,
                        'custom_id' => 'set_thumbnail',
                    ],
                    [
                        'type' => 2,
                        'label' => 'Image',
                        'style' => 1,
                        'custom_id' => 'set_image',
                    ],
                    [
                        'type' => 2,
                        'label' => 'Color',
                        'style' => 1,
                        'custom_id' => 'set_color',
                    ],
                    [
                        'type' => 2,
                        'label' => 'Add Field',
                        'style' => 1,
                        'custom_id' => 'add_field',
                    ],
                    [
                        'type' => 2,
                        'label' => 'Remove Field',
                        'style' => 1,
                        'custom_id' => 'remove_field',
                    ],
                ],
            ],
            [
                'type' => 1,
                'components' => [
                    [
                        'type' => 2,
                        'label' => 'Add Link',
                        'style' => 1,
                        'custom_id' => 'add_link',
                    ],
                    [
                        'type' => 2,
                        'label' => 'Remove Link',
                        'style' => 1,
                        'custom_id' => 'remove_link',
                    ],
                ],
            ],
            [
                'type' => 1,
                'components' => [
                    [
                        'type' => 2,
                        'label' => 'Save Template',
                        'style' => 3,
                        'custom_id' => 'save_template',
                    ],
                    [
                        'type' => 2,
                        'label' => 'Delete Template',
                        'style' => 4,
                        'custom_id' => 'delete_template',
                    ],
                    [
                        'type' => 2,
                        'label' => 'Post Embed',
                        'style' => 3,
                        'custom_id' => 'post_embed',
                    ],
                    [
                        'type' => 2,
                        'label' => 'Send Event',
                        'style' => 1,
                        'custom_id' => 'send_event',
                    ],
                ],
            ],
        ]);

        $response = $update
            ? $this->updateEmbed($embed, $components)
            : $this->replyEmbed($embed, true, $components);
        \Illuminate\Support\Facades\Log::debug('Discord response', ['json' => $response->getContent()]);
        return $response;
    }

    private function postEmbed(array $embedData): JsonResponse
    {
        $channelId = $this->interaction['channel_id'];
        $api = $this->makeApi();

        $embed = [
            'title' => $embedData['title'] ?? 'Untitled Embed',
            'description' => $embedData['description'] ?? null,
            'color' => is_string($embedData['color'] ?? null) ? hexdec(ltrim($embedData['color'], '#')) : ($embedData['color'] ?? 0x5865F2),
        ];

        if (!empty($embedData['author'])) {
            $embed['author'] = array_filter($embedData['author'], fn($v) => $v !== null && $v !== '');
        }

        if (!empty($embedData['footer'])) {
            $embed['footer'] = array_filter($embedData['footer'], fn($v) => $v !== null && $v !== '');
        }

        if (!empty($embedData['timestamp'])) {
            $embed['timestamp'] = $embedData['timestamp'];
        }

        if (!empty($embedData['thumbnail'])) {
            $embed['thumbnail'] = ['url' => $embedData['thumbnail']];
        }

        if (!empty($embedData['image'])) {
            $embed['image'] = ['url' => $embedData['image']];
        }

        if (!empty($embedData['fields'])) {
            $embed['fields'] = $embedData['fields'];
        }

        $components = [];
        if (!empty($embedData['links'])) {
            foreach ($embedData['links'] as $link) {
                $components[] = [
                    'type' => 1,
                    'components' => [[
                        'type' => 2,
                        'label' => $link['label'],
                        'style' => 5,
                        'url' => $link['url'],
                    ]],
                ];
            }
        }

        $api->sendMessage($channelId, [
            'embeds' => [$embed],
            'components' => $components,
        ]);

        return $this->reply('✅ Embed posted successfully!');
    }

    private function sendEventToAll(array $embedData): JsonResponse
    {
        $api = $this->makeApi();
        if (!$api) {
            return $this->reply('❌ Bot API is not configured.');
        }

        $embed = [
            'title' => $embedData['title'] ?? 'Untitled Event',
            'description' => $embedData['description'] ?? null,
            'color' => is_string($embedData['color'] ?? null) ? hexdec(ltrim($embedData['color'], '#')) : ($embedData['color'] ?? 0x5865F2),
        ];

        if (!empty($embedData['author'])) {
            $embed['author'] = array_filter($embedData['author'], fn($v) => $v !== null && $v !== '');
        }

        if (!empty($embedData['footer'])) {
            $embed['footer'] = array_filter($embedData['footer'], fn($v) => $v !== null && $v !== '');
        }

        if (!empty($embedData['timestamp'])) {
            $embed['timestamp'] = $embedData['timestamp'];
        }

        if (!empty($embedData['thumbnail'])) {
            $embed['thumbnail'] = ['url' => $embedData['thumbnail']];
        }

        if (!empty($embedData['image'])) {
            $embed['image'] = ['url' => $embedData['image']];
        }

        if (!empty($embedData['fields'])) {
            $embed['fields'] = $embedData['fields'];
        }

        $components = [];
        if (!empty($embedData['links'])) {
            foreach ($embedData['links'] as $link) {
                $components[] = [
                    'type' => 1,
                    'components' => [[
                        'type' => 2,
                        'label' => $link['label'],
                        'style' => 5,
                        'url' => $link['url'],
                    ]],
                ];
            }
        }

        $payload = ['embeds' => [$embed]];
        if (!empty($components)) {
            $payload['components'] = $components;
        }

        $linkedUsers = DiscordUser::all();
        $sent = 0;
        $failed = 0;

        foreach ($linkedUsers as $discordUser) {
            $success = $api->sendDm($discordUser->discord_id, $payload);
            $success ? $sent++ : $failed++;
        }

        $total = $sent + $failed;
        if ($total === 0) {
            return $this->reply('⚠️ No linked users found. Nobody has linked their account via `/link` yet.');
        }

        $message = "✅ Event sent to **{$sent}/{$total}** linked users.";
        if ($failed > 0) {
            $message .= " {$failed} failed (users may have DMs disabled).";
        }

        return $this->reply($message);
    }
}
