<?php

declare(strict_types=1);

namespace vosaka\foroutines;

use Closure;
use Fiber;
use Laravel\SerializableClosure\SerializableClosure;

final class CallableUtils
{
    /**
     * Temporary files created for extracted class definitions.
     * Cleaned up on shutdown.
     * @var string[]
     */
    private static array $tempFiles = [];

    /**
     * Whether the shutdown cleanup function has been registered.
     */
    private static bool $shutdownRegistered = false;

    public static function makeCallable(
        callable|Async|Fiber $callable,
    ): callable {
        if ($callable instanceof Fiber) {
            return self::fiberToCallable($callable);
        }

        if ($callable instanceof Async) {
            return self::fiberToCallable($callable->fiber);
        }

        return $callable;
    }

    public static function fiberToCallable(Fiber $fiber): callable
    {
        return function () use ($fiber) {
            if (!$fiber->isStarted()) {
                $fiber->start();
            }

            return $fiber->getReturn();
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
     * Extract source code for specific class/interface/trait/enum definitions
     * from a PHP file, along with their namespace and use-import context.
     *
     * Returns a string containing valid PHP code (with opening <?php tag)
     * that, when included, will define only the requested classes — no
     * top-level executable code is emitted.
     *
     * @param string   $filePath       Absolute path to the PHP file.
     * @param string[] $neededClasses  Lowercased FQCNs to extract.
     * @return string|null  PHP source code, or null if nothing was extracted.
     */
    private static function extractClassDefinitions(
        string $filePath,
        array $neededClasses,
    ): ?string {
        if (!is_file($filePath) || !is_readable($filePath)) {
            return null;
        }

        $code = @file_get_contents($filePath);
        if ($code === false) {
            return null;
        }

        try {
            $tokens = token_get_all($code, TOKEN_PARSE);
        } catch (\ParseError) {
            return null;
        }

        $count = count($tokens);
        $namespace = "";
        $useStatements = [];
        $extractedBlocks = [];
        $braceDepth = 0;

        // Collect the byte-offset ranges of class definitions we need,
        // plus namespace and use-import context.
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (!is_array($token)) {
                if ($token === "{") {
                    $braceDepth++;
                } elseif ($token === "}") {
                    $braceDepth--;
                }
                continue;
            }

            // Track namespace
            if ($token[0] === T_NAMESPACE) {
                $nsParts = "";
                $j = $i + 1;
                while ($j < $count) {
                    $t = $tokens[$j];
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
                        break;
                    }
                    $j++;
                }
                $namespace = $nsParts;
                continue;
            }

            // Track top-level use-import statements (not trait-use inside class bodies)
            if ($token[0] === T_USE && $braceDepth === 0) {
                // Collect the entire use statement text
                $useText = "use ";
                $j = $i + 1;
                while ($j < $count) {
                    $t = $tokens[$j];
                    if (!is_array($t) && $t === ";") {
                        $useText .= ";";
                        break;
                    }
                    $useText .= is_array($t) ? $t[1] : $t;
                    $j++;
                }
                $useStatements[] = $useText;
                continue;
            }

            // Detect class/interface/trait/enum definitions
            $definitionTokens = [T_CLASS, T_INTERFACE, T_TRAIT];
            if (defined("T_ENUM")) {
                $definitionTokens[] = T_ENUM;
            }

            if (!in_array($token[0], $definitionTokens, true)) {
                continue;
            }

            // Find the class name
            $j = $i + 1;
            while (
                $j < $count &&
                is_array($tokens[$j]) &&
                $tokens[$j][0] === T_WHITESPACE
            ) {
                $j++;
            }
            if (
                $j >= $count ||
                !is_array($tokens[$j]) ||
                $tokens[$j][0] !== T_STRING
            ) {
                continue; // anonymous class or parse oddity
            }

            $className = $tokens[$j][1];
            $fqcn =
                $namespace !== "" ? $namespace . "\\" . $className : $className;

            if (!isset($neededClasses[ltrim(strtolower($fqcn), "\\")])) {
                continue; // not a class we need
            }

            // Walk backwards from $i to capture attributes, docblocks,
            // abstract/final/readonly keywords that precede the class keyword.
            $startIndex = $i;
            $k = $i - 1;
            while ($k >= 0) {
                $t = $tokens[$k];
                if (is_array($t)) {
                    if (
                        in_array(
                            $t[0],
                            [
                                T_WHITESPACE,
                                T_COMMENT,
                                T_DOC_COMMENT,
                                T_ABSTRACT,
                                T_FINAL,
                                T_READONLY,
                                T_ATTRIBUTE,
                            ],
                            true,
                        )
                    ) {
                        $startIndex = $k;
                        $k--;
                        continue;
                    }
                    // Also handle #[...] attribute syntax on older tokenizers
                    break;
                }
                // Non-array token (e.g. ']' from attribute) — stop
                break;
            }

            // Find the opening brace of the class body, then match
            // braces to find the closing brace.
            $classStart = $tokens[$startIndex][2] ?? null; // line number
            $startOffset = is_array($tokens[$startIndex])
                ? $tokens[$startIndex][2]
                : null;

            // Find opening '{' for the class body
            $openBrace = null;
            $m = $j + 1;
            while ($m < $count) {
                $t = $tokens[$m];
                if (!is_array($t) && $t === "{") {
                    $openBrace = $m;
                    break;
                }
                $m++;
            }

            if ($openBrace === null) {
                continue;
            }

            // Match braces to find the end of the class body
            $depth = 1;
            $closeBrace = null;
            for ($m = $openBrace + 1; $m < $count; $m++) {
                $t = $tokens[$m];
                if (!is_array($t)) {
                    if ($t === "{") {
                        $depth++;
                    } elseif ($t === "}") {
                        $depth--;
                        if ($depth === 0) {
                            $closeBrace = $m;
                            break;
                        }
                    }
                }
            }

            if ($closeBrace === null) {
                continue;
            }

            // Reconstruct the source code for this class definition
            $classCode = "";
            for ($m = $startIndex; $m <= $closeBrace; $m++) {
                $t = $tokens[$m];
                $classCode .= is_array($t) ? $t[1] : $t;
            }

            $extractedBlocks[] = $classCode;
        }

        if (empty($extractedBlocks)) {
            return null;
        }

        // Build the output PHP file
        $output = "<?php\n\n";
        $output .= "// Auto-extracted class definitions from: {$filePath}\n";
        $output .=
            "// Generated by CallableUtils::extractClassDefinitions()\n\n";

        if ($namespace !== "") {
            $output .= "namespace {$namespace};\n\n";
        }

        foreach ($useStatements as $use) {
            $output .= $use . "\n";
        }
        if (!empty($useStatements)) {
            $output .= "\n";
        }

        foreach ($extractedBlocks as $block) {
            $output .= trim($block) . "\n\n";
        }

        return $output;
    }

