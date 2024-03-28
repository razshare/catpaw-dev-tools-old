<?php

use Amp\Http\Server\Response;
use Amp\Http\Status;

return function(/** @args */) {
    static $connection = new \PDO(
        'mysql:host=localhost;dbname=db1',
        'root',
        'root'
    );
    
    $statement = $connection->prepare("/** @query */");
    if ($statement->execute(/** @inject */)) {
        return new Response(Status::OK, $statement->fetchAll());
    }
    return new Response(Status::INTERNAL_SERVER_ERROR);
};