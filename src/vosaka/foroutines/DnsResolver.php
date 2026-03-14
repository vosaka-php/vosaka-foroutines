<?php

declare(strict_types=1);

namespace vosaka\foroutines;

use RuntimeException;

/**
 * Lightweight async DNS resolver using UDP + AsyncIO stream watchers.
 *
 * Avoids PHP's blocking gethostbyname() by crafting raw DNS queries and
 * reading responses via non-blocking sockets. Works on Linux/macOS/Windows.
 */
final class DnsResolver
{
    private const DEFAULT_NAMESERVER = "8.8.8.8";
    private const DEFAULT_TIMEOUT = 3.0;
    private const DNS_PORT = 53;
    private const MAX_DGRAM_SIZE = 512;

    /** @var string|null Cached nameserver once detected */
    private static ?string $cachedNameserver = null;

    /** @var string|null Optional forced nameserver override */
    private static ?string $forcedNameserver = null;

    /** @var array<string, array<int, string>>|null Cached hosts file entries */
    private static ?array $hostsCache = null;

    /**
     * Override the nameserver used for lookups. Pass null to restore auto-detect.
     */
    public static function setNameserver(?string $nameserver): void
    {
        self::$forcedNameserver = $nameserver;
        self::$cachedNameserver = null;
    }

    /**
     * Resolve hostname to IP using async UDP DNS (A with AAAA fallback unless preferIpv6).
     *
     * @return Deferred<string>
     */
    public static function resolve(
        string $host,
        float $timeoutSeconds = self::DEFAULT_TIMEOUT,
        bool $preferIpv6 = false,
    ): Deferred {
        return new LazyDeferred(static function () use (
            $host,
            $timeoutSeconds,
            $preferIpv6,
        ): string {
            return self::doResolve($host, $timeoutSeconds, $preferIpv6);
        });
    }

    private static function doResolve(
        string $host,
        float $timeoutSeconds,
        bool $preferIpv6,
    ): string {
        // Strict localhost fast-path to avoid any DNS traffic (Windows CI hit NXDOMAIN)
        $normalizedHost = strtolower(rtrim($host, "."));

        if ($normalizedHost === "localhost") {
            return $preferIpv6 ? "::1" : "127.0.0.1";
        }

        // Shortcut: already an IP
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return $host;
        }

        // Hosts-file / localhost fast-path to avoid external DNS (notably on Windows)
        $hostsHit = self::resolveFromHosts($host, $preferIpv6);
        if ($hostsHit !== null) {
            return $hostsHit;
        }

        $nameserver = self::pickNameserver();
        $types = $preferIpv6 ? [28, 1] : [1, 28]; // A=1, AAAA=28
        $errors = [];

        foreach ($types as $type) {
            try {
                return self::query($host, $type, $nameserver, $timeoutSeconds);
            } catch (RuntimeException $e) {
                $errors[] = $e->getMessage();
            }
        }

