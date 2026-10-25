<?php

namespace FreshDoktor\MinecraftClientModpack\Services;

/**
 * Decides whether players need a mod of the server: required, optional (the launcher may let the
 * player leave it out) or excluded because it only runs on the server.
 *
 * What the jar says about itself wins over Modrinth, a mod that refuses to load on the client can't
 * be needed there. A rule set on the page wins over both.
 *
 * @phpstan-import-type JarInfo from ModJarParser
 *
 * @phpstan-type Classification array{decision: string, reason: string}
 */
class ModClassifier
{
    public const REQUIRED = 'required';

    public const OPTIONAL = 'optional';

    public const EXCLUDED = 'excluded';

    public const DECISIONS = [self::REQUIRED, self::OPTIONAL, self::EXCLUDED];

    // Rules set by hand on the page.
    public const RULE_INCLUDE = 'include';

    public const RULE_OPTIONAL = 'optional';

    public const RULE_EXCLUDE = 'exclude';

    public const RULES = [self::RULE_INCLUDE, self::RULE_OPTIONAL, self::RULE_EXCLUDE];

    // Why a mod got its decision, shown next to it.
    public const REASON_RULE = 'rule';

    public const REASON_JAR_SERVER_ONLY = 'jar_server_only';

    public const REASON_MODRINTH_SERVER_ONLY = 'modrinth_server_only';

    public const REASON_JAR_CLIENT_ONLY = 'jar_client_only';

    public const REASON_MODRINTH_CLIENT_REQUIRED = 'modrinth_client_required';

    public const REASON_MODRINTH_CLIENT_OPTIONAL = 'modrinth_client_optional';

    public const REASON_FORGE_OPTIONAL = 'forge_optional';

    public const REASON_NEEDED = 'needed';

    // Set by the build when a mod that should be in the pack can't be put into it.
    public const REASON_UNREADABLE = 'unreadable';

    public const REASON_TOO_LARGE = 'too_large';

    public const REASON_NOT_EMBEDDED = 'not_embedded';

    public const REASON_EMBED_LIMIT = 'embed_limit';

    /**
     * @param  JarInfo|null  $jar  null if the jar couldn't be read
     * @param  array<string, mixed>|null  $project  Modrinth project of the jar, null if Modrinth doesn't know it
     * @param  string|null  $rule  one of RULES
     * @param  string|null  $loader  loader of the server
     * @return Classification
     */
    public function classify(?array $jar, ?array $project, ?string $rule, ?string $loader = null): array
    {
        if ($rule !== null && in_array($rule, self::RULES, true)) {
            return [
                'decision' => match ($rule) {
                    self::RULE_INCLUDE => self::REQUIRED,
                    self::RULE_OPTIONAL => self::OPTIONAL,
                    default => self::EXCLUDED,
                },
                'reason' => self::REASON_RULE,
            ];
        }

        $environment = $jar['environment'] ?? null;
        // A jar made for several loaders also carries a mods.toml, which says nothing on Fabric or Quilt.
        $displayTest = in_array($loader, [null, 'forge', 'neoforge'], true) ? ($jar['display_test'] ?? null) : null;
        $clientSide = is_string($project['client_side'] ?? null) ? $project['client_side'] : null;

        return match (true) {
            $environment === 'server' => $this->result(self::EXCLUDED, self::REASON_JAR_SERVER_ONLY),
            $environment === 'client' => $this->result(self::REQUIRED, self::REASON_JAR_CLIENT_ONLY),
            $clientSide === 'unsupported' => $this->result(self::EXCLUDED, self::REASON_MODRINTH_SERVER_ONLY),
            $clientSide === 'required' => $this->result(self::REQUIRED, self::REASON_MODRINTH_CLIENT_REQUIRED),
            $clientSide === 'optional' => $this->result(self::OPTIONAL, self::REASON_MODRINTH_CLIENT_OPTIONAL),
            // Only says that players can join without the mod, client mods like JEI use it as well.
            in_array($displayTest, ['IGNORE_SERVER_VERSION', 'IGNORE_ALL_VERSION', 'NONE'], true) => $this->result(self::OPTIONAL, self::REASON_FORGE_OPTIONAL),
            default => $this->result(self::REQUIRED, self::REASON_NEEDED),
        };
    }

    /**
     * The env of a file in modrinth.index.json.
     *
     * @param  array<string, mixed>|null  $project  Modrinth project, null for a mod that isn't on Modrinth
     * @param  bool  $onServer  whether the mod is on the server
     * @return array{client: string, server: string}
     */
    public function environment(string $decision, ?array $project, bool $onServer): array
    {
        $serverSide = is_string($project['server_side'] ?? null) ? $project['server_side'] : null;

        return [
            'client' => $decision === self::OPTIONAL ? 'optional' : 'required',
            'server' => match (true) {
                $onServer => 'required',
                $serverSide === 'required' => 'required',
                $serverSide === 'unsupported' => 'unsupported',
                default => 'optional',
            },
        ];
    }

    /** @return Classification */
    protected function result(string $decision, string $reason): array
    {
        return ['decision' => $decision, 'reason' => $reason];
    }
}
