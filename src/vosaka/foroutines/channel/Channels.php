<?php

declare(strict_types=1);

namespace vosaka\foroutines\channel;

use vosaka\foroutines\Launch;

final class Channels
{
    public static function new(): Channel
    {
        return new Channel(0);
    }

    public static function createBuffered(int $capacity): Channel
    {
        return new Channel($capacity);
    }

    public static function from(array $items): Channel
    {
        $channel = new Channel(count($items));
        foreach ($items as $item) {
            $channel->send($item);
        }
        return $channel;
    }

    public static function merge(Channel ...$channels): Channel
    {
        $output = new Channel();

        foreach ($channels as $channel) {
            Launch::new(function () use ($channel, $output) {
                while (true) {
                    $value = $channel->receive();
                    $output->send($value);
                }
            });
        }

        return $output;
    }

    public static function map(Channel $input, callable $transform): Channel
    {
        $output = new Channel();

        Launch::new(function () use ($input, $output, $transform) {
            while (true) {
                $value = $input->receive();
                $transformed = $transform($value);
                $output->send($transformed);
            }
        });

        return $output;
    }

    public static function filter(Channel $input, callable $predicate): Channel
    {
        $output = new Channel();

        Launch::new(function () use ($input, $output, $predicate) {
            while (true) {
                $value = $input->receive();
                if ($predicate($value)) {
                    $output->send($value);
                }
            }

            $output->close();
        });

        return $output;
    }

    public static function take(Channel $input, int $count): Channel
    {
        $output = new Channel();

        Launch::new(function () use ($input, $output, $count) {
            for ($i = 0; $i < $count; $i++) {
                $value = $input->receive();
                $output->send($value);
            }
            $output->close();
        });

        return $output;
    }
}
