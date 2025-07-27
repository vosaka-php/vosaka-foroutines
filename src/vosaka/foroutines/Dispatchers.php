<?php

declare(strict_types=1);

namespace vosaka\foroutines;

enum Dispatchers
{
    // Dispatchers for asynchronous tasks
    case DEFAULT;
        // Dispatchers for I/O-bound tasks, call another thread to handle the task
    case IO;
        // Dispatchers for CPU-bound tasks, run the task in the main thread
    case MAIN;
}
