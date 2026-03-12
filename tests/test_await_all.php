<?php

/**
 * Test: Async::awaitAll() — concurrent awaiting of multiple Async instances
 *
 * Demonstrates that awaitAll() drives all tasks forward simultaneously
 * rather than sequentially. Three tasks with different delays should
 * complete in roughly the time of the longest one (~800ms), not the
 * sum of all delays (500+800+300 = 1600ms).
 *
 * Expected output:
 *   "Starting awaitAll test..."
 *   "Result A: 42"
 *   "Result B: hello world"
 *   "Result C: 300"
 *   "Sum of A + C: 342"
 *   "All results collected via awaitAll"
 *   "Total time: ~800ms (not ~1600ms)"
 *   "awaitAll test completed"
 */

require "../vendor/autoload.php";

use vosaka\foroutines\Async;
use vosaka\foroutines\Delay;
use vosaka\foroutines\RunBlocking;

use function vosaka\foroutines\main;

main(function () {
	RunBlocking::new(function () {
		var_dump("Starting awaitAll test...");

		$start = hrtime(true);

		// Async that returns an integer after a short delay
		$asyncA = Async::new(function () {
			Delay::new(500);
			return 42;
		});

		// Async that returns a string after a longer delay
		$asyncB = Async::new(function () {
			Delay::new(800);
			return "hello world";
		});

		// Async that does a computation with a short delay
		$asyncC = Async::new(function () {
			Delay::new(300);
			$sum = 0;
			for ($i = 1; $i <= 24; $i++) {
				$sum += $i;
			}
			return $sum; // 300
		});

		// Await all concurrently — total time should be ~800ms (the longest)
		[$resultA, $resultB, $resultC] = Async::awaitAll($asyncA, $asyncB, $asyncC);

		$elapsed = (hrtime(true) - $start) / 1_000_000; // ms

		var_dump("Result A: $resultA");
		var_dump("Result B: $resultB");
		var_dump("Result C: $resultC");
		var_dump("Sum of A + C: " . ($resultA + $resultC));
		var_dump("All results collected via awaitAll");
		var_dump(sprintf("Total time: %.0fms (should be ~800ms, not ~1600ms)", $elapsed));

		// Verify correctness
		assert($resultA === 42, "Expected resultA to be 42");
		assert($resultB === "hello world", "Expected resultB to be 'hello world'");
		assert($resultC === 300, "Expected resultC to be 300");

		// Verify concurrency — should complete in under 1200ms
		// (800ms longest task + some overhead), not 1600ms (sequential sum)
		assert($elapsed < 1200, "Expected concurrent execution (< 1200ms), got {$elapsed}ms");
	});

	var_dump("awaitAll test completed");
});
