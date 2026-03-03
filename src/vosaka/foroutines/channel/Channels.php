<?php

declare(strict_types=1);

namespace vosaka\foroutines\channel;

use vosaka\foroutines\Launch;
use Exception;

final class Channels
{
    /**
     * Create a new inter-process channel.
     *
     * This is the **primary, simplified** factory method. By default it
     * uses a shared ChannelBrokerPool process — all channels created via
     * this method share a single background process instead of spawning
     * one per channel. The pool is lazily booted on the first call.
     *
     * If pool mode has been disabled via Channel::disablePool(), a
     * dedicated per-channel broker process is spawned instead.
     *
     * Usage:
     *   $ch = Channels::create(5);   // buffered, capacity 5
     *   $ch = Channels::create();    // unbounded (no capacity limit)
     *
     * In a child process (Dispatchers::IO / pcntl_fork), reconnect with:
     *   $ch->connect();
     *
     * The Channel is also serializable — when captured inside a closure
     * that is sent to a worker process (SerializableClosure), it will
     * automatically reconnect on unserialize.
     *
     * @param int   $capacity     Maximum buffer size (0 = unbounded / no limit).
     * @param float $readTimeout  Read timeout for blocking operations (seconds).
     * @param float $idleTimeout  Broker idle timeout (seconds, 0 = no timeout).
     * @return Channel A connected Channel that owns the broker.
     * @throws Exception If the broker cannot be started.
     */
    public static function create(
        int $capacity = 0,
        float $readTimeout = 30.0,
        float $idleTimeout = 300.0,
    ): Channel {
        return Channel::create($capacity, $readTimeout, $idleTimeout);
    }

    public static function new(): Channel
    {
        return new Channel(0);
    }

    public static function createBuffered(int $capacity): Channel
    {
        if ($capacity <= 0) {
            throw new Exception(
                "Buffered channel capacity must be greater than 0",
            );
        }
        return new Channel($capacity);
    }

    /**
     * Create inter-process channel (file-based transport — original implementation).
     *
     * Uses file_get_contents / file_put_contents + Mutex for synchronization.
     * For better performance, consider using create() instead.
     */
    public static function createInterProcess(
        string $channelName,
        int $capacity = 0,
        string $serializer = Channel::SERIALIZER_SERIALIZE,
        ?string $tempDir = null,
        bool $preserveObjectTypes = true,
    ): Channel {
        if (empty($channelName)) {
            throw new Exception("Channel name cannot be empty");
        }

        return Channel::newInterProcess(
            $channelName,
            $capacity,
            $serializer,
            $tempDir,
            $preserveObjectTypes,
        );
    }

    /**
     * Create inter-process channel using socket transport (with explicit name).
     *
     * Spawns a ChannelBroker background process that manages the channel
     * buffer entirely in-memory. Communication goes over TCP loopback
     * sockets instead of file I/O, providing:
     *
     *   - No file_get_contents / file_put_contents overhead
     *   - No full-buffer serialize/unserialize on every operation
     *   - Event-driven receive (no spin-wait polling)
     *   - No file lock contention
     *   - Works correctly on Windows (TCP sockets have proper non-blocking I/O)
     *
     * The returned Channel is the **owner** — it will shut down the broker
     * when destroyed. Other processes should use connectSocket() to obtain
     * a non-owner handle.
     *
     * Prefer Channels::create() for a simpler API that auto-generates the name.
     *
     * @param string $channelName  Unique channel identifier.
     * @param int    $capacity     Maximum buffer size (0 = unbounded).
     * @param float  $readTimeout  Read timeout for blocking operations (seconds).
     * @param float  $idleTimeout  Broker idle timeout (seconds, 0 = no timeout).
     * @return Channel A connected Channel that owns the broker.
     * @throws Exception If the broker cannot be started.
     */
    public static function createSocketInterProcess(
        string $channelName,
        int $capacity = 0,
        float $readTimeout = 30.0,
        float $idleTimeout = 300.0,
    ): Channel {
        if (empty($channelName)) {
            throw new Exception("Channel name cannot be empty");
        }

        return Channel::newSocketInterProcess(
            $channelName,
            $capacity,
            $readTimeout,
            $idleTimeout,
        );
    }

    // ═════════════════════════════════════════════════════════════════
    //  Pool mode — one background process for many channels
    // ═════════════════════════════════════════════════════════════════

