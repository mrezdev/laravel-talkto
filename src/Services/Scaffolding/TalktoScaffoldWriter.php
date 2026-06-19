<?php

namespace Mrezdev\LaravelTalkto\Services\Scaffolding;

use Mrezdev\LaravelTalkto\Support\Scaffolding\TalktoScaffoldFile;
use Mrezdev\LaravelTalkto\Support\Scaffolding\TalktoScaffoldResult;
use RuntimeException;
use Throwable;

final class TalktoScaffoldWriter
{
    /**
     * @param  array<int, TalktoScaffoldFile>  $files
     */
    public function write(array $files, bool $dryRun = false, bool $force = false): TalktoScaffoldResult
    {
        $created = [];
        $skipped = [];
        $overwritten = [];
        $intended = [];
        $errors = [];

        foreach ($files as $file) {
            $intended[] = $file->path;

            if ($dryRun) {
                continue;
            }

            try {
                $exists = is_file($file->path);

                if ($exists && ! $force) {
                    $skipped[] = $file->path;

                    continue;
                }

                $directory = dirname($file->path);

                if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
                    throw new RuntimeException("Unable to create directory [{$directory}].");
                }

                if (file_put_contents($file->path, $file->contents) === false) {
                    throw new RuntimeException("Unable to write file [{$file->path}].");
                }

                if ($exists) {
                    $overwritten[] = $file->path;
                } else {
                    $created[] = $file->path;
                }
            } catch (Throwable $exception) {
                $errors[$file->path] = $exception->getMessage();
            }
        }

        return new TalktoScaffoldResult(
            created: $created,
            skipped: $skipped,
            overwritten: $overwritten,
            intended: $dryRun ? $intended : [],
            errors: $errors,
        );
    }
}
