<?php

declare(strict_types=1);

namespace vosaka\foroutines;

use Error;
use Exception;
use ParseError;

final class RequireUtils
{
    /**
     * Safe require_once function - only requires files that haven't been included yet
     * Skips already included files and current file
     * 
     * @param string|array $files File path or array of file paths
     * @param bool $silent Whether to suppress output messages (default: false)
     * @return array Array of results with information about each file
     */
    public static function safeRequireOnce(string|array $files, bool $silent = false)
    {
        // Convert to array if string
        if (is_string($files)) {
            $files = [$files];
        }

        // Get list of already included files
        $included_files = get_included_files();

        // Get current file
        $current_file = __FILE__;

        $results = [];

        foreach ($files as $file) {
            // Normalize path
            $real_path = realpath($file);

            // Result info for each file
            $file_result = [
                'file' => $file,
                'real_path' => $real_path,
                'status' => '',
                'message' => '',
                'required' => false
            ];

            // Check if file exists
            if (!file_exists($file)) {
                $file_result['status'] = 'not_found';
                $file_result['message'] = "File not found: {$file}";

                if (!$silent) {
                    echo "Warning: {$file_result['message']}\n";
                }

                $results[] = $file_result;
                continue;
            }

            // Check if it's the current file
            if ($real_path === realpath($current_file)) {
                $file_result['status'] = 'current_file';
                $file_result['message'] = "Skipping current file: {$file}";

                if (!$silent) {
                    echo "Info: {$file_result['message']}\n";
                }

                $results[] = $file_result;
                continue;
            }

            // Check if file is already included
            $already_included = false;
            foreach ($included_files as $included_file) {
                if (realpath($included_file) === $real_path) {
                    $already_included = true;
                    break;
                }
            }

            if ($already_included) {
                $file_result['status'] = 'already_included';
                $file_result['message'] = "File already included: {$file}";

                if (!$silent) {
                    echo "Info: {$file_result['message']}\n";
                }

                $results[] = $file_result;
                continue;
            }

            // Perform require_once
            try {
                require_once $file;
                $file_result['status'] = 'success';
                $file_result['message'] = "Successfully required: {$file}";
                $file_result['required'] = true;

                if (!$silent) {
                    echo "Success: {$file_result['message']}\n";
                }
            } catch (ParseError $e) {
                $file_result['status'] = 'parse_error';
                $file_result['message'] = "Parse error in file {$file}: " . $e->getMessage();

                if (!$silent) {
                    echo "Error: {$file_result['message']}\n";
                }
            } catch (Error $e) {
                $file_result['status'] = 'fatal_error';
                $file_result['message'] = "Fatal error in file {$file}: " . $e->getMessage();

                if (!$silent) {
                    echo "Fatal Error: {$file_result['message']}\n";
                }
            } catch (Exception $e) {
                $file_result['status'] = 'exception';
                $file_result['message'] = "Exception in file {$file}: " . $e->getMessage();

                if (!$silent) {
                    echo "Exception: {$file_result['message']}\n";
                }
            }

            $results[] = $file_result;
        }

        return $results;
    }

    /**
     * Get only files that would be required (without actually requiring them)
     * Useful for debugging or validation
     * 
     * @param string|array $files File path or array of file paths
     * @return array Array of files that would be required
     */
    public static function getFilesToRequire(string|array $files)
    {
        if (is_string($files)) {
            $files = [$files];
        }

        $included_files = get_included_files();
        $current_file = __FILE__;
        $files_to_require = [];

        foreach ($files as $file) {
            if (!file_exists($file)) {
                continue;
            }

            $real_path = realpath($file);

            // Skip current file
            if ($real_path === realpath($current_file)) {
                continue;
            }

            // Skip already included files
            $already_included = false;
            foreach ($included_files as $included_file) {
                if (realpath($included_file) === $real_path) {
                    $already_included = true;
                    break;
                }
            }

            if (!$already_included) {
                $files_to_require[] = $file;
            }
        }

        return $files_to_require;
    }
}