    /**
     * Enable pool mode globally (idempotent).
     *
     * Pool mode is **enabled by default** — calling this is only needed
     * after a previous disablePool() call to re-enable it, or to eagerly
     * boot the pool process before the first Channel::create().
     *
     * The pool process is lazily spawned on the first channel creation.
     *
     *     // Pool is already enabled by default, so this is optional:
     *     Channels::enablePool();
     *
     *     $ch1 = Channels::create(5);   // auto-boots pool, uses it
     *     $ch2 = Channels::create(10);  // same pool process
     *     $ch3 = Channels::create();    // same pool process
     *
     * @param float $readTimeout  Read timeout for pool operations (seconds).
     * @param float $idleTimeout  Pool idle timeout (seconds, 0 = no timeout).
     */
    public static function enablePool(
        float $readTimeout = 30.0,
        float $idleTimeout = 300.0,
    ): void {
        Channel::enablePool($readTimeout, $idleTimeout);
    }

    /**
     * Disable pool mode globally.
     *
     * After calling this, new Channels::create() / Channel::create()
     * calls will spawn a dedicated broker process per channel (the
     * original behavior). Already-created pool channels continue to
     * work until the pool is shut down via shutdownPool().
     */
    public static function disablePool(): void
    {
        Channel::disablePool();
    }

    /**
     * Check if pool mode is enabled globally.
     */
    public static function isPoolEnabled(): bool
    {
        return Channel::isPoolEnabled();
    }

    /**
     * Get the pool port (if a pool is running).
     */
    public static function getPoolPort(): ?int
    {
        return Channel::getPoolPort();
    }

    /**
     * Shut down the pool process and clean up all pool-hosted channels.
     *
     * Pool mode remains enabled after this call — the next
     * Channels::create() / Channel::create() will lazily boot a fresh
     * pool process. To also disable pool mode, call disablePool()
     * afterwards.
     */
    public static function shutdownPool(): void
    {
        Channel::shutdownPool();
    }

    /**
     * Create a channel inside the shared pool process.
     *
     * If the pool is not yet running, it is lazily booted.
     * Unlike create(), this always uses the pool regardless of whether
     * enablePool() was called.
     *
     *     $ch = Channels::createPooled(5);           // buffered, capacity 5
     *     $ch = Channels::createPooled();             // unbounded
     *     $ch = Channels::createPooled(0, "my_ch");   // with explicit name
     *
     * In a child process (Dispatchers::IO / pcntl_fork), reconnect with:
     *     $ch->connect();
     *
     * @param int    $capacity     Maximum buffer size (0 = unbounded).
     * @param string $channelName  Optional channel name (auto-generated if empty).
     * @param float  $readTimeout  Read timeout for blocking operations (seconds).
     * @return Channel A channel backed by the shared pool process.
     * @throws Exception If the pool cannot be started or the channel cannot be created.
     */
    public static function createPooled(
        int $capacity = 0,
        string $channelName = "",
        float $readTimeout = 30.0,
    ): Channel {
        return Channel::createPooled($channelName, $capacity, $readTimeout);
    }

    /**
     * Connect to existing file-based inter-process channel (for other processes).
     *
     * For socket-based channels, use connectSocket() or $chan->connect() instead.
     */
    public static function connect(
        string $channelName,
        string $serializer = Channel::SERIALIZER_SERIALIZE,
        ?string $tempDir = null,
        bool $preserveObjectTypes = true,
    ): Channel {
        if (empty($channelName)) {
            throw new Exception("Channel name cannot be empty");
        }

        return Channel::connectByName(
            $channelName,
            $serializer,
            $tempDir,
            $preserveObjectTypes,
        );
    }

    /**
     * Connect to an existing socket-based inter-process channel via port file.
     *
     * @deprecated The broker no longer writes a port file. Use
     *             Channel::create() + $chan->connect() instead, or
     *             Channel::connectSocketByPort() if you know the port.
     *
     * Discovers the broker's port by reading the port file and opens a
     * TCP connection. The returned Channel is NOT the owner — it will
     * not shut down the broker on destruction.
     *
     * @param string      $channelName    Unique channel identifier.
     * @param float       $readTimeout    Read timeout for blocking operations (seconds).
     * @param string|null $tempDir        Temp directory for port file discovery.
     * @param float       $connectTimeout Max seconds to wait for broker.
     * @return Channel A connected Channel (not the owner).
     * @throws Exception If the broker cannot be found.
     */
    public static function connectSocket(
        string $channelName,
        float $readTimeout = 30.0,
        ?string $tempDir = null,
        float $connectTimeout = 10.0,
    ): Channel {
        if (empty($channelName)) {
            throw new Exception("Channel name cannot be empty");
        }

        return Channel::connectSocket(
            $channelName,
            $readTimeout,
            $tempDir,
            $connectTimeout,
        );
    }

