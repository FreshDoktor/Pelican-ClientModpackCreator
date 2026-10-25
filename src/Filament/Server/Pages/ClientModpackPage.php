<?php

namespace FreshDoktor\MinecraftClientModpack\Filament\Server\Pages;

use App\Facades\Activity;
use App\Models\Server;
use App\Traits\Filament\BlockAccessInConflict;
use Exception;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use FreshDoktor\MinecraftClientModpack\Filament\Concerns\BuildsClientModpack;
use FreshDoktor\MinecraftClientModpack\Integration\ModrinthIntegration;
use FreshDoktor\MinecraftClientModpack\Jobs\BuildClientModpackJob;
use FreshDoktor\MinecraftClientModpack\Models\ClientModpack;
use FreshDoktor\MinecraftClientModpack\Services\ClientModpackService;
use FreshDoktor\MinecraftClientModpack\Services\ModClassifier;
use FreshDoktor\MinecraftClientModpack\Services\ModrinthApi;
use FreshDoktor\MinecraftClientModpack\Services\MrpackWriter;
use FreshDoktor\MinecraftClientModpack\Services\ServerFilesService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * The client modpack of a server: build it, hand out its download link, and choose which mods go
 * into it (mods of the server to leave out or keep, extra client mods, folders like config).
 *
 * In the menu unless minecraft-modrinth is enabled, then its mods page links here, see ModrinthIntegration.
 */
class ClientModpackPage extends Page implements HasTable
{
    use BlockAccessInConflict {
        canAccess as canAccessWhenNotInConflict;
    }
    use BuildsClientModpack;
    use InteractsWithTable;

    // A version picked for an extra mod, empty for the newest one that fits.
    protected const LATEST_VERSION = '';

    protected static string|\BackedEnum|null $navigationIcon = 'tabler-package-export';

    protected static ?string $slug = 'client-modpack';

    protected static ?int $navigationSort = 32;

    protected ?ClientModpack $pack = null;

    protected bool $packLoaded = false;

    /** Fingerprint of the mods folder as it is now, '' if it couldn't be read. */
    protected ?string $currentFingerprint = null;

    /** @var array{loader: ?string, minecraft: ?string}|null */
    protected ?array $environment = null;

    public static function canAccess(): bool
    {
        $server = Filament::getTenant();

        return $server instanceof Server && static::canAccessServer($server) && static::canAccessWhenNotInConflict();
    }

    public static function canAccessServer(Server $server): bool
    {
        return ClientModpackService::isModdedServer($server) && (user()?->can('file.read', $server) ?? false);
    }

    /**
     * With minecraft-modrinth enabled the mods page has a button for this page instead.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return !ModrinthIntegration::isModrinthPluginEnabled();
    }

    public static function getNavigationLabel(): string
    {
        return trans('minecraft-client-modpack::strings.page.title');
    }

    public static function getModelLabel(): string
    {
        return static::getNavigationLabel();
    }

    public static function getPluralModelLabel(): string
    {
        return static::getNavigationLabel();
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    public function getSubheading(): ?string
    {
        return trans('minecraft-client-modpack::strings.page.subheading');
    }

    public function mount(): void
    {
        // A build started elsewhere (e.g. on the mods page) is reported here as well.
        if ($this->isBuilding()) {
            $this->watchedBuild = $this->service()->getBuildStatus($this->getServer())['operation_id'] ?? null;
        }
    }

    public function hydrate(): void
    {
        $this->reportWatchedBuild(false);
    }

    protected function getServer(): Server
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return $server;
    }

    protected function getPack(): ?ClientModpack
    {
        if (!$this->packLoaded) {
            $this->pack = $this->service()->find($this->getServer());
            $this->packLoaded = true;
        }

        return $this->pack;
    }

    protected function getOrCreatePack(): ClientModpack
    {
        $this->forgetPack();

        return $this->pack = $this->service()->findOrCreate($this->getServer());
    }

    protected function forgetPack(): void
    {
        $this->pack = null;
        $this->packLoaded = false;
    }

    protected function isBuilt(): bool
    {
        return $this->getPack()?->isBuilt() ?? false;
    }

    /** @return array<string, mixed>|null */
    protected function getBuildStatus(): ?array
    {
        return $this->service()->getBuildStatus($this->getServer());
    }

    /**
     * Whether the mods folder changed since the last build. Unknown (false) while the daemon can't be reached.
     */
    protected function modsChanged(): bool
    {
        $pack = $this->getPack();

        if ($pack === null || !$pack->isBuilt() || $pack->mods_fingerprint === null) {
            return false;
        }

        $this->currentFingerprint ??= rescue(
            fn () => ClientModpackService::modsFingerprint(app(ServerFilesService::class)->listModJars($this->getServer())),
            '',
            report: false,
        );

        return $this->currentFingerprint !== '' && $this->currentFingerprint !== $pack->mods_fingerprint;
    }

