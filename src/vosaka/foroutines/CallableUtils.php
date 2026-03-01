<?php

declare(strict_types=1);

namespace vosaka\foroutines;

use Closure;
use Fiber;
use Generator;
use Laravel\SerializableClosure\SerializableClosure;

final class CallableUtils
{
    public static function makeCallable(
        callable|Generator|Async|Fiber $callable,
    ): callable {
        if ($callable instanceof Fiber) {
            return self::fiberToCallable($callable);
        }

        if ($callable instanceof Async) {
            return self::fiberToCallable($callable->fiber);
        }

        if ($callable instanceof Generator) {
            return self::generatorToCallable($callable);
        }

        return $callable;
    }

    public static function fiberToCallable(Fiber $fiber): callable
    {
        return function () use ($fiber) {
            if (!$fiber->isStarted()) {
                $fiber->start();
            }

            while ($fiber->isStarted() && !$fiber->isTerminated()) {
                $fiber->resume();
            }

            return $fiber->getReturn();
        };
    }

    public static function generatorToCallable(Generator $generator): callable
    {
        return function () use ($generator) {
            if (!$generator->valid()) {
                return null;
            }

            $result = null;
            while ($generator->valid()) {
                $result = $generator->current();
                $generator->next();
            }

            return $result;
        };
    }

    /**
     * Check whether a PHP file contains top-level class, interface, trait,
     * enum, or function definitions using the tokenizer.
     *
     * Returns an associative array with two keys:
     *   'classes'   => string[]  (FQCN of every class/interface/trait/enum)
     *   'functions' => bool      (true if at least one top-level function exists)
     *
     * @param string $filePath Absolute path to the PHP file.
     * @return array{classes: string[], functions: bool}
     */
    private static function scanFileDefinitions(string $filePath): array
    {
        $result = ["classes" => [], "functions" => false];

        if (!is_file($filePath) || !is_readable($filePath)) {
            return $result;
        }

        $code = @file_get_contents($filePath);
        if ($code === false) {
            return $result;
        }

        try {
            $tokens = token_get_all($code, TOKEN_PARSE);
        } catch (\ParseError) {
            return $result;
        }

        $namespace = "";
        $count = count($tokens);
        $braceDepth = 0; // track nesting to distinguish top-level from nested

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            // Track brace depth for top-level detection
            if (!is_array($token)) {
                if ($token === "{") {
                    $braceDepth++;
                } elseif ($token === "}") {
                    $braceDepth--;
                }
                continue;
            }

            // Track namespace declarations: namespace Foo\Bar;
            if ($token[0] === T_NAMESPACE) {
                $nsParts = "";
                $i++;
                while ($i < $count) {
                    $t = $tokens[$i];
                    if (
                        is_array($t) &&
                        in_array(
                            $t[0],
                            [
                                T_NAME_QUALIFIED,
                                T_NAME_FULLY_QUALIFIED,
                                T_STRING,
                            ],
                            true,
                        )
                    ) {
                        $nsParts .= $t[1];
                    } elseif (
                        (!is_array($t) && ($t === ";" || $t === "{")) ||
                        (is_array($t) && $t[0] !== T_WHITESPACE)
                    ) {
                        if (!is_array($t) && $t === "{") {
                            $braceDepth++;
                        }
                        break;
                    }
                    $i++;
                }
                $namespace = $nsParts;
                continue;
            }

            // Detect class, interface, trait, enum keywords
            $definitionTokens = [T_CLASS, T_INTERFACE, T_TRAIT];
            if (defined("T_ENUM")) {
                $definitionTokens[] = T_ENUM;
            }

            if (in_array($token[0], $definitionTokens, true)) {
                // Peek forward for the name token (skip anonymous classes)
                $j = $i + 1;
                while (
                    $j < $count &&
                    is_array($tokens[$j]) &&
                    $tokens[$j][0] === T_WHITESPACE
                ) {
                    $j++;
                }
                if (
                    $j < $count &&
                    is_array($tokens[$j]) &&
                    $tokens[$j][0] === T_STRING
                ) {
                    $className = $tokens[$j][1];
                    $fqcn =
                        $namespace !== ""
                            ? $namespace . "\\" . $className
                            : $className;
                    $result["classes"][] = $fqcn;
                }
                continue;
            }

            // Detect top-level function definitions (brace depth 0 means
            // we are at the namespace/file level, not inside a class body).
            if ($token[0] === T_FUNCTION && $braceDepth === 0) {
                // Peek forward to ensure it's a named function, not a closure
                $j = $i + 1;
                while (
                    $j < $count &&
                    is_array($tokens[$j]) &&
                    $tokens[$j][0] === T_WHITESPACE
                ) {
                    $j++;
                }
                if (
                    $j < $count &&
                    is_array($tokens[$j]) &&
                    $tokens[$j][0] === T_STRING
                ) {
                    $result["functions"] = true;
                }
            }
        }