    /**
     * Check if a file-based inter-process channel exists.
     */
    public static function exists(
        string $channelName,
        ?string $tempDir = null,
    ): bool {
        if (empty($channelName)) {
            return false;
        }

        $tempDir = $tempDir ?: sys_get_temp_dir();
        $channelFile =
            $tempDir .
            DIRECTORY_SEPARATOR .
            "channel_" .
            md5($channelName) .
            ".dat";
        return file_exists($channelFile);
    }

    /**
     * Check if a socket-based inter-process channel broker is running.
     *
     * @deprecated The broker no longer writes a port file, so this method
     *             will always return false for channels created with the
     *             new API (Channel::create / Channels::create). It only
     *             works for legacy channels that still write port files.
     *
     * @param string      $channelName Channel identifier.
     * @param string|null $tempDir     Temp directory (default: sys_get_temp_dir()).
     * @return bool True if the broker's port file exists.
     */
    public static function existsSocket(
        string $channelName,
        ?string $tempDir = null,
    ): bool {
        if (empty($channelName)) {
            return false;
        }

        $tempDir = $tempDir ?: sys_get_temp_dir();
        $portFile =
            $tempDir .
            DIRECTORY_SEPARATOR .
            "channel_broker_" .
            md5($channelName) .
            ".port";
        return file_exists($portFile);
    }

    /**
     * Remove inter-process channel
     */
    public static function remove(
        string $channelName,
        ?string $tempDir = null,
    ): bool {
        if (empty($channelName)) {
            return false;
        }

        $tempDir = $tempDir ?: sys_get_temp_dir();
        $channelFile =
            $tempDir .
            DIRECTORY_SEPARATOR .
            "channel_" .
            md5($channelName) .
            ".dat";

        if (file_exists($channelFile)) {
            return @unlink($channelFile);
        }
        return true;
    }

    /**
     * List all existing inter-process channels
     */
    public static function listChannels(?string $tempDir = null): array
    {
        $tempDir = $tempDir ?: sys_get_temp_dir();
        $channels = [];

        $pattern = $tempDir . DIRECTORY_SEPARATOR . "channel_*.dat";
        $files = glob($pattern);

        if ($files === false) {
            return [];
        }

        foreach ($files as $file) {
            $content = @file_get_contents($file);
            if ($content === false) {
                continue;
            }

            try {
                $state = unserialize($content);
                if (is_array($state)) {
                    $channels[] = [
                        "file" => basename($file),
                        "name" => $state["name"] ?? "unknown",
                        "capacity" => $state["capacity"] ?? 0,
                        "buffer_size" => count($state["buffer"] ?? []),
                        "closed" => $state["closed"] ?? false,
                        "created_by" => $state["created_by"] ?? null,
                        "last_updated" => $state["timestamp"] ?? null,
                        "serializer" => $state["serializer"] ?? "serialize",
                    ];
                }
            } catch (Exception) {
                // Skip invalid files
            }
        }

        return $channels;
    }

    public static function from(array $items): Channel
    {
        $channel = new Channel(count($items));
        foreach ($items as $item) {
            $channel->send($item);
        }
        return $channel;
    }

    /**
     * Create inter-process channel from array
     */
    public static function fromInterProcess(
        string $channelName,
        array $items,
        string $serializer = Channel::SERIALIZER_SERIALIZE,
        ?string $tempDir = null,
    ): Channel {
        if (empty($channelName)) {
            throw new Exception("Channel name cannot be empty");
        }

        $channel = self::createInterProcess(
            $channelName,
            count($items),
            $serializer,
            $tempDir,
        );
        foreach ($items as $item) {
            if (!$channel->trySend($item)) {
                throw new Exception("Failed to send item to channel");
            }
        }
        return $channel;
    }

    public static function merge(Channel ...$channels): Channel
    {
        if (empty($channels)) {
            throw new Exception("Cannot merge empty channel list");
        }

        $output = new Channel();

        foreach ($channels as $channel) {
            Launch::new(function () use ($channel, $output) {
                try {
                    foreach ($channel as $value) {
                        $output->send($value);
                    }
                } catch (Exception) {
                    // Channel closed or error - continue with other channels
                } finally {
                    // Check if all input channels are closed
                    $allClosed = true;
                    foreach (func_get_args() as $ch) {
                        if (!$ch->isClosed() || !$ch->isEmpty()) {
                            $allClosed = false;
                            break;
                        }
                    }

                    if ($allClosed) {
                        $output->close();
                    }
                }
            });
        }

        return $output;
    }

