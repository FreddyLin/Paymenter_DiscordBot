<?php

namespace Paymenter\Extensions\Others\Discord\Admin\Resources\DiscordUserResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Paymenter\Extensions\Others\Discord\Admin\Resources\DiscordUserResource;

class ListDiscordUsers extends ListRecords
{
    protected static string $resource = DiscordUserResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
