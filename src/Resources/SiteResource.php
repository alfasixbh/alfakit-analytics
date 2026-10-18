<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAnalytics\Resources;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Actions\ActionGroup;
use AlfaSix\Alfakit\Actions\DeleteAction;
use AlfaSix\Alfakit\Actions\EditAction;
use AlfaSix\Alfakit\Forms\Components\Repeater;
use AlfaSix\Alfakit\Forms\Components\Select;
use AlfaSix\Alfakit\Forms\Components\TagsInput;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Forms\Components\Toggle;
use AlfaSix\Alfakit\Localization\Languages;
use AlfaSix\Alfakit\Resources\Resource;
use AlfaSix\Alfakit\Schemas\Components\Section;
use AlfaSix\Alfakit\Schemas\Components\Text;
use AlfaSix\Alfakit\Schemas\Components\Utilities\Get;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\AlfakitAnalytics\Analytics;
use AlfaSix\AlfakitAnalytics\AnalyticsPlugin;
use AlfaSix\AlfakitAnalytics\Models\Site;
use AlfaSix\AlfakitAnalytics\Resources\SiteResource\Pages;
use AlfaSix\AlfakitAnalytics\Support\Goal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The tracked sites of the panel (of the current tenant on tenancy panels): domain,
 * timezone, goals, the tracking snippet and the shared dashboard link.
 *
 * Extend it and pass your class to `AnalyticsPlugin::make()->usingSiteResource(...)`.
 */
class SiteResource extends Resource
{
    protected static ?string $slug = 'analytics-sites';

    protected static ?string $recordTitleAttribute = 'name';

    // Sites are tenant-owned through `tenant_type` / `tenant_id`: see getEloquentQuery().
    protected static bool $isScopedToTenant = false;

    protected static bool $isGloballySearchable = false;

    protected static bool $isDiscovered = false;

    public static function getModel(): string
    {
        return Site::class;
    }

    public static function getPlugin(): AnalyticsPlugin
    {
        return AnalyticsPlugin::get();
    }

    public static function getModelLabel(): string
    {
        return (string) __('alfakit-analytics::analytics.sites.label');
    }