    /**
     * Merge inter-process channels
     */
    public static function mergeInterProcess(
        string $outputChannelName,
        string $serializer = Channel::SERIALIZER_SERIALIZE,
        ?string $tempDir = null,
        Channel ...$channels,
    ): Channel {
        if (empty($outputChannelName)) {
            throw new Exception("Output channel name cannot be empty");
        }

        if (empty($channels)) {
            throw new Exception("Cannot merge empty channel list");
        }

        $output = self::createInterProcess(
            $outputChannelName,
            0,
            $serializer,
            $tempDir,
        );

        foreach ($channels as $channel) {
            Launch::new(function () use ($channel, $output) {
                try {
                    while (!$channel->isClosed() || !$channel->isEmpty()) {
                        $value = $channel->tryReceive();
                        if ($value !== null) {
                            if (!$output->trySend($value)) {
                                usleep(1000); // Wait and retry
                                $output->send($value); // Use blocking send
                            }
                        } else {
                            usleep(1000); // 1ms wait
                        }
                    }
                } catch (Exception) {
                    // Channel closed or error
                }
            });
        }

        return $output;
    }

    public static function map(Channel $input, callable $transform): Channel
    {
        $output = new Channel();

        Launch::new(function () use ($input, $output, $transform) {
            try {
                foreach ($input as $value) {
                    $transformed = $transform($value);
                    $output->send($transformed);
                }
            } catch (Exception) {
                // Input channel closed or error
            } finally {
                $output->close();
            }
        });

        return $output;
    }

    /**
     * Map inter-process channel
     */
    public static function mapInterProcess(
        Channel $input,
        callable $transform,
        string $outputChannelName,
        string $serializer = Channel::SERIALIZER_SERIALIZE,
        ?string $tempDir = null,
    ): Channel {
        if (empty($outputChannelName)) {
            throw new Exception("Output channel name cannot be empty");
        }

        $output = self::createInterProcess(
            $outputChannelName,
            0,
            $serializer,
            $tempDir,
        );

        Launch::new(function () use ($input, $output, $transform) {
            try {
                while (!$input->isClosed() || !$input->isEmpty()) {
                    $value = $input->tryReceive();
                    if ($value !== null) {
                        $transformed = $transform($value);
                        if (!$output->trySend($transformed)) {
                            usleep(1000);
                            $output->send($transformed);
                        }
                    } else {
                        usleep(1000); // 1ms wait
                    }
                }
            } catch (Exception) {
                // Handle error
            } finally {
                $output->close();
            }
        });

        return $output;
    }

    public static function filter(Channel $input, callable $predicate): Channel
    {
        $output = new Channel();

        Launch::new(function () use ($input, $output, $predicate) {
            try {
                foreach ($input as $value) {
                    if ($predicate($value)) {
                        $output->send($value);
                    }
                }
            } catch (Exception) {
                // Input channel closed or error
            } finally {
                $output->close();
            }
        });

        return $output;
    }

    /**
     * Filter inter-process channel
     */
    public static function filterInterProcess(
        Channel $input,
        callable $predicate,
        string $outputChannelName,
        string $serializer = Channel::SERIALIZER_SERIALIZE,
        ?string $tempDir = null,
    ): Channel {
        if (empty($outputChannelName)) {
            throw new Exception("Output channel name cannot be empty");
        }

        $output = self::createInterProcess(
            $outputChannelName,
            0,
            $serializer,
            $tempDir,
        );

        Launch::new(function () use ($input, $output, $predicate) {
            try {
                while (!$input->isClosed() || !$input->isEmpty()) {
                    $value = $input->tryReceive();
                    if ($value !== null && $predicate($value)) {
                        if (!$output->trySend($value)) {
                            usleep(1000);
                            $output->send($value);
                        }
                    } elseif ($value === null) {
                        usleep(1000); // 1ms wait
                    }
                }
            } catch (Exception) {
                // Handle error
            } finally {
                $output->close();
            }
        });

        return $output;
    }

    public static function take(Channel $input, int $count): Channel
    {
        if ($count < 0) {
            throw new Exception("Count cannot be negative");
        }

        if ($count === 0) {
            $output = new Channel();
            $output->close();
            return $output;
        }

        $output = new Channel();

        Launch::new(function () use ($input, $output, $count) {
            try {
                $taken = 0;
                foreach ($input as $value) {
                    if ($taken >= $count) {
                        break;
                    }
                    $output->send($value);
                    $taken++;
                }
            } catch (Exception) {
                // Handle error
            } finally {
                $output->close();
            }
        });

        return $output;
    }

