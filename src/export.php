<?php
/**
 * @psalm-type ProjectName = "dev-tools" | "core" | "cli" | "environment" | "examples" | "mysql" | "mysql-dbms" | "optional" | "queue" | "raspberrypi" | "starter" | "store" | "web" | "cui" | "spa" | "web-starter" | "svelte-starter"
 */

use function Amp\call;
use function Amp\File\createDirectoryRecursively;
use function Amp\File\exists;
use function Amp\File\isFile;
use Amp\Promise;
use function CatPaw\copyDirectoryRecursively;
use function CatPaw\copyFile;
use function CatPaw\deleteDirectoryRecursively;

/**
 * @param  string      $root
 * @param  ProjectName $project
 * @param  array       $items
 * @return Promise
 */
function exportProjectItems(string $root, string $master, mixed $project, array $items):Promise {
    return call(function() use ($root, $master, $project, $items) {
        foreach ($items as $item) {
            $source      = "$root/$master/$item";
            $destination = "$root/$project/$item";
            if (!yield exists($source)) {
                echo "Skipping source file \"$source\" because it doesn't exist.".PHP_EOL;
                continue;
            }
            if (yield isFile($source)) {
                $ddirname = dirname($destination);
                if (!yield exists($ddirname)) {
                    yield createDirectoryRecursively($ddirname);
                }
                yield copyFile($source, $destination);
                continue;
            }
            if (yield exists($destination)) {
                yield deleteDirectoryRecursively($destination);
            }
            yield copyDirectoryRecursively($source, $destination);
        }
    });
}


/**
 * @return Promise<void>
 */
function export():Promise {
    return call(function() {
        /** @var array */
        $projects = $_ENV['projects'] ?? [];
        /** @var string */
        $master = $_ENV['master'] ?? '';
        $root   = realpath('.');


        foreach ($projects as $name => $props) {
            if ($master === $name) {
                // skip self
                continue;
            }
            $exports = $props['imports'] ?? $_ENV['exports'] ?? [];
            if ('none' === $exports) {
                $exports = [];
            }
            exportProjectItems($root, $master, $name, $exports);
        }
    });
}