    public static function getPluralModelLabel(): string
    {
        return (string) __('alfakit-analytics::analytics.sites.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return (string) __('alfakit-analytics::analytics.sites.navigation');
    }

    public static function getNavigationGroup(): ?string
    {
        return static::getPlugin()->getNavigationGroup();
    }

    public static function getNavigationParentItem(): ?string
    {
        return static::getPlugin()->getNavigationGroup() === null ? static::getPlugin()->getNavigationLabel() : null;
    }

    public static function getNavigationIcon(): ?string
    {
        return 'globe';
    }

    public static function getNavigationSort(): ?int
    {
        $sort = static::getPlugin()->getNavigationSort();

        return $sort === null ? null : $sort + 1;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::getPlugin()->shouldRegisterNavigation() && static::can('viewAny');
    }

    public static function getEloquentQuery(): Builder
    {
        /** @var Builder<Site> $query */
        $query = parent::getEloquentQuery();
        $panel = alfakit()->getCurrentPanel();

        if ($panel !== null && $panel->hasTenancy() && static::getPlugin()->isScopedToTenant()) {
            $tenant = $panel->getTenant();

            // A tenancy panel without a tenant (shouldn't happen) shows nothing.
            return $tenant === null ? $query->whereRaw('1 = 0') : Site::constrainToTenant($query, $tenant);
        }

        return $query;
    }

    // ---------------------------------------------------------------- Authorisation

    public static function can(string $action, ?Model $record = null): bool
    {
        $plugin = static::getPlugin();

        if (! $plugin->isAuthorized()) {
            return false;
        }

        // A site of another tenant is never accessible, whatever the policy says.
        if ($record instanceof Site && ! $record->isOwnedByTenant($plugin->getOwningTenant())) {
            return false;
        }

        return \Illuminate\Support\Facades\Gate::getPolicyFor(Site::class) === null ? true : parent::can($action, $record);
    }

    // ---------------------------------------------------------------- Form

    public static function form(Schema $schema): Schema
    {
        $plugin = static::getPlugin();

        return $schema->components([
            Section::make(__('alfakit-analytics::analytics.sites.sections.site'))
                ->description(__('alfakit-analytics::analytics.sites.sections.site_description'))
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->label(__('alfakit-analytics::analytics.sites.fields.name'))
                        ->required()
                        ->maxLength(255),
                    TextInput::make('domain')
                        ->label(__('alfakit-analytics::analytics.sites.fields.domain'))
                        ->placeholder('example.com')
                        ->helperText(__('alfakit-analytics::analytics.sites.fields.domain_help'))
                        ->maxLength(255)
                        ->regex('/^(https?:\/\/)?(\*\.)?[A-Za-z0-9.-]+(:\d+)?\/?$/'),
                    TagsInput::make('domains')
                        ->label(__('alfakit-analytics::analytics.sites.fields.domains'))
                        ->placeholder('shop.example.com')
                        ->helperText(__('alfakit-analytics::analytics.sites.fields.domains_help')),
                    Select::make('timezone')
                        ->label(__('alfakit-analytics::analytics.sites.fields.timezone'))
                        ->options(Languages::timezones())
                        ->searchable()
                        ->default(config('app.timezone', 'UTC'))
                        ->required()
                        ->in(array_keys(Languages::timezones())),
                    Toggle::make('is_active')
                        ->label(__('alfakit-analytics::analytics.sites.fields.is_active'))
                        ->helperText(__('alfakit-analytics::analytics.sites.fields.is_active_help'))
                        ->default(true),
                ]),

            Section::make(__('alfakit-analytics::analytics.sites.sections.goals'))
                ->description(__('alfakit-analytics::analytics.sites.sections.goals_description'))
                ->collapsible()
                ->schema([
                    Repeater::make('goals')
                        ->hiddenLabel()
                        ->label(__('alfakit-analytics::analytics.sites.sections.goals'))
                        ->addActionLabel(__('alfakit-analytics::analytics.sites.goals.add'))
                        ->columns(3)
                        ->maxItems(50)
                        ->defaultItems(0)
                        ->schema([
                            Select::make('type')
                                ->label(__('alfakit-analytics::analytics.sites.goals.type'))
                                ->options([
                                    Goal::PAGE => __('alfakit-analytics::analytics.sites.goals.types.page'),
                                    Goal::EVENT => __('alfakit-analytics::analytics.sites.goals.types.event'),
                                ])
                                ->default(Goal::EVENT)
                                ->required()
                                ->live(),
                            TextInput::make('match')
                                ->label(static fn (Get $get): string => $get('type') === Goal::PAGE
                                    ? __('alfakit-analytics::analytics.sites.goals.path')
                                    : __('alfakit-analytics::analytics.sites.goals.event'))
                                ->placeholder(static fn (Get $get): string => $get('type') === Goal::PAGE ? '/checkout/thanks' : 'Signup')
                                ->helperText(static fn (Get $get): ?string => $get('type') === Goal::PAGE ? __('alfakit-analytics::analytics.sites.goals.path_help') : null)
                                ->required()
                                ->maxLength(255),
                            TextInput::make('name')
                                ->label(__('alfakit-analytics::analytics.sites.goals.name'))
                                ->placeholder(__('alfakit-analytics::analytics.sites.goals.name_placeholder'))
                                ->maxLength(120),
                        ]),
                ]),

            Section::make(__('alfakit-analytics::analytics.sites.sections.tracking'))
                ->description(__('alfakit-analytics::analytics.sites.sections.tracking_description'))
                ->visibleOn('edit')
                ->schema([
                    Text::make(static fn (?Model $record): string => $record instanceof Site ? (string) Analytics::script($record) : '')
                        ->fontFamily('mono')
                        ->copyable(copyMessage: __('alfakit-analytics::analytics.sites.copied')),
                    Text::make(static fn (?Model $record): string => $record instanceof Site ? __('alfakit-analytics::analytics.sites.key', ['key' => $record->key]) : '')
                        ->size('sm'),
                ]),

            Section::make(__('alfakit-analytics::analytics.sites.sections.sharing'))
                ->description(__('alfakit-analytics::analytics.sites.sections.sharing_description'))
                ->visible(static fn (): bool => $plugin->canShare())
                ->schema([
                    Toggle::make('is_shared')
                        ->label(__('alfakit-analytics::analytics.sites.fields.is_shared'))
                        ->helperText(__('alfakit-analytics::analytics.sites.fields.is_shared_help')),
                    Text::make(static fn (?Model $record): string => $record instanceof Site ? (string) Analytics::shareUrl($record) : '')
                        ->fontFamily('mono')
                        ->copyable(copyMessage: __('alfakit-analytics::analytics.share.copied'))
                        ->visible(static fn (?Model $record): bool => $record instanceof Site && $record->is_shared),
                ]),
        ]);
    }

    /**
     * Clean the form state: goals without a match are dropped, hostnames normalised.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalizeFormData(array $data): array
    {
        if (array_key_exists('goals', $data)) {
            $goals = [];

            foreach ((array) $data['goals'] as $goal) {
                $goal = is_array($goal) ? Goal::fromArray($goal) : null;

                if ($goal !== null) {
                    $goals[] = $goal->toArray();
                }
            }

            $data['goals'] = $goals === [] ? null : $goals;
        }

        if (array_key_exists('domains', $data)) {
            $domains = array_values(array_unique(array_filter(array_map(static fn (mixed $host): ?string => is_string($host) ? Site::normalizeHostname($host) : null, (array) $data['domains']))));
            $data['domains'] = $domains === [] ? null : $domains;
        }

        if (! static::getPlugin()->canShare()) {
            unset($data['is_shared']);
        }

        return $data;
    }

    // ---------------------------------------------------------------- Table

    public static function table(Table $table): Table
    {
        $page = static::getPlugin()->getPage();

        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('alfakit-analytics::analytics.sites.fields.name'))
                    ->description(static fn (Site $record): ?string => $record->domain)
                    ->weight('medium')
                    ->searchable(['name', 'domain'])
                    ->sortable(),
                TextColumn::make('key')
                    ->label(__('alfakit-analytics::analytics.sites.fields.key'))
                    ->fontFamily('mono')
                    ->copyable()
                    ->toggleable(),
                TextColumn::make('timezone')
                    ->label(__('alfakit-analytics::analytics.sites.fields.timezone'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')
                    ->label(__('alfakit-analytics::analytics.sites.fields.status'))
                    ->getStateUsing(static fn (Site $record): string => $record->is_active ? 'active' : 'paused')
                    ->formatStateUsing(static fn (string $state): string => __('alfakit-analytics::analytics.sites.statuses.'.$state))
                    ->badge()
                    ->color(static fn (string $state): string => $state === 'active' ? 'success' : 'gray'),
                TextColumn::make('is_shared')
                    ->label(__('alfakit-analytics::analytics.sites.fields.is_shared'))
                    ->formatStateUsing(static fn (mixed $state): string => $state ? __('alfakit-analytics::analytics.sites.shared') : __('alfakit-analytics::analytics.sites.private'))
                    ->badge()
                    ->color(static fn (mixed $state): string => $state ? 'info' : 'gray')
                    ->visible(static fn (): bool => static::getPlugin()->canShare()),
            ])
            ->recordActions([
                Action::make('dashboard')
                    ->label(__('alfakit-analytics::analytics.sites.open_dashboard'))
                    ->icon('chart-line')
                    ->url(static fn (Site $record): string => $page::getUrl(['site' => $record->getKey()]))
                    ->visible(static fn (Site $record): bool => static::getPlugin()->can('view', $record)),
                ActionGroup::make([
                    EditAction::make(),
                    Action::make('rotateShareLink')
                        ->label(__('alfakit-analytics::analytics.share.rotate'))
                        ->icon('refresh-cw')
                        ->requiresConfirmation()
                        ->modalDescription(__('alfakit-analytics::analytics.share.rotate_description'))
                        ->visible(static fn (Site $record): bool => static::getPlugin()->canShare() && $record->is_shared)
                        ->authorize(static fn (Site $record): bool => static::can('update', $record))
                        ->action(static fn (Site $record) => $record->rotateShareToken()),
                    DeleteAction::make(),
                ]),
            ])
            ->defaultSort('name')
            ->emptyStateIcon('globe')
            ->emptyStateHeading(__('alfakit-analytics::analytics.sites.empty.heading'))
            ->emptyStateDescription(__('alfakit-analytics::analytics.sites.empty.description'));
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSites::route('/'),
            'create' => Pages\CreateSite::route('/create'),
            'edit' => Pages\EditSite::route('/{record}/edit'),
        ];
    }
}
