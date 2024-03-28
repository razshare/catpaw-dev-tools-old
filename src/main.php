<?php

use function Amp\File\deleteFile;
use function Amp\File\exists;
use function Amp\File\write;

use Amp\Promise;
use CatPaw\Attributes\Option;
use CatPaw\Environment\Attributes\Environment;
use Psr\Log\LoggerInterface;


/**
 * @param  LoggerInterface                      $logger
 * @param  bool                                 $sync
 * @param  bool                                 $export
 * @param  bool                                 $buildConfig
 * @param  false|string                         $build
 * @param  false|string                         $deleteAllTags
 * @param  string                               $executeEverywhere
 * @param  string                               $executeEverywhereParallel
 * @param  bool                                 $clearCache
 * @param  string                               $project
 * @throws Error
 * @return Generator<int, Promise, mixed, void>
 */
#[Environment('product.yml', 'product.yaml', 'resources/product.yml', 'resources/product.yaml')]
function main(
    #[Option("--sync")] bool $sync,
    #[Option("--export")] bool $export,
    #[Option("--build-config")] bool $buildConfig,
    #[Option("--build")] false|string $build,
    #[Option("--delete-all-tags")] false|string $deleteAllTags,
    #[Option("--execute-everywhere")] string $executeEverywhere,
    #[Option("--execute-everywhere-parallel")] string $executeEverywhereParallel,
    #[Option("--clear-cache")] bool $clearCache,
    #[Option("--project")] string $project = '',
    #[Option("--except")] string $except = '',
    #[Option("--optimize")] bool $optimizeLong = false,
    #[Option("-o")] bool $optimizeShort = false,
    #[Option("--dump-definitions")] false|string $dumpDefinitions = false,
    #[Option("--to")] string $to = '',
    #[Option("--clear")] bool $clear = false,
    #[Option("--namespace-prefix")] string $nameSpacePrefix = '',
) {
    $optimized = $optimizeLong || $optimizeShort;

    $project = trim($project);

    if ($clearCache && yield exists("./.product.cache")) {
        yield deleteFile("./.product.cache");
    }

    if ($executeEverywhere) {
        yield executeEverywhere($executeEverywhere, $except);
    }

    if ($executeEverywhereParallel) {
        yield executeEverywhereParallel($executeEverywhereParallel, $except);
    }
    

    if ($buildConfig) {
        echo 'Trying to generate build.yml file...';
        if (!yield exists('build.yml')) {
            yield write('build.yml', <<<YAML
                name: app
                entry: ./src/main.php
                libraries: ./src/lib
                match: /^\.\/(\.build-cache|src|vendor|resources|bin)\/.*/
                YAML);
            
            echo 'done!'.PHP_EOL;
        } else {
            echo 'a build.yml file already exists - will not overwrite.'.PHP_EOL;
        }
    }

    if (false !== $build) {
        if (ini_get('phar.readonly')) {
            die('Cannot build using readonly phar, please disable the phar.readonly flag by running the builder with "php -dphar.readonly=0"'.PHP_EOL);
        }
        yield build($build?$build:'build.yml,build.yaml', $optimized);
    }

    if ($export) {
        yield export();
    }

    if (false !== $deleteAllTags) {
        yield deleteAllTags($project);
    }

    if ($sync) {
        yield sync();
    }

    if (false !== $dumpDefinitions) {
        yield dumpDefinitions($dumpDefinitions, $to, $clear, $nameSpacePrefix);
    }
}