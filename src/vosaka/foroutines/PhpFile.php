<?php

namespace vosaka\foroutines;

use Exception;
use InvalidArgumentException;
use Symfony\Component\Process\Process as SymfonyProcess;
use vosaka\foroutines\Sleep;

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
            if (PHP_OS_FAMILY === 'Windows') {
                return $this->runOnWindows();
            } else {
                return $this->runOnUnix();
            }
        });
    }

    private function runOnWindows(): string
    {
        $command = [PHP_BINARY, $this->file, ...$this->args];
        $process = new SymfonyProcess($command);
        $process->setTimeout(3600);

        $fullOutput = '';
        $fullError = '';

        $process->start();

        while ($process->isRunning()) {
            $newOutput = $process->getIncrementalOutput();
            $newError = $process->getIncrementalErrorOutput();

            $fullOutput .= $newOutput;
            $fullError .= $newError;

            Sleep::new(0.05);
        }

        $remainingOutput = $process->getIncrementalOutput();
        $remainingError = $process->getIncrementalErrorOutput();

        $fullOutput .= $remainingOutput;
        $fullError .= $remainingError;

        if (empty($fullOutput)) {
            $fullOutput = $process->getOutput();
        }

        if (!$process->isSuccessful() && !empty($fullError)) {
            throw new Exception("Process failed: {$fullError}");
        }

        return unserialize(base64_decode(trim($fullOutput)));
    }

    private function runOnUnix(): string
    {
        $escapedFile = escapeshellarg($this->file);
        $escapedArgs = array_map('escapeshellarg', $this->args);
        $argsString = implode(' ', $escapedArgs);

        $tempFile = tempnam(sys_get_temp_dir(), 'php_async_');
        $errorFile = tempnam(sys_get_temp_dir(), 'php_async_error_');

        $command = PHP_BINARY . ' ' . $escapedFile . ' ' . $argsString . ' > ' . escapeshellarg($tempFile) . ' 2> ' . escapeshellarg($errorFile) . ' & echo $!';

        $pidOutput = [];
        exec($command, $pidOutput);
        $pid = !empty($pidOutput) ? (int)$pidOutput[0] : null;

        if ($pid === null) {
            throw new Exception('Failed to start process');
        }

        while (posix_kill($pid, 0)) {
            Sleep::new(0.05);
        }

        $output = file_exists($tempFile) ? file_get_contents($tempFile) : '';
        $error = file_exists($errorFile) ? file_get_contents($errorFile) : '';

        if (file_exists($tempFile)) unlink($tempFile);
        if (file_exists($errorFile)) unlink($errorFile);

        if (!empty($error)) {
            throw new Exception("Process failed: {$error}");
        }

        return unserialize(base64_decode(trim($output)));
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

            fclose($pipes[0]);
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);

            $output = '';
            $error = '';

            do {
                $status = proc_get_status($process);
                $stdout = stream_get_contents($pipes[1]);
                $stderr = stream_get_contents($pipes[2]);

                if ($stdout !== false) {
                    $output .= $stdout;
                }

                if ($stderr !== false) {
                    $error .= $stderr;
                }

                if ($status['running']) {
                    Sleep::new(0.05);
                }
            } while ($status['running']);

            $output .= stream_get_contents($pipes[1]);
            $error .= stream_get_contents($pipes[2]);

            fclose($pipes[1]);
            fclose($pipes[2]);

            $exitCode = proc_close($process);

            if ($exitCode !== 0 && !empty($error)) {
                throw new Exception("Process failed with exit code {$exitCode}: {$error}");
            }

            return unserialize(base64_decode(trim($output)));
        });
    }
}
