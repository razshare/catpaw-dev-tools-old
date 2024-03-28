<?php
use function Amp\call;
use function Amp\File\exists;
use function Amp\File\read;
use function Amp\File\write;
use Amp\Promise;
use function Amp\Promise\all;
use function CatPaw\execute;

use CatPaw\Utilities\Container;

use Psr\Log\LoggerInterface;

/**
 * @return Promise<void>
 */
function sync():Promise {
    return call(function() {
        /** @var LoggerInterface */
        $logger = yield Container::create(LoggerInterface::class);

        if (yield exists("./.product.cache")) {
            $cache = yaml_parse(yield read("./.product.cache"));
        } else {
            $cache = [];
        }

        /** @var array */
        $projects  = $_ENV['projects'] ?? [];
        $root      = realpath('.');
        $libraries = [];
        $versions  = [];
        $promises  = [];
        
        foreach ($projects as $projectName => $projectProperties) {
            $library       = $projectProperties['library'] ?? $projectName;
            $versionString = preg_replace('/"/', '\\"', $projectProperties['version']);
            $versionPieces = explode('.', $versionString);
            $version       = join('.', [$versionPieces[0] ?? '0',$versionPieces[1] ?? '0']);
            $message       = preg_replace('/"/', '\\"', $projectProperties['message'] ?? "Version $versionString");
            if (strpos($message, '`')) {
                $logger->error("Project $projectName contains backticks (`) in its message, this is not allowed.", ["message" => $message]);
                die(22);
            }
            $libraries[]        = $library;
            $versions[$library] = $version;
        }

        foreach ($projects as $projectName => $projectProperties) {
            echo "Tagging project $projectName".PHP_EOL;

            $library       = $projectProperties['library'] ?? $projectName;
            $versionString = preg_replace('/"/', '\\"', $projectProperties['version']);

            $versionPieces = explode('.', $versionString);
            $version       = join('.', [$versionPieces[0] ?? '0',$versionPieces[1] ?? '0']);
            $message       = preg_replace('/"/', '\\"', $projectProperties['message'] ?? "Version $versionString");
            $message       = preg_replace('/\n|\s/', ' ', $message);

            $cwd             = "$root/$projectName";
            $composeFileName = "$cwd/composer.json";
            $composer        = json_decode(yield read($composeFileName));

            if (isset($composer->require)) {
                foreach ($composer->require as $composerLibrary => &$composerVersion) {
                    if (in_array($composerLibrary, $libraries)) {
                        $composerVersion = '^'.$versions[$composerLibrary];
                    }
                }
    
                yield write($composeFileName, trim(json_encode($composer, JSON_PRETTY_PRINT)));
    
                yield write($composeFileName, trim(str_replace('\/', '/', yield read($composeFileName))));
            }

            /**
             * @psalm-suppress MissingClosureReturnType
             */
            $promises[] = function() use ($cwd, $message, $versionString, $cache, $projectName) {
                echo yield execute("composer fix", $cwd);
                echo yield execute("rm composer.lock", $cwd);
                echo yield execute("git fetch", $cwd);
                echo yield execute("git pull", $cwd);
                echo yield execute("git add .", $cwd);
                echo yield execute("git commit -m\"$message\"", $cwd);
                echo yield execute("git push", $cwd);

                if (($cache["projects"][$projectName]["version"] ?? '') === $versionString) {
                    return;
                }

                echo yield execute("git tag -a \"$versionString\" -m\"$message\"", $cwd);
                echo yield execute("git push --tags", $cwd);
            };

            $cache["projects"][$projectName]["version"] = $versionString;
        }

        $joins = [];

        foreach ($promises as $promise) {
            $joins[] = call($promise);
        }

        yield all($joins);

        foreach ($projects as $projectName => $projectProperties) {
            $versionString = preg_replace('/"/', '\\"', $projectProperties['version']);
            $message       = preg_replace('/"/', '\\"', $projectProperties['message'] ?? "Version $versionString");

            echo "Updating project $projectName".PHP_EOL;

            $cwd = "$root/$projectName";


            call(function() use ($cwd) {
                yield execute("composer update", $cwd);
            });
        }
        
        $cacheStringified = yaml_emit($cache, YAML_UTF8_ENCODING);
        yield write(".product.cache", $cacheStringified);
    });
}