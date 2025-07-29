const { performance } = require('perf_hooks');

// Simulate HTTP request
async function httpRequest(id) {
    // Simulate network delay
    const delay = Math.floor(Math.random() * 400) + 100; // 100-500ms
    await new Promise(resolve => setTimeout(resolve, delay));

    // Simulate some CPU work
    let result = 0;
    for (let i = 0; i < 1000000; i++) {
        result += Math.sqrt(i);
    }

    return {
        id: id,
        result: result,
        timestamp: Date.now() / 1000
    };
}

// Simulate database operation
async function dbQuery(id) {
    // Simulate DB query delay
    const delay = Math.floor(Math.random() * 150) + 50; // 50-200ms
    await new Promise(resolve => setTimeout(resolve, delay));

    // Simulate data processing
    const data = [];
    for (let i = 0; i < 10000; i++) {
        data.push(`user_${id}_record_${i}`);
    }

    return {
        id: id,
        records: data.length,
        sample: data.slice(0, 3)
    };
}

async function main() {
    console.log("Starting Node.js Concurrent Benchmark...");

    // Record start metrics
    const startTime = performance.now();
    const startMemory = process.memoryUsage();

    const httpPromises = [];
    const dbPromises = [];

    // Launch 100 concurrent HTTP requests
    for (let i = 0; i < 100; i++) {
        httpPromises.push(httpRequest(i));
    }

    // Launch 50 concurrent DB queries
    for (let i = 0; i < 50; i++) {
        dbPromises.push(dbQuery(i));
    }

    console.log("Launched 150 concurrent tasks");

    // Wait for all tasks to complete
    const [httpResults, dbResults] = await Promise.all([
        Promise.all(httpPromises),
        Promise.all(dbPromises)
    ]);

    // Print first 3 results of each type
    for (let i = 0; i < Math.min(3, httpResults.length); i++) {
        const result = httpResults[i];
        console.log(`HTTP ${result.id} completed: ${result.result.toFixed(2)}`);
    }

    for (let i = 0; i < Math.min(3, dbResults.length); i++) {
        const result = dbResults[i];
        console.log(`DB ${result.id} completed: ${result.records} records`);
    }

    // Record end metrics
    const endTime = performance.now();
    const endMemory = process.memoryUsage();

    console.log("\n=== Node.js Results ===");
    console.log(`Total execution time: ${((endTime - startTime) / 1000).toFixed(3)} seconds`);
    console.log(`Memory used: ${((endMemory.heapUsed - startMemory.heapUsed) / 1024 / 1024).toFixed(2)} MB`);
    console.log(`Peak memory: ${(endMemory.heapUsed / 1024 / 1024).toFixed(2)} MB`);
    console.log(`HTTP requests completed: ${httpResults.length}`);
    console.log(`DB queries completed: ${dbResults.length}`);
}

// Run the benchmark
main().catch(console.error);