    /**
     * Loader and Minecraft version to search extra mods for: the ones set, or the ones of the last build.
     *
     * @return array{loader: ?string, minecraft: ?string}
     */
    protected function getEnvironment(): array
    {
        if ($this->environment !== null) {
            return $this->environment;
        }

        $pack = $this->getPack();

        $loader = $pack?->loader ?: $pack?->built_loader;

        if ($loader === null) {
            $tags = $this->getServer()->loadMissing('egg')->egg->tags ?? [];
            $loader = collect(['neoforge', 'quilt', 'fabric', 'forge'])->first(fn (string $loader) => in_array($loader, $tags, true));
        }

        $minecraft = $pack?->minecraft_version ?: $pack?->built_minecraft_version;

        if ($minecraft === null) {
            $variable = $this->getServer()->variables()->whereIn('env_variable', ['MINECRAFT_VERSION', 'MC_VERSION'])->first()?->server_value;
            $minecraft = is_string($variable) && preg_match('/^\d+\.\d+(\.\d+)?$/', trim($variable)) ? trim($variable) : null;
        }

        return $this->environment = ['loader' => $loader, 'minecraft' => $minecraft];
    }

    /** @return array<int, string> loaders to search extra mods for */
    protected function getSearchLoaders(): array
    {
        $loader = $this->getEnvironment()['loader'];

        return match ($loader) {
            null => [],
            'quilt' => ['quilt', 'fabric'],
            default => [$loader],
        };
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Callout::make(trans('minecraft-client-modpack::strings.page.url_problem'))
                    ->description(fn () => $this->service()->publicUrlProblem())
                    ->warning()
                    ->visible(fn () => $this->service()->publicUrlProblem() !== null),
                Callout::make(trans('minecraft-client-modpack::strings.build.running'))
                    ->description(fn () => $this->describeBuildProgress())
                    ->icon('tabler-loader-2')
                    ->info()
                    ->visible(fn () => $this->isBuilding()),
                Callout::make(trans('minecraft-client-modpack::strings.build.failed'))
                    ->description(fn () => $this->getBuildStatus()['message'] ?? null)
                    ->danger()
                    ->visible(fn () => ($this->getBuildStatus()['status'] ?? null) === ClientModpackService::STATUS_FAILED),
                Callout::make(trans('minecraft-client-modpack::strings.build.outdated'))
                    ->description(fn () => trans($this->modsChanged()
                        ? 'minecraft-client-modpack::strings.build.outdated_mods'
                        : 'minecraft-client-modpack::strings.build.outdated_settings'))
                    ->warning()
                    ->visible(fn () => !$this->isBuilding() && $this->isBuilt() && ($this->getPack()->hasPendingChanges() || $this->modsChanged())),
                Section::make(trans('minecraft-client-modpack::strings.pack.section'))
                    ->icon('tabler-package')
                    ->visible(fn () => $this->isBuilt())
                    ->schema([
                        Grid::make(['default' => 1, 'sm' => 2, 'lg' => 4])
                            ->schema([
                                TextEntry::make('file_name')
                                    ->label(trans('minecraft-client-modpack::strings.pack.file'))
                                    ->state(fn () => $this->getPack()?->file_name)
                                    ->columnSpan(['default' => 1, 'sm' => 2]),
                                TextEntry::make('built_version')
                                    ->label(trans('minecraft-client-modpack::strings.pack.version'))
                                    ->state(fn () => $this->getPack()?->built_version)
                                    ->badge(),
                                TextEntry::make('size')
                                    ->label(trans('minecraft-client-modpack::strings.pack.size'))
                                    ->state(fn () => $this->service()->formatBytes((int) $this->getPack()?->size)),
                                TextEntry::make('built_minecraft_version')
                                    ->label(trans('minecraft-client-modpack::strings.pack.minecraft'))
                                    ->state(fn () => $this->getPack()?->built_minecraft_version)
                                    ->badge(),
                                TextEntry::make('built_loader')
                                    ->label(trans('minecraft-client-modpack::strings.pack.loader'))
                                    ->state(fn () => $this->getPack()?->built_loader ? Str::title($this->getPack()->built_loader).' '.$this->getPack()->built_loader_version : null)
                                    ->badge(),
                                TextEntry::make('mods')
                                    ->label(trans('minecraft-client-modpack::strings.pack.mods'))
                                    ->state(fn () => $this->describeCounts()),
                                TextEntry::make('built_at')
                                    ->label(trans('minecraft-client-modpack::strings.pack.built_at'))
                                    ->state(fn () => $this->getPack()?->built_at)
                                    ->since()
                                    ->dateTimeTooltip(),
                                TextEntry::make('built_by')
                                    ->label(trans('minecraft-client-modpack::strings.pack.built_by'))
                                    ->state(fn () => $this->getPack()?->builder?->username ?? trans('minecraft-client-modpack::strings.pack.unknown_user')),
                            ]),
                        TextEntry::make('download_url')
                            ->label(trans('minecraft-client-modpack::strings.pack.url'))
                            ->helperText(trans('minecraft-client-modpack::strings.pack.url_helper'))
                            ->state(fn () => $this->getPack() ? $this->service()->downloadUrl($this->getPack()) : null)
                            ->fontFamily(FontFamily::Mono)
                            ->copyable()
                            ->copyMessage(trans('minecraft-client-modpack::strings.page.copied')),
                    ]),
                Callout::make(trans('minecraft-client-modpack::strings.pack.warnings'))
                    ->description(fn () => $this->describeWarnings())
                    ->warning()
                    ->visible(fn () => $this->isBuilt() && !empty($this->getPack()?->warnings)),
                Section::make(trans('minecraft-client-modpack::strings.mods.section'))
                    ->description(trans('minecraft-client-modpack::strings.mods.description'))
                    ->icon('tabler-puzzle')
                    ->schema([
                        EmbeddedTable::make(),
                    ]),
            ]);
    }

    protected function describeBuildProgress(): string
    {
        $status = $this->getBuildStatus();
        $step = $status['step'] ?? null;

        if (($status['status'] ?? null) === ClientModpackService::STATUS_QUEUED || $step === null) {
            return trans('minecraft-client-modpack::strings.build.queued');
        }

        $label = trans("minecraft-client-modpack::strings.build.steps.$step");

        return isset($status['current'], $status['total']) && $status['total'] > 0
            ? trans('minecraft-client-modpack::strings.build.progress', ['step' => $label, 'current' => $status['current'], 'total' => $status['total']])
            : $label;
    }

    protected function describeCounts(): string
    {
        $rows = collect($this->getPack()?->contents ?? []);

        return trans('minecraft-client-modpack::strings.pack.mods_state', [
            'required' => $rows->where('decision', ModClassifier::REQUIRED)->count(),
            'optional' => $rows->where('decision', ModClassifier::OPTIONAL)->count(),
            'excluded' => $rows->where('decision', ModClassifier::EXCLUDED)->count(),
        ]);
    }

    protected function describeWarnings(): HtmlString
    {
        $lines = [];

        foreach ($this->getPack()?->warnings ?? [] as $warning) {
            if (is_array($warning) && is_string($warning['key'] ?? null)) {
                $lines[] = trans("minecraft-client-modpack::strings.warnings.{$warning['key']}", is_array($warning['params'] ?? null) ? $warning['params'] : []);
            }
        }

        return $this->bulletList(array_values(array_unique($lines)));
    }

    /**
     * The mods of the last build, with the changes made since: rules set or removed, extra mods
     * added or removed. Those take effect with the next build.
     *
     * @return array<string, array<string, mixed>> rows by key
     */
    protected function getRows(): array
    {
        $pack = $this->getPack();

        if ($pack === null) {
            return [];
        }

        $rules = $pack->mod_rules ?? [];
        $extras = collect($pack->extra_mods ?? [])->keyBy('project_id');
        $rows = [];

        foreach ($pack->isBuilt() ? ($pack->contents ?? []) : [] as $row) {
            if (!is_array($row) || !isset($row['key'], $row['source'])) {
                continue;
            }

            $row['rule'] = null;
            $row['pending'] = null;

            if ($row['source'] === BuildClientModpackJob::SOURCE_SERVER) {
                $rule = $rules[$row['rule_key'] ?? ''] ?? $rules[ClientModpackService::ruleKey(null, (string) $row['filename'])] ?? null;
                $row['rule'] = is_string($rule) ? $rule : null;

                if ($row['rule'] !== null && $this->decisionOfRule($row['rule']) !== $row['decision']) {
                    $row['pending'] = $this->decisionOfRule($row['rule']);
                } elseif ($row['rule'] === null && $row['reason'] === ModClassifier::REASON_RULE) {
                    $row['pending'] = 'auto';
                }
            } elseif ($row['source'] === BuildClientModpackJob::SOURCE_EXTRA && !$extras->has($row['project_id'])) {
                $row['pending'] = 'removed';
            }

            $rows[$row['key']] = $row;
        }

        foreach ($extras as $extra) {
            $key = BuildClientModpackJob::SOURCE_EXTRA.'-'.$extra['project_id'];

            if (isset($rows[$key])) {
                continue;
            }

            $rows[$key] = [
                'key' => $key,
                'source' => BuildClientModpackJob::SOURCE_EXTRA,
                'filename' => '',
                'title' => $extra['title'],
                'icon_url' => $extra['icon_url'] ?? null,
                'project_id' => $extra['project_id'],
                'project_slug' => $extra['slug'] ?? null,
                'version_number' => $extra['version_number'] ?? null,
                'rule_key' => null,
                'decision' => ModClassifier::REQUIRED,
                'reason' => ModClassifier::REASON_RULE,
                'delivery' => BuildClientModpackJob::DELIVERY_MODRINTH,
                'size' => null,
                'required_by' => null,
                'rule' => null,
                'pending' => 'added',
            ];
        }

        return $rows;
    }

    protected function decisionOfRule(string $rule): string
    {
        return match ($rule) {
            ModClassifier::RULE_INCLUDE => ModClassifier::REQUIRED,
            ModClassifier::RULE_OPTIONAL => ModClassifier::OPTIONAL,
            default => ModClassifier::EXCLUDED,
        };
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(function (?string $search, ?array $filters, int $page, int $recordsPerPage) {
                $rows = $this->getRows();

                if ($search) {
                    $search = strtolower($search);

                    $rows = array_filter($rows, fn (array $row) => str_contains(strtolower($row['title']), $search)
                        || str_contains(strtolower((string) $row['filename']), $search)
                        || str_contains(strtolower((string) $row['project_slug']), $search));
                }

                $decision = $filters['decision']['value'] ?? null;
                if (filled($decision)) {
                    $rows = array_filter($rows, fn (array $row) => $row['decision'] === $decision);
                }

                $source = $filters['source']['value'] ?? null;
                if (filled($source)) {
                    $rows = array_filter($rows, fn (array $row) => $row['source'] === $source);
                }

                return new LengthAwarePaginator(array_slice($rows, ($page - 1) * $recordsPerPage, $recordsPerPage, true), count($rows), $recordsPerPage, $page);
            })
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(25)
            // Renders the whole page again while a build runs, so its progress and result show up.
            ->poll(fn () => $this->isBuilding() ? '3s' : null)
            ->columns([
                ImageColumn::make('icon_url')
                    ->label('')
                    ->imageSize(32),
                TextColumn::make('title')
                    ->label(trans('minecraft-client-modpack::strings.mods.columns.name'))
                    ->weight('bold')
                    ->searchable()
                    ->description(fn (array $record) => match (true) {
                        !empty($record['required_by']) => trans('minecraft-client-modpack::strings.mods.required_by', ['name' => $record['required_by']]),
                        $record['filename'] !== '' => $record['filename'],
                        default => null,
                    })
                    ->url(fn (array $record) => !empty($record['project_slug']) ? 'https://modrinth.com/mod/'.$record['project_slug'] : null, true)
                    ->wrap(),
                TextColumn::make('version_number')
                    ->label(trans('minecraft-client-modpack::strings.mods.columns.version'))
                    ->badge()
                    ->color('gray')
                    ->placeholder('—'),
                TextColumn::make('source')
                    ->label(trans('minecraft-client-modpack::strings.mods.columns.source'))
                    ->badge()
                    ->formatStateUsing(fn ($state) => trans("minecraft-client-modpack::strings.mods.sources.$state"))
                    ->color(fn ($state) => $state === BuildClientModpackJob::SOURCE_SERVER ? 'gray' : 'info')
                    ->icon(fn ($state) => match ($state) {
                        BuildClientModpackJob::SOURCE_SERVER => 'tabler-server',
                        BuildClientModpackJob::SOURCE_EXTRA => 'tabler-device-desktop-plus',
                        default => 'tabler-link',
                    }),
                TextColumn::make('decision')
                    ->label(trans('minecraft-client-modpack::strings.mods.columns.decision'))
                    ->badge()
                    ->formatStateUsing(fn ($state, array $record) => trans("minecraft-client-modpack::strings.mods.decisions.$state")
                        .($record['rule'] !== null ? ' · '.trans('minecraft-client-modpack::strings.mods.manual') : ''))
                    ->color(fn ($state) => match ($state) {
                        ModClassifier::REQUIRED => 'success',
                        ModClassifier::OPTIONAL => 'info',
                        default => 'gray',
                    })
                    ->icon(fn (array $record) => $record['pending'] !== null ? 'tabler-clock' : null)
                    ->tooltip(fn (array $record) => $this->describeDecision($record)),
                TextColumn::make('delivery')
                    ->label(trans('minecraft-client-modpack::strings.mods.columns.delivery'))
                    ->formatStateUsing(fn ($state) => trans("minecraft-client-modpack::strings.mods.deliveries.$state"))
                    ->tooltip(fn ($state) => $state ? trans("minecraft-client-modpack::strings.mods.delivery_hints.$state") : null)
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('size')
                    ->label(trans('minecraft-client-modpack::strings.mods.columns.size'))
                    ->formatStateUsing(fn ($state) => $state ? $this->service()->formatBytes((int) $state) : '')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('decision')
                    ->label(trans('minecraft-client-modpack::strings.mods.columns.decision'))
                    ->options(collect(ModClassifier::DECISIONS)->mapWithKeys(fn ($decision) => [$decision => trans("minecraft-client-modpack::strings.mods.decisions.$decision")])->all()),
                SelectFilter::make('source')
                    ->label(trans('minecraft-client-modpack::strings.mods.columns.source'))
                    ->options(collect([BuildClientModpackJob::SOURCE_SERVER, BuildClientModpackJob::SOURCE_EXTRA, BuildClientModpackJob::SOURCE_DEPENDENCY])
                        ->mapWithKeys(fn ($source) => [$source => trans("minecraft-client-modpack::strings.mods.sources.$source")])->all()),
            ])
            ->toolbarActions([
                $this->addModAction(),
            ])
            ->recordActions([
                ActionGroup::make([
                    $this->ruleAction('include', ModClassifier::RULE_INCLUDE, 'tabler-circle-check', 'success'),
                    $this->ruleAction('optional', ModClassifier::RULE_OPTIONAL, 'tabler-circle-half-2', 'info'),
                    $this->ruleAction('exclude', ModClassifier::RULE_EXCLUDE, 'tabler-circle-x', 'gray'),
                    $this->ruleAction('auto', null, 'tabler-wand', 'gray'),
                ])
                    ->tooltip(trans('minecraft-client-modpack::strings.mods.actions.change'))
                    ->visible(fn (array $record) => $record['source'] === BuildClientModpackJob::SOURCE_SERVER && $this->userCan('file.update')),
                Action::make('remove')
                    ->iconButton()
                    ->icon('tabler-trash')
                    ->color('danger')
                    ->tooltip(trans('minecraft-client-modpack::strings.mods.actions.remove'))
                    ->visible(fn (array $record) => $record['source'] === BuildClientModpackJob::SOURCE_EXTRA && $record['pending'] !== 'removed' && $this->userCan('file.update'))
                    ->requiresConfirmation()
                    ->modalHeading(fn (array $record) => trans('minecraft-client-modpack::strings.mods.remove.heading', ['name' => $record['title']]))
                    ->modalDescription(trans('minecraft-client-modpack::strings.mods.remove.description'))
                    ->action(function (array $record) {
                        $pack = $this->getPack();

                        if ($pack === null) {
                            return;
                        }

                        $this->service()->removeExtraMod($pack, (string) $record['project_id']);

                        Activity::event('server:client-modpack.mod-remove')
                            ->subject($this->getServer())
                            ->property('name', $record['title'])
                            ->log();

                        $this->notifyRebuildNeeded(trans('minecraft-client-modpack::strings.notifications.mod_removed', ['name' => $record['title']]));
                    }),
            ])
            ->emptyStateIcon('tabler-package-off')
            ->emptyStateHeading(fn () => trans($this->isBuilding() ? 'minecraft-client-modpack::strings.build.running' : 'minecraft-client-modpack::strings.mods.empty_heading'))
            ->emptyStateDescription(fn () => $this->isBuilding() ? null : trans('minecraft-client-modpack::strings.mods.empty_description'));
    }

    /**
     * @param  array<string, mixed>  $record
     */
    protected function describeDecision(array $record): string
    {
        $reason = (string) ($record['reason'] ?? '');
        $lines = [trans("minecraft-client-modpack::strings.mods.reasons.$reason")];

        if ($record['pending'] !== null) {
            $lines[] = trans('minecraft-client-modpack::strings.mods.pending.'.match ($record['pending']) {
                'auto', 'added', 'removed' => $record['pending'],
                default => 'changed',
            }, ['decision' => trans('minecraft-client-modpack::strings.mods.decisions.'.(in_array($record['pending'], ModClassifier::DECISIONS, true) ? $record['pending'] : ModClassifier::REQUIRED))]);
        }

        return implode(' ', $lines);
    }

    protected function ruleAction(string $name, ?string $rule, string $icon, string $color): Action
    {
        return Action::make("rule_$name")
            ->label(trans("minecraft-client-modpack::strings.mods.actions.$name"))
            ->icon($icon)
            ->color($color)
            ->visible(fn (array $record) => $record['rule'] !== $rule)
            ->action(function (array $record) use ($rule, $name) {
                $pack = $this->getOrCreatePack();
                $key = (string) ($record['rule_key'] ?? ClientModpackService::ruleKey(null, (string) $record['filename']));

                // A rule by file name from before the mod was known on Modrinth would win otherwise.
                $this->service()->setRule($pack, ClientModpackService::ruleKey(null, (string) $record['filename']), null);
                $this->service()->setRule($pack, $key, $rule);

                Activity::event('server:client-modpack.rule')
                    ->subject($this->getServer())
                    ->property([
                        'name' => $record['title'],
                        'rule' => trans("minecraft-client-modpack::strings.mods.actions.$name"),
                    ])
                    ->log();

                $this->forgetPack();

                $this->notifyRebuildNeeded(trans('minecraft-client-modpack::strings.notifications.rule_saved', [
                    'name' => $record['title'],
                    'rule' => trans("minecraft-client-modpack::strings.mods.actions.$name"),
                ]));
            });
    }

    protected function addModAction(): Action
    {
        return Action::make('add_mod')
            ->label(trans('minecraft-client-modpack::strings.mods.actions.add'))
            ->icon('tabler-plus')
            ->visible(fn () => $this->userCan('file.update'))
            ->modalHeading(trans('minecraft-client-modpack::strings.mods.add.heading'))
            ->modalDescription(fn () => trans('minecraft-client-modpack::strings.mods.add.description', [
                'loader' => Str::title($this->getEnvironment()['loader'] ?? trans('minecraft-client-modpack::strings.page.unknown')),
                'minecraft' => $this->getEnvironment()['minecraft'] ?? trans('minecraft-client-modpack::strings.page.unknown'),
            ]))
            ->modalSubmitActionLabel(trans('minecraft-client-modpack::strings.mods.actions.add'))
            ->schema([
                Select::make('project_id')
                    ->label(trans('minecraft-client-modpack::strings.mods.add.project'))
                    ->helperText(trans('minecraft-client-modpack::strings.mods.add.project_helper'))
                    ->getSearchResultsUsing(fn (string $search) => $this->toProjectOptions(app(ModrinthApi::class)->searchMods($search, $this->getSearchLoaders(), $this->getEnvironment()['minecraft'])))
                    ->getOptionLabelUsing(function ($value) {
                        $project = is_string($value) ? app(ModrinthApi::class)->getProject($value) : null;

                        return $project ? $this->toProjectOptions([$project['id'] => $project])[$project['id']] : $value;
                    })
                    ->searchable()
                    ->native(false)
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn (Set $set) => $set('version_id', self::LATEST_VERSION)),
                Select::make('version_id')
                    ->label(trans('minecraft-client-modpack::strings.mods.add.version'))
                    ->helperText(trans('minecraft-client-modpack::strings.mods.add.version_helper'))
                    ->options(fn (Get $get) => $this->getVersionOptions(is_string($get('project_id')) ? $get('project_id') : null))
                    ->default(self::LATEST_VERSION)
                    ->selectablePlaceholder(false)
                    ->native(false),
            ])
            ->action(function (array $data) {
                $modrinth = app(ModrinthApi::class);
                $project = $modrinth->getProject((string) ($data['project_id'] ?? ''));

                if ($project === null) {
                    Notification::make()
                        ->title(trans('minecraft-client-modpack::strings.notifications.project_not_found'))
                        ->danger()
                        ->send();

                    return;
                }

                $versionId = filled($data['version_id'] ?? null) ? (string) $data['version_id'] : null;
                $version = null;

                if ($versionId !== null) {
                    $version = rescue(fn () => $modrinth->getVersion($versionId), null);

                    if ($version === null || ($version['project_id'] ?? null) !== $project['id']) {
                        Notification::make()
                            ->title(trans('minecraft-client-modpack::strings.notifications.version_not_found'))
                            ->danger()
                            ->send();

                        return;
                    }
                }

                $this->service()->addExtraMod($this->getOrCreatePack(), [
                    'project_id' => (string) $project['id'],
                    'slug' => (string) $project['slug'],
                    'title' => (string) $project['title'],
                    'icon_url' => is_string($project['icon_url'] ?? null) ? $project['icon_url'] : null,
                    'version_id' => $version !== null ? (string) $version['id'] : null,
                    'version_number' => $version !== null ? (string) ($version['version_number'] ?? '') : null,
                ]);

                Activity::event('server:client-modpack.mod-add')
                    ->subject($this->getServer())
                    ->property([
                        'name' => $project['title'],
                        'version' => $version['version_number'] ?? trans('minecraft-client-modpack::strings.mods.add.latest'),
                    ])
                    ->log();

                $this->forgetPack();

                $this->notifyRebuildNeeded(trans('minecraft-client-modpack::strings.notifications.mod_added', ['name' => $project['title']]));
            });
    }

    /**
     * @param  array<string, array<string, mixed>>  $projects
     * @return array<string, string>
     */
    protected function toProjectOptions(array $projects): array
    {
        $options = [];

        foreach ($projects as $id => $project) {
            $author = !empty($project['author']) ? ' · '.$project['author'] : '';
            $options[$id] = "{$project['title']} ({$project['slug']}){$author}";
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    protected function getVersionOptions(?string $projectId): array
    {
        $options = [self::LATEST_VERSION => trans('minecraft-client-modpack::strings.mods.add.latest')];

        if ($projectId === null || $projectId === '') {
            return $options;
        }

        try {
            $versions = app(ModrinthApi::class)->getCompatibleVersions($projectId, $this->getSearchLoaders(), $this->getEnvironment()['minecraft']);
        } catch (Exception $exception) {
            report($exception);

            return $options;
        }

        foreach (array_slice($versions, 0, 50) as $version) {
            $options[(string) $version['id']] = trim(($version['version_number'] ?? $version['id']).' · '.($version['version_type'] ?? ''), ' ·');
        }

        return $options;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('download')
                ->label(trans('minecraft-client-modpack::strings.actions.download'))
                ->icon('tabler-download')
                ->color('gray')
                ->visible(fn () => $this->isBuilt())
                ->url(fn () => $this->getPack() ? $this->service()->downloadUrl($this->getPack()) : null, true),
            Action::make('cancel')
                ->label(trans('minecraft-client-modpack::strings.actions.cancel'))
                ->icon('tabler-player-stop')
                ->color('danger')
                ->visible(fn () => $this->isBuilding() && $this->userCan('file.read-content'))
                ->requiresConfirmation()
                ->modalHeading(trans('minecraft-client-modpack::strings.actions.cancel'))
                ->modalDescription(trans('minecraft-client-modpack::strings.build.cancel_description'))
                ->action(function () {
                    $this->service()->cancelBuild($this->getServer());
                    $this->watchedBuild = null;

                    Activity::event('server:client-modpack.cancel')
                        ->subject($this->getServer())
                        ->log();

                    Notification::make()
                        ->title(trans('minecraft-client-modpack::strings.notifications.cancelled'))
                        ->success()
                        ->send();
                }),
            $this->makeBuildAction(false),
            $this->settingsAction(),
            ActionGroup::make([
                $this->resetLinkAction(),
                $this->deleteAction(),
            ]),
        ];
    }

    protected function settingsAction(): Action
    {
        return Action::make('settings')
            ->label(trans('minecraft-client-modpack::strings.actions.settings'))
            ->icon('tabler-settings')
            ->color('gray')
            ->visible(fn () => $this->userCan('file.update'))
            ->modalHeading(trans('minecraft-client-modpack::strings.settings.heading'))
            ->modalDescription(trans('minecraft-client-modpack::strings.settings.description'))
            ->fillForm(function () {
                $pack = $this->getPack();

                return [
                    'name' => $pack?->name,
                    'version' => $pack?->version,
                    'summary' => $pack?->summary,
                    'minecraft_version' => $pack?->minecraft_version,
                    'loader' => $pack?->loader,
                    'loader_version' => $pack?->loader_version,
                    'embed_unknown' => $pack?->embed_unknown ?? true,
                    'include_paths' => $pack?->include_paths ?? [],
                ];
            })
            ->schema([
                Grid::make(['default' => 1, 'md' => 2])
                    ->schema([
                        TextInput::make('name')
                            ->label(trans('minecraft-client-modpack::strings.settings.name'))
                            ->placeholder(fn () => $this->getServer()->name)
                            ->maxLength(100),
                        TextInput::make('version')
                            ->label(trans('minecraft-client-modpack::strings.settings.version'))
                            ->placeholder(trans('minecraft-client-modpack::strings.settings.version_placeholder'))
                            ->helperText(trans('minecraft-client-modpack::strings.settings.version_helper'))
                            ->regex('/^[0-9A-Za-z][0-9A-Za-z.+_-]*$/')
                            ->maxLength(50),
                    ]),
                Textarea::make('summary')
                    ->label(trans('minecraft-client-modpack::strings.settings.summary'))
                    ->helperText(trans('minecraft-client-modpack::strings.settings.summary_helper'))
                    ->rows(2)
                    ->maxLength(500),
                Section::make(trans('minecraft-client-modpack::strings.settings.game'))
                    ->description(trans('minecraft-client-modpack::strings.settings.game_description'))
                    ->compact()
                    ->columns(['default' => 1, 'md' => 3])
                    ->schema([
                        TextInput::make('minecraft_version')
                            ->label(trans('minecraft-client-modpack::strings.settings.minecraft_version'))
                            ->placeholder(fn () => $this->getPack()?->built_minecraft_version ?? trans('minecraft-client-modpack::strings.settings.automatic'))
                            ->regex('/^\d+\.\d+(\.\d+)?(-[0-9A-Za-z.]+)?$/')
                            ->maxLength(30),
                        Select::make('loader')
                            ->label(trans('minecraft-client-modpack::strings.settings.loader'))
                            ->placeholder(fn () => trans('minecraft-client-modpack::strings.settings.automatic').($this->getPack()?->built_loader ? ' ('.Str::title($this->getPack()->built_loader).')' : ''))
                            ->options(collect(ClientModpackService::LOADERS)->mapWithKeys(fn ($loader) => [$loader => $loader === 'neoforge' ? 'NeoForge' : Str::title($loader)])->all())
                            ->native(false),
                        TextInput::make('loader_version')
                            ->label(trans('minecraft-client-modpack::strings.settings.loader_version'))
                            ->placeholder(fn () => $this->getPack()?->built_loader_version ?? trans('minecraft-client-modpack::strings.settings.automatic'))
                            ->regex('/^[0-9][0-9A-Za-z.+_-]*$/')
                            ->maxLength(50),
                    ]),
                Toggle::make('embed_unknown')
                    ->label(trans('minecraft-client-modpack::strings.settings.embed_unknown'))
                    ->helperText(trans('minecraft-client-modpack::strings.settings.embed_unknown_helper')),
                TagsInput::make('include_paths')
                    ->label(trans('minecraft-client-modpack::strings.settings.include_paths'))
                    ->helperText(trans('minecraft-client-modpack::strings.settings.include_paths_helper'))
                    ->placeholder('config')
                    ->suggestions(['config', 'defaultconfigs', 'kubejs', 'resourcepacks', 'shaderpacks', 'options.txt'])
                    ->visible(fn () => $this->userCan('file.read-content')),
            ])
            ->action(function (array $data) {
                $pack = $this->getOrCreatePack();

                $attributes = [
                    'name' => filled($data['name'] ?? null) ? trim((string) $data['name']) : null,
                    'version' => filled($data['version'] ?? null) ? trim((string) $data['version']) : null,
                    'summary' => filled($data['summary'] ?? null) ? trim((string) $data['summary']) : null,
                    'minecraft_version' => filled($data['minecraft_version'] ?? null) ? trim((string) $data['minecraft_version']) : null,
                    'loader' => in_array($data['loader'] ?? null, ClientModpackService::LOADERS, true) ? $data['loader'] : null,
                    'loader_version' => filled($data['loader_version'] ?? null) ? trim((string) $data['loader_version']) : null,
                    'embed_unknown' => (bool) ($data['embed_unknown'] ?? true),
                ];

                $invalid = [];

                if ($this->userCan('file.read-content')) {
                    $paths = [];

                    foreach (is_array($data['include_paths'] ?? null) ? $data['include_paths'] : [] as $path) {
                        try {
                            $paths[] = MrpackWriter::normalisePath(trim((string) $path));
                        } catch (InvalidArgumentException) {
                            $invalid[] = (string) $path;
                        }
                    }

                    $attributes['include_paths'] = $paths === [] ? null : array_values(array_unique($paths));
                }

                $this->service()->updateSettings($pack, $attributes);

                Activity::event('server:client-modpack.settings')
                    ->subject($this->getServer())
                    ->property('name', $this->service()->packName($pack))
                    ->log();

                $this->forgetPack();

                if ($invalid !== []) {
                    Notification::make()
                        ->title(trans('minecraft-client-modpack::strings.notifications.invalid_paths'))
                        ->body($this->bulletList($invalid))
                        ->warning()
                        ->send();
                }

                $this->notifyRebuildNeeded(trans('minecraft-client-modpack::strings.notifications.settings_saved'));
            });
    }

    protected function resetLinkAction(): Action
    {
        return Action::make('reset_link')
            ->label(trans('minecraft-client-modpack::strings.actions.reset_link'))
            ->icon('tabler-link-off')
            ->visible(fn () => $this->isBuilt() && $this->userCan('file.update'))
            ->requiresConfirmation()
            ->modalHeading(trans('minecraft-client-modpack::strings.actions.reset_link'))
            ->modalDescription(trans('minecraft-client-modpack::strings.reset_link.description'))
            ->action(function () {
                $pack = $this->getPack();

                if ($pack === null) {
                    return;
                }

                $this->service()->resetLink($pack);

                Activity::event('server:client-modpack.link-reset')
                    ->subject($this->getServer())
                    ->log();

                Notification::make()
                    ->title(trans('minecraft-client-modpack::strings.notifications.link_reset'))
                    ->success()
                    ->send();
            });
    }

    protected function deleteAction(): Action
    {
        return Action::make('delete')
            ->label(trans('minecraft-client-modpack::strings.actions.delete'))
            ->icon('tabler-trash')
            ->color('danger')
            ->visible(fn () => $this->getPack() !== null && $this->userCan('file.delete'))
            ->requiresConfirmation()
            ->modalHeading(trans('minecraft-client-modpack::strings.delete.heading'))
            ->modalDescription(trans('minecraft-client-modpack::strings.delete.description'))
            ->action(function () {
                $pack = $this->getPack();

                if ($pack === null) {
                    return;
                }

                $this->service()->cancelBuild($this->getServer());
                $this->watchedBuild = null;

                try {
                    $this->service()->delete($pack);
                } catch (Throwable $exception) {
                    report($exception);

                    Notification::make()
                        ->title(trans('minecraft-client-modpack::strings.notifications.delete_failed'))
                        ->body(trans('minecraft-client-modpack::strings.errors.unexpected'))
                        ->danger()
                        ->send();

                    return;
                }

                Activity::event('server:client-modpack.delete')
                    ->subject($this->getServer())
                    ->log();

                $this->forgetPack();

                Notification::make()
                    ->title(trans('minecraft-client-modpack::strings.notifications.deleted'))
                    ->success()
                    ->send();
            });
    }

    protected function notifyRebuildNeeded(string $title): void
    {
        Notification::make()
            ->title($title)
            ->body($this->isBuilt() || $this->getPack()?->isBuilt() ? trans('minecraft-client-modpack::strings.notifications.rebuild_hint') : null)
            ->success()
            ->send();
    }

    /**
     * Callout and notification bodies are HTML, every line is escaped.
     *
     * @param  array<int, string>  $lines
     */
    protected function bulletList(array $lines): HtmlString
    {
        if (count($lines) === 1) {
            return new HtmlString(e(reset($lines)));
        }

        return new HtmlString('<ul style="list-style: disc; padding-inline-start: 1.25rem">'
            .implode('', array_map(fn (string $line) => '<li>'.e($line).'</li>', $lines))
            .'</ul>');
    }
}
