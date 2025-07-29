package main

import (
	"fmt"
	"math"
	"math/rand"
	"runtime"
	"sync"
	"time"
)

// Result structures
type HTTPResult struct {
	ID        int     `json:"id"`
	Result    float64 `json:"result"`
	Timestamp float64 `json:"timestamp"`
}

type DBResult struct {
	ID      int      `json:"id"`
	Records int      `json:"records"`
	Sample  []string `json:"sample"`
}

// Simulate HTTP request
func httpRequest(id int, wg *sync.WaitGroup, results chan<- HTTPResult) {
	defer wg.Done()

	// Simulate network delay
	delay := time.Duration(rand.Intn(400)+100) * time.Millisecond
	time.Sleep(delay)

	// Simulate some CPU work
	result := 0.0
	for i := 0; i < 1000000; i++ {
		result += math.Sqrt(float64(i))
	}

	results <- HTTPResult{
		ID:        id,
		Result:    result,
		Timestamp: float64(time.Now().UnixNano()) / 1e9,
	}
}

// Simulate database operation
func dbQuery(id int, wg *sync.WaitGroup, results chan<- DBResult) {
	defer wg.Done()

	// Simulate DB query delay
	delay := time.Duration(rand.Intn(150)+50) * time.Millisecond
	time.Sleep(delay)

	// Simulate data processing
	data := make([]string, 10000)
	for i := 0; i < 10000; i++ {
		data[i] = fmt.Sprintf("user_%d_record_%d", id, i)
	}

	sample := data[:3]
	if len(data) < 3 {
		sample = data
	}

	results <- DBResult{
		ID:      id,
		Records: len(data),
		Sample:  sample,
	}
}

func main() {
	fmt.Println("Starting Golang Concurrent Benchmark...")

	// Record start metrics
	startTime := time.Now()
	var startMem runtime.MemStats
	runtime.ReadMemStats(&startMem)

	// Channels for results
	httpResults := make(chan HTTPResult, 100)
	dbResults := make(chan DBResult, 50)

	// WaitGroup for synchronization
	var wg sync.WaitGroup

	// Launch 100 concurrent HTTP requests
	for i := 0; i < 100; i++ {
		wg.Add(1)
		go httpRequest(i, &wg, httpResults)
	}

	// Launch 50 concurrent DB queries
	for i := 0; i < 50; i++ {
		wg.Add(1)
		go dbQuery(i, &wg, dbResults)
	}

	fmt.Println("Launched 150 concurrent goroutines")

	// Wait for all goroutines to complete
	go func() {
		wg.Wait()
		close(httpResults)
		close(dbResults)
	}()

	// Collect results
	httpCount := 0
	dbCount := 0

	for httpResults != nil || dbResults != nil {
		select {
		case result, ok := <-httpResults:
			if !ok {
				httpResults = nil
			} else {
				httpCount++
				if httpCount <= 3 { // Print first 3 for verification
					fmt.Printf("HTTP %d completed: %.2f\n", result.ID, result.Result)
				}
			}
		case result, ok := <-dbResults:
			if !ok {
				dbResults = nil
			} else {
				dbCount++
				if dbCount <= 3 { // Print first 3 for verification
					fmt.Printf("DB %d completed: %d records\n", result.ID, result.Records)
				}
			}
		}
	}

	// Record end metrics
	endTime := time.Now()
	var endMem runtime.MemStats
	runtime.ReadMemStats(&endMem)

	fmt.Println("\n=== Golang Results ===")
	fmt.Printf("Total execution time: %.3f seconds\n", endTime.Sub(startTime).Seconds())
	fmt.Printf("Memory used: %.2f MB\n", float64(endMem.Alloc-startMem.Alloc)/1024/1024)
	fmt.Printf("Peak memory: %.2f MB\n", float64(endMem.Sys)/1024/1024)
	fmt.Printf("Goroutines at end: %d\n", runtime.NumGoroutine())
	fmt.Printf("HTTP requests completed: %d\n", httpCount)
	fmt.Printf("DB queries completed: %d\n", dbCount)
}
