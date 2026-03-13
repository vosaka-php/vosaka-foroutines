# Reference: Flow Operators

`Flow` is a cold asynchronous stream that emits values sequentially. Operators allow you to transform and control the stream.

## Creation Operators
- `Flow::of(...$values)`: Create from a list of arguments.
- `Flow::fromArray(array $array)`: Create from an array.
- `Flow::new(callable $block)`: Manually emit values using a collector.

## Transformation Operators
- `map(callable $transform)`: Transform each emitted value.
- `filter(callable $predicate)`: Only emit values that pass the test.
- `take(int $count)`: Emit only the first N values.
- `zip(Flow $other)`: Combine values from two flows into pairs.

## Flow Control
- `buffer(int $capacity, string $onOverflow)`: Add a buffer to allow the producer to run ahead of the consumer.
- `backpressure(string $strategy)`: Define what happens when the buffer is full (`SUSPEND`, `DROP_OLDEST`, `DROP_LATEST`, `ERROR`).

## Terminal Operators
- `collect(callable $collector)`: Start the flow and process each value.
- `toList()`: Collect all values into a single array.
- `first()`: Get only the first value.