        throw new RuntimeException(
            "DNS resolution failed for {$host}: " . implode("; ", $errors),
        );
    }

    private static function query(
        string $host,
        int $qtype,
        string $nameserver,
        float $timeoutSeconds,
    ): string {
        $id = random_int(0, 0xFFFF);
        $packet = self::buildQueryPacket($host, $qtype, $id);

        $socket = @stream_socket_client(
            "udp://{$nameserver}:" . self::DNS_PORT,
            $errno,
            $errstr,
            0,
            STREAM_CLIENT_CONNECT,
        );

        if ($socket === false) {
            throw new RuntimeException(
                "Cannot open UDP socket to DNS server {$nameserver}: {$errstr}",
            );
        }

        stream_set_blocking($socket, false);

        // Send request
        AsyncIO::streamWrite($socket, $packet, $timeoutSeconds)->await();

        // Await response
        $response = AsyncIO::streamRead(
            $socket,
            self::MAX_DGRAM_SIZE,
            $timeoutSeconds,
        )->await();

        fclose($socket);

        if ($response === "") {
            throw new RuntimeException("Empty DNS response from {$nameserver}");
        }

        return self::parseResponse($response, $id, $qtype);
    }

    private static function buildQueryPacket(
        string $host,
        int $qtype,
        int $id,
    ): string {
        $qname = self::encodeName($host);

        $header = pack(
            "nnnnnn",
            $id, // ID
            0x0100, // RD flag set
            1, // QDCOUNT
            0, // ANCOUNT
            0, // NSCOUNT
            0, // ARCOUNT
        );

        $question = $qname . pack("nn", $qtype, 1); // QTYPE, QCLASS=IN

        return $header . $question;
    }

    /**
     * @return string resolved IP address
     */
    private static function parseResponse(
        string $data,
        int $expectedId,
        int $wantedType,
    ): string {
        $len = strlen($data);
        if ($len < 12) {
            throw new RuntimeException("Malformed DNS response (too short)");
        }

        $header = unpack("nid/nflags/nqdcount/nancount/nnscount/narcount", $data);
        $id = $header["id"];
        $flags = $header["flags"];
        $qdcount = $header["qdcount"];
        $ancount = $header["ancount"];

        if ($id !== $expectedId) {
            throw new RuntimeException("DNS response ID mismatch");
        }

        // QR bit (response) must be set
        if (($flags & 0x8000) === 0) {
            throw new RuntimeException("DNS response missing QR flag");
        }

        $rcode = $flags & 0x000F;
        if ($rcode !== 0) {
            throw new RuntimeException("DNS server returned error code {$rcode}");
        }

        $offset = 12;

        // Skip questions
        for ($i = 0; $i < $qdcount; $i++) {
            [, $offset] = self::decodeName($data, $offset);
            $offset += 4; // QTYPE + QCLASS
            if ($offset > $len) {
                throw new RuntimeException("Malformed DNS response (question overrun)");
            }
        }

        // Parse answers
        for ($i = 0; $i < $ancount; $i++) {
            [, $offset] = self::decodeName($data, $offset);

            if ($offset + 10 > $len) {
                throw new RuntimeException("Malformed DNS response (answer header)");
            }

            $answer = unpack(
                "nqtype/nqclass/Nttl/nrdlength",
                substr($data, $offset, 10),
            );

            $offset += 10;
            $rdlength = $answer["rdlength"];

            if ($offset + $rdlength > $len) {
                throw new RuntimeException("Malformed DNS response (rdata overrun)");
            }

            $rdata = substr($data, $offset, $rdlength);
            $offset += $rdlength;

            if ($answer["qclass"] !== 1) {
                continue; // Only IN class
            }

            if ($answer["qtype"] === $wantedType) {
                if ($answer["qtype"] === 1 && $rdlength === 4) {
                    $ip = inet_ntop($rdata);
                } elseif ($answer["qtype"] === 28 && $rdlength === 16) {
                    $ip = inet_ntop($rdata);
                } else {
                    continue;
                }

                if ($ip === false) {
                    continue;
                }

                return $ip;
            }
        }

        throw new RuntimeException("No matching A/AAAA records in DNS response");
    }

    /**
     * Resolve from local hosts file (or builtin localhost) before DNS.
     *
     * @return string|null
     */
    private static function resolveFromHosts(string $host, bool $preferIpv6): ?string
    {
        $normalized = strtolower(rtrim($host, "."));

        // Cache hosts file once per process
        if (self::$hostsCache === null) {
            self::$hostsCache = self::loadHostsFile();
        }

        $records = self::$hostsCache[$normalized] ?? null;
        if ($records === null) {
            return null;
        }

        // Prefer requested IP family but allow fallback
        $pick = self::pickByFamilyPreference($records, $preferIpv6);
        return $pick;
    }

    /**
     * Load hosts entries into a lowercase map of hostname => [ip,...].
     */
    private static function loadHostsFile(): array
    {
        $paths = [];
        if (stripos(PHP_OS, "WIN") === 0) {
            $systemRoot = getenv("SystemRoot") ?: "C:\\Windows";
            $paths[] = $systemRoot . "\\System32\\drivers\\etc\\hosts";
        }
        $paths[] = "/etc/hosts";

        $stripBom = static function (string $line): string {
            if (str_starts_with($line, "\xEF\xBB\xBF")) {
                return substr($line, 3);
            }
            if (str_starts_with($line, "\xFF\xFE") || str_starts_with($line, "\xFE\xFF")) {
                // UTF-16 BOMs – best-effort strip; remaining nulls will be trimmed out below
                return substr($line, 2);
            }
            return $line;
        };

        $map = [];

        foreach ($paths as $path) {
            if (!is_readable($path)) {
                continue;
            }

            $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines === false) {
                continue;
            }

            foreach ($lines as $line) {
                $line = trim($stripBom($line));
                if ($line === "" || str_starts_with($line, "#")) {
                    continue;
                }

                // Split on whitespace; first token is IP, rest are hostnames/aliases
                $parts = preg_split('/\s+/', $line);
                if (!$parts || count($parts) < 2) {
                    continue;
                }

                $ip = array_shift($parts);
                if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
                    continue;
                }

                foreach ($parts as $h) {
                    $key = strtolower($h);
                    // Preserve first occurrence to honor file ordering
                    if (!isset($map[$key])) {
                        $map[$key] = [];
                    }
                    $map[$key][] = $ip;
                }
            }
        }

        // Fallback: ensure localhost resolves even if hosts file missing
        if (!isset($map['localhost'])) {
            $map['localhost'] = ['127.0.0.1', '::1'];
        }

        return $map;
    }

    /**
     * Pick IPv4/IPv6 according to preference and availability.
     *
     * @param array<int, string> $ips
     */
    private static function pickByFamilyPreference(array $ips, bool $preferIpv6): ?string
    {
        $primaryFlag = $preferIpv6 ? FILTER_FLAG_IPV6 : FILTER_FLAG_IPV4;
        $secondaryFlag = $preferIpv6 ? FILTER_FLAG_IPV4 : FILTER_FLAG_IPV6;

        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, $primaryFlag) !== false) {
                return $ip;
            }
        }

        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, $secondaryFlag) !== false) {
                return $ip;
            }
        }

        // Should not happen but keep safe default
        return $ips[0] ?? null;
    }

    /**
     * Encode hostname into DNS label format.
     */
    private static function encodeName(string $host): string
    {
        $labels = explode(".", trim($host, "."));

        $encoded = "";
        foreach ($labels as $label) {
            $length = strlen($label);
            if ($length === 0 || $length > 63) {
                throw new RuntimeException("Invalid DNS label in host: {$host}");
            }
            $encoded .= chr($length) . $label;
        }

        return $encoded . "\0";
    }

    /**
     * Decode a DNS name (handles compression). Returns [name, nextOffset].
     *
     * @return array{0: string, 1: int}
     */
    private static function decodeName(
        string $data,
        int $offset,
        int $depth = 0,
    ): array {
        $labels = [];
        $jumped = false;
        $initialOffset = $offset;
        $len = strlen($data);

        while (true) {
            if ($offset >= $len) {
                throw new RuntimeException("Malformed DNS response (name truncated)");
            }

            $lenByte = ord($data[$offset]);

            // Pointer
            if (($lenByte & 0xC0) === 0xC0) {
                if ($offset + 1 >= $len) {
                    throw new RuntimeException("Malformed DNS pointer");
                }
                $pointer = (($lenByte & 0x3F) << 8) | ord($data[$offset + 1]);
                if ($depth > 10) {
                    throw new RuntimeException("DNS pointer recursion too deep");
                }
                [$suffix] = self::decodeName($data, $pointer, $depth + 1);
                $labels[] = $suffix;
                $offset += 2;
                $jumped = true;
                break;
            }

            $offset++;
            if ($lenByte === 0) {
                break;
            }

            if ($offset + $lenByte > $len) {
                throw new RuntimeException("Malformed DNS label length");
            }

            $labels[] = substr($data, $offset, $lenByte);
            $offset += $lenByte;
        }

        $name = implode(".", $labels);
        return [$name, $jumped ? $offset : $offset];
    }

    private static function pickNameserver(): string
    {
        if (self::$forcedNameserver !== null) {
            return self::$forcedNameserver;
        }

        if (self::$cachedNameserver !== null) {
            return self::$cachedNameserver;
        }

        $detected = self::detectNameserver();
        self::$cachedNameserver = $detected;
        return $detected;
    }

    private static function detectNameserver(): string
    {
        $resolv = "/etc/resolv.conf";

        if (stripos(PHP_OS, "WIN") === 0 || !is_readable($resolv)) {
            return self::DEFAULT_NAMESERVER;
        }

        $lines = @file($resolv, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return self::DEFAULT_NAMESERVER;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if (str_starts_with($line, "#") || str_starts_with($line, ";")) {
                continue;
            }
            if (str_starts_with($line, "nameserver")) {
                $parts = preg_split('/\s+/', $line);
                if (isset($parts[1]) && filter_var($parts[1], FILTER_VALIDATE_IP)) {
                    return $parts[1];
                }
            }
        }

        return self::DEFAULT_NAMESERVER;
    }
}