    /**
     * Take from inter-process channel
     */
    public static function takeInterProcess(
        Channel $input,
        int $count,
        string $outputChannelName,
        string $serializer = Channel::SERIALIZER_SERIALIZE,
        ?string $tempDir = null,
    ): Channel {
        if ($count < 0) {
            throw new Exception("Count cannot be negative");
        }

        if (empty($outputChannelName)) {
            throw new Exception("Output channel name cannot be empty");
        }

        $output = self::createInterProcess(
            $outputChannelName,
            0,
            $serializer,
            $tempDir,
        );

        if ($count === 0) {
            $output->close();
            return $output;
        }

        Launch::new(function () use ($input, $output, $count) {
            try {
                $taken = 0;
                while (
                    $taken < $count &&
                    (!$input->isClosed() || !$input->isEmpty())
                ) {
                    $value = $input->tryReceive();
                    if ($value !== null) {
                        if (!$output->trySend($value)) {
                            usleep(1000);
                            $output->send($value);
                        }
                        $taken++;
                    } else {
                        usleep(1000); // 1ms wait
                    }
                }
            } catch (Exception) {
                // Handle error
            } finally {
                $output->close();
            }
        });

        return $output;
    }

    /**
     * Get platform information for channels
     */
    public static function getPlatformInfo(): array
    {
        $testChannel = new Channel(0, true, "test_info_" . uniqid());
        $info = $testChannel->getInfo();
        // No manual cleanup() call here — __destruct() handles it.
        // Calling cleanup() manually caused double shm_detach() because
        // the destructor (and the shutdown function registered by
        // initInterProcessSupport) would call cleanup() again on the
        // already-destroyed shared memory block, triggering a fatal error.
        return $info;
    }

    /**
     * Create a range channel that sends numbers from start to end
     */
    public static function range(int $start, int $end, int $step = 1): Channel
    {
        if ($step === 0) {
            throw new Exception("Step cannot be zero");
        }

        if ($step > 0 && $start > $end) {
            throw new Exception(
                "Start cannot be greater than end with positive step",
            );
        }

        if ($step < 0 && $start < $end) {
            throw new Exception(
                "Start cannot be less than end with negative step",
            );
        }

        $channel = new Channel();

        Launch::new(function () use ($channel, $start, $end, $step) {
            try {
                for (
                    $i = $start;
                    $step > 0 ? $i <= $end : $i >= $end;
                    $i += $step
                ) {
                    $channel->send($i);
                }
            } catch (Exception) {
                // Handle error
            } finally {
                $channel->close();
            }
        });

        return $channel;
    }

    /**
     * Create a timer channel that sends the current time at intervals
     */
    public static function timer(int $intervalMs, int $maxTicks = -1): Channel
    {
        if ($intervalMs <= 0) {
            throw new Exception("Interval must be positive");
        }

        $channel = new Channel();

        Launch::new(function () use ($channel, $intervalMs, $maxTicks) {
            try {
                $ticks = 0;
                while ($maxTicks < 0 || $ticks < $maxTicks) {
                    $channel->send(microtime(true));
                    $ticks++;
                    usleep($intervalMs * 1000);
                }
            } catch (Exception) {
                // Handle error
            } finally {
                $channel->close();
            }
        });

        return $channel;
    }

    /**
     * Zip multiple channels together
     */
    public static function zip(Channel ...$channels): Channel
    {
        if (empty($channels)) {
            throw new Exception("Cannot zip empty channel list");
        }

        $output = new Channel();

        Launch::new(function () use ($channels, $output) {
            try {
                while (true) {
                    $values = [];
                    $allClosed = true;

                    foreach ($channels as $channel) {
                        if (!$channel->isClosed() || !$channel->isEmpty()) {
                            $allClosed = false;
                            $value = $channel->tryReceive();
                            if ($value !== null) {
                                $values[] = $value;
                            } else {
                                // No value available, break and try again
                                break 2;
                            }
                        } else {
                            // Channel is closed and empty
                            break 2;
                        }
                    }

                    if ($allClosed) {
                        break;
                    }

                    if (count($values) === count($channels)) {
                        $output->send($values);
                    }

                    usleep(1000); // Small delay to prevent busy waiting
                }
            } catch (Exception) {
                // Handle error
            } finally {
                $output->close();
            }
        });

        return $output;
    }
}
