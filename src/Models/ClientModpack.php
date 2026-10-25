<?php

namespace FreshDoktor\MinecraftClientModpack\Models;

use App\Models\Server;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The client modpack of a server: its settings and the last build. The file itself lives on the
 * panel's local disk, see ClientModpackService.
 *
 * @phpstan-type ExtraMod array{project_id: string, slug: string, title: string, icon_url: ?string, version_id: ?string, version_number: ?string}
 * @phpstan-type ContentRow array{key: string, source: string, filename: string, title: string, icon_url: ?string, project_id: ?string, project_slug: ?string, version_number: ?string, rule_key: ?string, decision: string, reason: string, delivery: ?string, size: ?int}
 *
 * @property int $id
 * @property int $server_id
 * @property string $link_token
 * @property ?string $name
 * @property ?string $version
 * @property ?string $summary
 * @property ?string $minecraft_version
 * @property ?string $loader
 * @property ?string $loader_version
 * @property bool $embed_unknown
 * @property ?array<int, string> $include_paths
 * @property ?array<string, string> $mod_rules rule by rule key, see ClientModpackService::ruleKey()
 * @property ?array<int, ExtraMod> $extra_mods
 * @property ?string $file_token
 * @property ?string $file_name
 * @property ?int $size
 * @property ?string $sha1
 * @property ?string $built_version
 * @property ?string $built_minecraft_version
 * @property ?string $built_loader
 * @property ?string $built_loader_version
 * @property ?array<int, ContentRow> $contents
 * @property ?array<int, string> $warnings
 * @property ?string $mods_fingerprint
 * @property ?CarbonImmutable $built_at
 * @property ?int $built_by
 * @property ?CarbonImmutable $settings_changed_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Server $server
 * @property-read ?User $builder
 */
class ClientModpack extends Model
{
    protected $table = 'minecraft_client_modpacks';

    protected $fillable = [
        'server_id',
        'link_token',
        'name',
        'version',
        'summary',
        'minecraft_version',
        'loader',
        'loader_version',
        'embed_unknown',
        'include_paths',
        'mod_rules',
        'extra_mods',
        'file_token',
        'file_name',
        'size',
        'sha1',
        'built_version',
        'built_minecraft_version',
        'built_loader',
        'built_loader_version',
        'contents',
        'warnings',
        'mods_fingerprint',
        'built_at',
        'built_by',
        'settings_changed_at',
    ];

    protected $attributes = [
        'embed_unknown' => true,
    ];

    protected function casts(): array
    {
        return [
            'embed_unknown' => 'boolean',
            'include_paths' => 'array',
            'mod_rules' => 'array',
            'extra_mods' => 'array',
            'size' => 'integer',
            'contents' => 'array',
            'warnings' => 'array',
            'built_at' => 'immutable_datetime',
            'settings_changed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function builder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'built_by');
    }

    public function isBuilt(): bool
    {
        return $this->file_token !== null;
    }

    /**
     * Whether settings changed since the last build, so the file doesn't reflect them yet.
     */
    public function hasPendingChanges(): bool
    {
        return $this->isBuilt()
            && $this->settings_changed_at !== null
            && ($this->built_at === null || $this->settings_changed_at->gt($this->built_at));
    }
}