    /**
     * Create a temporary PHP file with the given content and register it
     * for cleanup on shutdown.
     *
     * @param string $content Valid PHP source code.
     * @return string Absolute path to the temporary file.
     */
    private static function createTempClassFile(string $content): string
    {
        $tmpDir = sys_get_temp_dir();
        $tmpFile = tempnam($tmpDir, "vsk_cls_");
        if ($tmpFile === false) {
            // Fallback
            $tmpFile =
                $tmpDir . DIRECTORY_SEPARATOR . "vsk_cls_" . mt_rand() . ".php";
        } else {
            // tempnam creates the file; rename to .php extension
            $phpFile = $tmpFile . ".php";
            rename($tmpFile, $phpFile);
            $tmpFile = $phpFile;
        }

        file_put_contents($tmpFile, $content);
        self::$tempFiles[] = $tmpFile;

        if (!self::$shutdownRegistered) {
            register_shutdown_function(function () {
                foreach (CallableUtils::getTempFiles() as $f) {
                    if (is_file($f)) {
                        @unlink($f);
                    }
                }
            });
            self::$shutdownRegistered = true;
        }

        return $tmpFile;
    }

    /**
     * Returns the list of temporary files (used by shutdown cleanup).
     * @return string[]
     */
    public static function getTempFiles(): array
    {
        return self::$tempFiles;
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

        // Determine the main entry-point script so we can skip it.
        // Including the entry script in a worker child would re-execute
        // its top-level code (e.g. main(), RunBlocking::new(), TCP
        // server creation …) causing infinite spawning loops or hangs.
        //
        // We use multiple heuristics in order of reliability:
        //   1. $_SERVER['SCRIPT_FILENAME'] — the script PHP was invoked with
        //   2. $_SERVER['argv'][0]         — CLI argument (may be relative)
        //   3. $includedFiles[0]           — first included file is almost
        //      always the entry-point script
        $entryScripts = [];

        if (!empty($_SERVER["SCRIPT_FILENAME"])) {
            $real = realpath($_SERVER["SCRIPT_FILENAME"]);
            if ($real !== false) {
                $entryScripts[] = $real;
            }
        }
        if (!empty($_SERVER["argv"][0])) {
            $real = realpath($_SERVER["argv"][0]);
            if ($real !== false) {
                $entryScripts[] = $real;
            }
        }
        if (!empty($includedFiles[0])) {
            $real = realpath($includedFiles[0]);
            if ($real !== false) {
                $entryScripts[] = $real;
            }
        }

        // Normalise to lowercase on Windows for reliable comparison
        $isWindows = strncasecmp(PHP_OS, "WIN", 3) === 0;
        if ($isWindows) {
            $entryScripts = array_map("strtolower", $entryScripts);
        }
        $entryScripts = array_unique($entryScripts);

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

            // Check if this file is the main entry-point script.
            $isEntryScript = false;
            $fileReal = realpath($file);
            if ($fileReal !== false) {
                $cmp = $isWindows ? strtolower($fileReal) : $fileReal;
                if (in_array($cmp, $entryScripts, true)) {
                    $isEntryScript = true;
                }
            }

            if ($isEntryScript) {
                // The entry script cannot be require_once'd (it would
                // re-execute top-level code like main(), RunBlocking,
                // WorkerPool boot, etc.). However, if it defines classes
                // that the serialized closure needs, we must make those
                // class definitions available in the child process.
                //
                // Strategy: use the tokenizer to extract ONLY the class
                // definitions into a temporary PHP file, then include
                // that temp file instead.
                $defs = self::scanFileDefinitions($file);
                $entryHasNeededClass = false;
                foreach ($defs["classes"] as $cls) {
                    if (isset($neededClasses[ltrim(strtolower($cls), "\\")])) {
                        $entryHasNeededClass = true;
                        break;
                    }
                }

                if ($entryHasNeededClass) {
                    $extracted = self::extractClassDefinitions(
                        $file,
                        $neededClasses,
                    );
                    if ($extracted !== null) {
                        $tmpFile = self::createTempClassFile($extracted);
                        $filesToRequire[] = $tmpFile;
                    }
                }

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

    /**
     * Executes a background task within a RunBlocking context, avoiding creating
     * a redundant redundant Async fiber for the closure.
     * @param Closure $closure The task to execute.
     * @return mixed The result of the task.
     */
    public static function executeTask(Closure $closure): mixed
    {
        $result = null;
        RunBlocking::new(function () use (&$result, $closure) {
            $result = $closure();
        });
        Thread::await();
        return $result;
    }

    /**
     * Builds a standard error array for worker processes to return to the parent.
     * @param \Throwable $e The caught exception.
     * @return array
     */
    public static function buildWorkerError(\Throwable $e): array
    {
        return [
            "__worker_error__" => true,
            "message" => $e->getMessage(),
            "file" => $e->getFile(),
            "line" => $e->getLine(),
            "trace" => $e->getTraceAsString(),
        ];
    }
}
