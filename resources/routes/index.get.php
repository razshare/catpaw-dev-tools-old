<?php

use Amp\Http\Server\Response;
use Amp\Http\Status;

return function(
    #[\CatPaw\Web\Attributes\Query] string $username = '',
) {
    static $connection = new \PDO(
        'mysql:host=localhost;dbname=db1',
        'root',
        'root'
    );
    
    $statement = $connection->prepare("set @username = :username;\n\n\nselect * from Log;\n");
    if ($statement->execute(["username" => $username])) {
        return new Response(Status::OK, $statement->fetchAll());
    }
    return new Response(Status::INTERNAL_SERVER_ERROR);
};