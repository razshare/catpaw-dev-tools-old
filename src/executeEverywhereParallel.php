<?php
use function Amp\call;
use Amp\Promise;
use function CatPaw\execute;

/**
 * 
 * @param  string        $command
 * @return Promise<void>
 */
function executeEverywhereParallel(string $command, string $except = ''):Promise {
    return call(function() use ($command, $except) {
        /** @var array */
        $projects = $_ENV['projects'] ?? [];
        /** @var string */
        $master = $_ENV['master'] ?? '';

        $except = preg_split('/,+/', $except);

        foreach ($projects as $projectName => $_) {
            if ($except && in_array($projectName, $except)) {
                continue;
            }
            call(function() use ($projectName, $command, $master) {
                $cwd    = "$projectName";
                $output = yield execute($command, $cwd);
                if ($projectName === $master) {
                    echo "Executing \"$command\" in $projectName (master)".PHP_EOL;
                } else {
                    echo "Executing \"$command\" in $projectName".PHP_EOL;
                }
                echo $output.PHP_EOL;
            });
        }
    });
}