        return $result;
    }

    /**
     * Determine which of the parent process's included files need to be
     * loaded in the child process.  This uses a two-pronged strategy:
     *
     * 1. Files containing class definitions that are referenced by the
     *    serialized closure data (detected via O:<len>:"ClassName" markers).
     *    These MUST be loaded before unserialize() to avoid incomplete objects.
     *
     * 2. Files containing top-level function definitions.  User functions
     *    (e.g. work()) cannot be detected from the serialized data alone,
     *    so we include every user file that defines at least one function.
     *
     * Vendor/composer files are always excluded (handled by Composer's
     * autoloader).
     *
     * @param string[] $includedFiles  From get_included_files() in the parent.
     * @param string   $serializedCallable  The pre-serialized closure string.
     * @return string[]  File paths to require_once in the child process.
     */
    private static function collectFilesForChild(
        array $includedFiles,
        string $serializedCallable,
    ): array {
        // Extract class names referenced in the serialized closure data.
        $neededClasses = [];
        if (preg_match_all('/O:\d+:"([^"]+)"/', $serializedCallable, $m)) {
            foreach ($m[1] as $cls) {
                $neededClasses[ltrim(strtolower($cls), "\\")] = true;
            }
        }

        $filesToRequire = [];

        foreach ($includedFiles as $file) {
            // Normalize directory separators for reliable substring matching.
            $normalized = str_replace("\\", "/", $file);

            // Skip vendor and composer directories — their classes/functions
            // are already handled by Composer's autoloader.
            if (
                str_contains($normalized, "/vendor/") ||
                str_contains($normalized, "/composer/")
            ) {
                continue;
            }

            $defs = self::scanFileDefinitions($file);

            // Include this file if it defines a class we need …
            $hasNeededClass = false;
            foreach ($defs["classes"] as $cls) {
                if (isset($neededClasses[ltrim(strtolower($cls), "\\")])) {
                    $hasNeededClass = true;
                    break;
                }
            }

            // … or if it defines any top-level function.
            if ($hasNeededClass || $defs["functions"]) {
                $filesToRequire[] = $file;
            }
        }

        return array_unique($filesToRequire);
    }

    public static function makeCallableForThread(
        callable $callable,
        array $includedFiles,
    ): Closure {
        // ── Phase 1 (runs in the PARENT process) ─────────────────────
        //
        // 1. Pre-serialize the user's callable into a plain string while we
        //    are still in the parent process where every class and function
        //    definition is loaded.  This turns any captured user objects
        //    (e.g. instances of "Test") into opaque bytes inside a string —
        //    they are NOT live PHP objects any more, so the wrapper closure's
        //    `use` list only carries strings and arrays, which are always
        //    safe to (un)serialize without needing class definitions up front.
        $serializedCallable = serialize(
            new SerializableClosure(Closure::fromCallable($callable)),
        );

        // 2. Determine which user files the child process will need:
        //    - Files with class definitions referenced in the serialized data
        //      (so unserialize() can fully hydrate captured objects).
        //    - Files with top-level function definitions (so the closure body
        //      can call user functions like work()).
        //    Vendor files are excluded (already autoloadable via Composer).
        $filesToRequire = self::collectFilesForChild(
            $includedFiles,
            $serializedCallable,
        );

        // ── Phase 2 (runs in the CHILD process) ──────────────────────
        //
        // The closure below is serialized, sent through shmop, and executed
        // inside the child PHP process spawned by Process/Worker.
        //
        // IMPORTANT: This closure must NOT contain nested closures (e.g.
        // spl_autoload_register(function(){...})) because SerializableClosure
        // tries to recursively serialize them, which can cause segfaults.
        // We only capture plain strings and arrays here.
        return function () use ($serializedCallable, $filesToRequire) {
            // 2a. Load the required user files.  This ensures that:
            //     - Class definitions are known BEFORE unserialize() tries
            //       to hydrate captured objects (avoids __PHP_Incomplete_Class)
            //     - User-defined functions are available when the closure
            //       body executes
            foreach ($filesToRequire as $file) {
                if (is_file($file) && !in_array($file, get_included_files())) {
                    require_once $file;
                }
            }

            // 2b. Unserialize the real closure.  All captured user objects
            //     will be fully hydrated because their class definitions
            //     were loaded in step 2a.
            /** @var SerializableClosure $sc */
            $sc = unserialize($serializedCallable);

            // 2c. Execute.
            return call_user_func($sc->getClosure());
        };
    }
}
