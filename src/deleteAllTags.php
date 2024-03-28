<?php
use function Amp\call;
use Amp\Promise;
use function CatPaw\execute;

/**
 * @return Promise<void>
 */
function deleteAllTags(string $project = ''):Promise {
    return call(function() use ($project) {
        /** @var array */
        $projects = $_ENV['projects'] ?? [];
        /** @var string */
        $master = $_ENV['master'] ?? '';

        $cwd = "$master";
        echo "Deleting tags of project $master".PHP_EOL;

        #Delete local tags.
        echo yield execute("git tag -l | xargs git tag -d", $cwd);
        #Fetch remote tags.
        echo yield execute("git fetch", $cwd);
        #Delete remote tags.
        echo yield execute("git tag -l | xargs git push --delete origin", $cwd);
        #Delete local tags.
        echo yield execute("git tag -l | xargs git tag -d", $cwd);

        foreach ($projects as $projectName => $_) {
            if ('' !== $project && $project !== $projectName) {
                continue;
            }
            echo "Tagging project $projectName".PHP_EOL;
            $cwd = "$projectName";
            
            #Work in parallel on each project to speed things up
            call(function() use ($cwd) {
                #Delete local tags.
                echo yield execute("git tag -l | xargs git tag -d", $cwd);
                #Fetch remote tags.
                echo yield execute("git fetch", $cwd);
                #Delete remote tags.
                echo yield execute("git tag -l | xargs git push --delete origin", $cwd);
                #Delete local tags.
                echo yield execute("git tag -l | xargs git tag -d", $cwd);
            });
        }
    });
}
