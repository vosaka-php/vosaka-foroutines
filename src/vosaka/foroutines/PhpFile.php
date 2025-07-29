<?php

namespace vosaka\foroutines;

use Exception;
use InvalidArgumentException;
use vosaka\foroutines\Delay;
use Symfony\Component\Process\Process as SymfonyProcess;

/**
 * Class PhpFile
 * This class is used to run a PHP file asynchronously.
 * It checks if the file is readable and is a valid PHP file before executing it.
 */
readonly class PhpFile
{
    public function __construct(private string $file, private array $args = [])
    {
        if (!is_readable($this->file)) {
            throw new InvalidArgumentException('Error: file ' . $this->file
                . ' does not exists or is not readable!');
        }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $this->file);
        finfo_close($finfo);
        if (!in_array($mimeType, ['text/x-php', 'application/x-php', 'application/php', 'application/x-httpd-php'])) {
            throw new Exception('Error: file ' . $this->file . ' is not a PHP file!');
        }
    }

    private function echoOutputNoResult(string $output): void
    {
        if ($this->getResultFromOutput($output) === null) {
            echo $output;
        }
    }

    private function getResultFromOutput(string $output): mixed
    {
        // token result
        $output = trim($output);
        $output = explode("<RESULT>", $output, 2)[1] ?? '';

        if (empty($output)) {
            return null;
        }

        $decodedOutput = base64_decode($output);
        if ($decodedOutput === false) {
            throw new Exception("Failed to decode base64 output");
        }

        $result = @unserialize($decodedOutput);
        if ($result === false && $decodedOutput !== serialize(false)) {
            throw new Exception("Failed to unserialize output");
        }

        return $result;
    }

    /**
     * Runs the PHP file asynchronously.
     * It detects the operating system and runs the file accordingly.
     *
     * @return Async An Async instance that resolves to the output of the PHP file.
     * @throws Exception if the process fails or if there is an error reading the output.
     */
    public function run(): Async
    {
        return Async::new(function () {
            return $this->runProcess();
        });
    }

    private function runProcess(): mixed
    {
        $command = [PHP_BINARY, $this->file, ...$this->args];
        $process = new SymfonyProcess($command);
        $process->setTimeout(3600);
        $process->start();

        while ($process->isRunning()) {
            $output = $process->getIncrementalOutput();
            $error = $process->getIncrementalErrorOutput();

            if (strlen($output) !== 0) {
                $this->echoOutputNoResult($output);
            }

            if (strlen($error) !== 0) {
                throw new Exception("Error in process: {$error}");
            }

            Delay::new(500);
        }

        $result = $process->getOutput();
        return $this->getResultFromOutput($result);
    }

    /**
     * Alternative Unix implementation using proc_open for more control over the process.
     * This method provides real-time output streaming similar to Windows implementation.
     *
     * @return mixed
     * @throws Exception if the process fails or if there is an error reading the output.
     */
    private function runOnUnixWithProcOpen(): mixed
    {
        $command = [PHP_BINARY, $this->file, ...$this->args];
        $commandString = implode(' ', array_map('escapeshellarg', $command));

        $descriptors = [
            0 => ["pipe", "r"],  // stdin
            1 => ["pipe", "w"],  // stdout
            2 => ["pipe", "w"]   // stderr
        ];

        $process = proc_open($commandString, $descriptors, $pipes);

        if (!is_resource($process)) {
            throw new Exception('Failed to create process');
        }

        fclose($pipes[0]); // Close stdin as we don't need it

        // Set streams to non-blocking mode
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $fullOutput = '';
        $fullError = '';
        $startTime = time();
        $timeout = 3600; // 1 hour timeout

        do {
            $status = proc_get_status($process);

            // Check for timeout
            if (time() - $startTime > $timeout) {
                proc_terminate($process);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
                throw new Exception("Process timeout after {$timeout} seconds");
            }

            // Read from stdout
            $stdout = stream_get_contents($pipes[1]);
            if ($stdout !== false && strlen($stdout) > 0) {
                echo $stdout;
                $fullOutput .= $stdout;
            }

            // Read from stderr
            $stderr = stream_get_contents($pipes[2]);
            if ($stderr !== false && strlen($stderr) > 0) {
                echo $stderr;
                $fullError .= $stderr;
            }

            if ($status['running']) {
                Delay::new(500);
            }
        } while ($status['running']);

        // Get any remaining output
        $remainingOutput = stream_get_contents($pipes[1]);
        $remainingError = stream_get_contents($pipes[2]);

        if ($remainingOutput !== false && strlen($remainingOutput) > 0) {
            echo $remainingOutput;
            $fullOutput .= $remainingOutput;
        }

        if ($remainingError !== false && strlen($remainingError) > 0) {
            echo $remainingError;
            $fullError .= $remainingError;
        }

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        if ($exitCode !== 0 && !empty($fullError)) {
            throw new Exception("Process failed with exit code {$exitCode}: {$fullError}");
        }

        if (empty($fullOutput)) {
            throw new Exception("No output received from process");
        }

        return $this->getResultFromOutput($fullOutput);
    }

    /**
     * Runs the PHP file using proc_open for more control over the process.
     *
     * @return Async An Async instance that resolves to the output of the PHP file.
     * @throws Exception if the process fails or if there is an error reading the output.
     */
    public function runWithProcOpen(): Async
    {
        return Async::new(function () {
            return $this->runOnUnixWithProcOpen();
        });
    }
}
