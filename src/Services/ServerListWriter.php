<?php

namespace FreshDoktor\MinecraftClientModpack\Services;

use InvalidArgumentException;

/**
 * Writes Minecraft's server list (servers.dat): uncompressed NBT with a root compound that holds a list
 * "servers" of compounds with "name" and "ip". The game reads the file once the multiplayer screen opens
 * and fills in icon, player count and so on itself when it pings the servers.
 *
 * @see https://minecraft.wiki/w/Servers.dat_format
 */
class ServerListWriter
{
    public const FILE = 'servers.dat';

    protected const TAG_END = 0;

    protected const TAG_STRING = 8;

    protected const TAG_LIST = 9;

    protected const TAG_COMPOUND = 10;

    /**
     * @param  array<int, array{name: string, ip: string}>  $servers
     *
     * @throws InvalidArgumentException
     */
    public function encode(array $servers): string
    {
        $nbt = chr(self::TAG_COMPOUND).$this->string('');

        $nbt .= chr(self::TAG_LIST).$this->string('servers');
        $nbt .= chr(self::TAG_COMPOUND).pack('N', count($servers));

        foreach ($servers as $server) {
            $nbt .= chr(self::TAG_STRING).$this->string('name').$this->string($server['name']);
            $nbt .= chr(self::TAG_STRING).$this->string('ip').$this->string($server['ip']);
            $nbt .= chr(self::TAG_END);
        }

        return $nbt.chr(self::TAG_END);
    }

    /**
     * A string as Java's DataOutput writes it: its length in bytes, then modified UTF-8 (NUL as two
     * bytes, characters outside the BMP as two surrogates of three bytes each).
     *
     * @throws InvalidArgumentException
     */
    protected function string(string $value): string
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            throw new InvalidArgumentException('Not valid UTF-8');
        }

        $bytes = '';
        $units = unpack('n*', mb_convert_encoding($value, 'UTF-16BE', 'UTF-8')) ?: [];

        foreach ($units as $unit) {
            $bytes .= match (true) {
                $unit >= 0x01 && $unit <= 0x7F => chr($unit),
                $unit <= 0x7FF => chr(0xC0 | ($unit >> 6)).chr(0x80 | ($unit & 0x3F)),
                default => chr(0xE0 | ($unit >> 12)).chr(0x80 | (($unit >> 6) & 0x3F)).chr(0x80 | ($unit & 0x3F)),
            };
        }

        if (strlen($bytes) > 0xFFFF) {
            throw new InvalidArgumentException('String too long');
        }

        return pack('n', strlen($bytes)).$bytes;
    }
}
