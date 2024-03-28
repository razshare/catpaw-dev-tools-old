<?php

use function Amp\call;
use function Amp\File\createDirectoryRecursively;
use function Amp\File\deleteFile;
use function Amp\File\exists;
use function Amp\File\read;
use function Amp\File\write;
use Amp\Promise;

use function CatPaw\deleteDirectoryRecursively;
use function CatPaw\execute;

/**
 * 
 * @param string $config name of the build yaml file.
 * 
 * Multiple names separated by "," are allowed, only the first valid name will be used.
 * @return Promise
 */
function build(
    string $build,
    bool $optimize,
):Promise {
    return call(function() use ($build, $optimize) {
        $buildFileFound = false;
        foreach (explode(',', $build) as $buildFile) {
            if (!yield exists($buildFile)) {
                continue;
            }
            $buildFileFound = true;
            $config         = yaml_parse(yield read($buildFile));
        }

        if (!$buildFileFound) {
            die('Build file not found.'.PHP_EOL);
        }

        /**
         * @var string $name
         * @var string $entry
         * @var string $libraries
         * @var string $match
         */
        $name      = $config['name']      ?? 'app.phar';
        $entry     = $config['entry']     ?? '';
        $libraries = $config['libraries'] ?? '';
        $match     = $config['match']     ?? '';
    
        $name      = str_replace(['"',"\n"], ['\\"',''], $name);
        $entry     = str_replace(['"',"\n"], ['\\"',''], $entry);
        $libraries = str_replace(['"',"\n"], ['\\"',''], $libraries);
        
        if (!str_ends_with(strtolower($name), '.phar')) {
            $name .= '.phar';
        }

        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    
        if (!str_starts_with($entry, './')) {
            if (!$isWindows) {
                die("The entry file path must be relative to the project, received: $entry.".PHP_EOL);
            }
            if (!str_starts_with($entry, '.\\')) {
                die("The entry file path must be relative to the project, received: $entry.".PHP_EOL);
            }
        }
        
        foreach (!$libraries?[]:\preg_split('/,|;/', $libraries) as $libraries) {
            if (!str_starts_with($libraries, './')) {
                if (!$isWindows) {
                    die("All library directory paths must be relative to the project, received: $libraries.".PHP_EOL);
                }
                if (str_starts_with($libraries, '.\\')) {
                    continue;
                }
            }
        }
    
        $app   = "$name";
        $start = '.build-cache/start.php';
    
        $dirnameStart = dirname($start);
        try {
            if (yield exists($dirnameStart)) {
                yield deleteDirectoryRecursively($dirnameStart);
            }
    
            yield createDirectoryRecursively($dirnameStart);
    
            yield write($start, <<<PHP
                <?php
                use Amp\File\Filesystem;
                use Amp\Loop;
                use CatPaw\Amp\File\CatPawDriver;
                use CatPaw\Bootstrap;
                \$_ENV = [
                    ...\$_ENV,
                    ...getenv(),
                ];
                require 'vendor/autoload.php';

                if (isset(\$_ENV["CATPAW_FILE_DRIVER"]) && \$_ENV["CATPAW_FILE_DRIVER"]) {
                    Loop::setState(\Amp\File\Driver::class, new Filesystem(new CatPawDriver));
                }
                Bootstrap::start(
                    entry: "$entry",
                    name: "$name",
                    libraries: "$libraries",
                    info: false,
                    dieOnChange: false,
                    resources: '',
                );
                PHP);
    
            if (yield exists($app)) {
                yield deleteFile($app);
            }
    
            if (yield exists($app.'.gz')) {
                yield deleteFile($app.'.gz');
            }
            
            if ($optimize) {
                echo yield execute("composer update --no-dev");
            }

            $phar = new Phar($app);
    
            $phar->startBuffering();
    
            $phar->buildFromDirectory('.', $match);
    
            $phar->setStub(
                "#!/usr/bin/env php \n".$phar->createDefaultStub($start)
            );
    
            $phar->stopBuffering();
    
            $phar->compressFiles(Phar::GZ);
    
            # Make the file executable
            chmod($app, 0770);
    
            // yield deleteFile($start);
            yield deleteDirectoryRecursively($dirnameStart);
    
            if ($optimize) {
                echo yield execute("composer update");
            }

            echo "$app successfully created".PHP_EOL;
        } catch (Exception $e) {
            die(((string)$e).PHP_EOL);
        }
    });
}