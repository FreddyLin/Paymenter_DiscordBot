<?php

namespace Paymenter\Extensions\Others\Discord\Admin\Resources;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Paymenter\Extensions\Others\Discord\Admin\Resources\DiscordUserResource\Pages\ListDiscordUsers;
use Paymenter\Extensions\Others\Discord\Models\DiscordUser;

class DiscordUserResource extends Resource
{
    protected static ?string $model = DiscordUser::class;

    protected static string|\BackedEnum|null $navigationIcon = 'ri-discord-line';

    protected static string|\BackedEnum|null $activeNavigationIcon = 'ri-discord-fill';

    protected static string|\UnitEnum|null $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'Discord Links';

    protected static ?string $modelLabel = 'Discord Link';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('avatar_url')
                    ->label('Avatar')
                    ->circular()
                    ->defaultImageUrl('https://cdn.discordapp.com/embed/avatars/0.png'),
                TextColumn::make('discord_username')
                    ->label('Discord User')
                    ->description(fn (DiscordUser $r) => $r->discord_discriminator !== '0' ? "#{$r->discord_discriminator}" : null)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('discord_id')
                    ->label('Discord ID')
                    ->copyable()
                    ->searchable(),
                TextColumn::make('user.name')
                    ->label('Paymenter User')
                    ->description(fn (DiscordUser $r) => $r->user?->email)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Linked At')
                    ->since()
                    ->sortable()
                    ->dateTimeTooltip(),
            ])
            ->filters([])
            ->recordActions([
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDiscordUsers::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
