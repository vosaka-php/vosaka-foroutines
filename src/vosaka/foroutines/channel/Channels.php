<?php

declare(strict_types=1);

namespace vosaka\foroutines\channel;

use vosaka\foroutines\Launch;
use Exception;

final class Channels
{
    public static function new(): Channel
    {
        return new Channel(0);
    }

    public static function createBuffered(int $capacity): Channel
    {
        if ($capacity <= 0) {
            throw new Exception("Buffered channel capacity must be greater than 0");
        }
        return new Channel($capacity);
    }

    /**
     * Create inter-process channel (for the first process that creates the channel)
     */
    public static function createInterProcess(
        string $channelName,
        int $capacity = 0,
        string $serializer = Channel::SERIALIZER_SERIALIZE,
        ?string $tempDir = null,
        bool $preserveObjectTypes = true
    ): Channel {
        if (empty($channelName)) {
            throw new Exception("Channel name cannot be empty");
        }

        return Channel::newInterProcess($channelName, $capacity, $serializer, $tempDir, $preserveObjectTypes);
    }

    /**
     * Connect to existing inter-process channel (for other processes)
     */
    public static function connect(
        string $channelName,
        string $serializer = Channel::SERIALIZER_SERIALIZE,
        ?string $tempDir = null,
        bool $preserveObjectTypes = true
    ): Channel {
        if (empty($channelName)) {
            throw new Exception("Channel name cannot be empty");
        }

        return Channel::connect($channelName, $serializer, $tempDir, $preserveObjectTypes);
    }

    /**
     * Check if inter-process channel exists
     */
    public static function exists(string $channelName, ?string $tempDir = null): bool
    {
        if (empty($channelName)) {
            return false;
        }

        $tempDir = $tempDir ?: sys_get_temp_dir();
        $channelFile = $tempDir . DIRECTORY_SEPARATOR .
            'channel_' . md5($channelName) . '.dat';
        return file_exists($channelFile);
    }

    /**
     * Remove inter-process channel
     */
    public static function remove(string $channelName, ?string $tempDir = null): bool
    {
        if (empty($channelName)) {
            return false;
        }

        $tempDir = $tempDir ?: sys_get_temp_dir();
        $channelFile = $tempDir . DIRECTORY_SEPARATOR .
            'channel_' . md5($channelName) . '.dat';

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

        $pattern = $tempDir . DIRECTORY_SEPARATOR . 'channel_*.dat';
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
                        'file' => basename($file),
                        'name' => $state['name'] ?? 'unknown',
                        'capacity' => $state['capacity'] ?? 0,
                        'buffer_size' => count($state['buffer'] ?? []),
                        'closed' => $state['closed'] ?? false,
                        'created_by' => $state['created_by'] ?? null,
                        'last_updated' => $state['timestamp'] ?? null,
                        'serializer' => $state['serializer'] ?? 'serialize',
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
        ?string $tempDir = null
    ): Channel {
        if (empty($channelName)) {
            throw new Exception("Channel name cannot be empty");
        }

        $channel = self::createInterProcess($channelName, count($items), $serializer, $tempDir);
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
        Channel ...$channels
    ): Channel {
        if (empty($outputChannelName)) {
            throw new Exception("Output channel name cannot be empty");
        }

        if (empty($channels)) {
            throw new Exception("Cannot merge empty channel list");
        }

        $output = self::createInterProcess($outputChannelName, 0, $serializer, $tempDir);

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
        ?string $tempDir = null
    ): Channel {
        if (empty($outputChannelName)) {
            throw new Exception("Output channel name cannot be empty");
        }

        $output = self::createInterProcess($outputChannelName, 0, $serializer, $tempDir);

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
        ?string $tempDir = null
    ): Channel {
        if (empty($outputChannelName)) {
            throw new Exception("Output channel name cannot be empty");
        }

        $output = self::createInterProcess($outputChannelName, 0, $serializer, $tempDir);

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
        ?string $tempDir = null
    ): Channel {
        if ($count < 0) {
            throw new Exception("Count cannot be negative");
        }

        if (empty($outputChannelName)) {
            throw new Exception("Output channel name cannot be empty");
        }

        $output = self::createInterProcess($outputChannelName, 0, $serializer, $tempDir);

        if ($count === 0) {
            $output->close();
            return $output;
        }

        Launch::new(function () use ($input, $output, $count) {
            try {
                $taken = 0;
                while ($taken < $count && (!$input->isClosed() || !$input->isEmpty())) {
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
        $testChannel = new Channel(0, true, 'test_info_' . uniqid());
        $info = $testChannel->getInfo();
        $testChannel->cleanup(); // Clean up test channel
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
            throw new Exception("Start cannot be greater than end with positive step");
        }

        if ($step < 0 && $start < $end) {
            throw new Exception("Start cannot be less than end with negative step");
        }

        $channel = new Channel();

        Launch::new(function () use ($channel, $start, $end, $step) {
            try {
                for ($i = $start; $step > 0 ? $i <= $end : $i >= $end; $i += $step) {
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
