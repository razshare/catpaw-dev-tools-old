<?php

use function Amp\call;
use function Amp\File\createDirectoryRecursively;
use function Amp\File\isDirectory;
use function Amp\File\isFile;
use function Amp\File\read;
use function Amp\File\write;
use Amp\Promise;

use function CatPaw\deleteDirectoryRecursively;
use function CatPaw\flatten;

use function CatPaw\listFilesRecursively;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Match_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Label;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Use_;
use PhpParser\ParserFactory;

/**
 * @param  string           $fileName
 * @param  string           $nameSpace
 * @param  Match_           $match
 * @throws Error
 * @return DefinitionWalker
 */
function walk(
    string $fileName,
    string $nameSpace,
    Match_ $match
):DefinitionWalker {
    $line = $match->getAttribute("startLine");
    if (!$match instanceof \PhpParser\Node\Expr\Match_) {
        throw new Error("Definition must be an expression for $fileName:$line");
    }
    /** @var \PhpParser\Node\Expr\ClassConstFetch */
    $condition = $match->cond;
    /** @var string */
    $className = (string)$condition->class;

    $props = [];

    foreach ($match->arms as $arm) {
        if (($count = count($arm->conds)) > 1 || 0 === $count) {
            $line = $arm->getAttribute("startLine");
            throw new Error("Each match arm in $fileName:$line must contain exactly one single variable name to the left side of the arrow.");
        }
        
        $condition = $arm->conds[0];

        if (!$condition instanceof Variable) {
            throw new Error("Left side definition of property in $fileName:$line must be a \$variable name.");
        }

        $name = (string)$condition->name;

        $body = $arm->body;
        
        if (
            !$body instanceof ClassConstFetch
            && ! $body instanceof Match_
            && ! $body instanceof Array_
        ) {
            throw new Error("Right side definition of property in $fileName:$line must contain a class::name, match expression or an array (of 1 element) of either one of the two.");
        }

        if ($body instanceof ClassConstFetch) {
            $props[$name] = new DefinitionProperty(
                isArray: false,
                definitionWalker: using((string)$body->class),
            );
        } else if ($body instanceof Match_) {
            $props[$name] = new DefinitionProperty(
                isArray: false,
                definitionWalker: walk($fileName, $nameSpace, $body),
            );
        } else if ($body instanceof Array_) {
            if (($count = count($body->items) > 1) || $count < 0) {
                throw new Error("Right side definition of property in $fileName:$line must contain an array of exactly one element, either a class::name or  a match expression.");
            }

            $item = $body->items[0]->value;

            if ($item instanceof ClassConstFetch) {
                $props[$name] = new DefinitionProperty(
                    isArray: true,
                    definitionWalker: using((string)$item->class),
                );
            } else if ($item instanceof Match_) {
                $props[$name] = new DefinitionProperty(
                    isArray: true,
                    definitionWalker: walk($fileName, $nameSpace, $item),
                );
            }
        }
    }

    return new DefinitionWalker(
        nameSpace: $nameSpace,
        className: $className,
        props: $props,
    );
}

/**
 * @param  string           $fileName
 * @param  string           $nameSpace
 * @param  Label            $label
 * @param  Match_           $match
 * @throws Error
 * @return DefinitionWalker
 */
function matcher(
    string $fileName,
    string $nameSpace,
    Label $label,
    Match_ $match,
):DefinitionWalker {
    return walk($fileName, $nameSpace, $match);
    // return match ($label->name) {
    //     'T'     => walk($fileName, $nameSpace, $match),
    //     default => false,
    // };
}

function using(string $className, string $fullClassName = ''):string {
    static $imports = [];

    if (!$fullClassName) {
        $fullClassName = $className;
    }

    if (isset($imports[$className])) {
        return $imports[$className];
    }
    return $imports[$className] = $fullClassName;
}

/**
 * @param  array<Stmt>             $ast
 * @return array<DefinitionWalker>
 */
function mirror(
    string $fileName,
    array $ast,
    string $nameSpace = ''
):array {
    $label      = false;
    $expression = false;
    
    $definitions = [];

    foreach ($ast as $node) {
        $line = $node->getAttribute("startLine");
        if ($node instanceof Namespace_) {
            $definitions[] = mirror($fileName, $ast, (string)$node->name);
        } else if ($node instanceof Label) {
            if ($label) {
                throw new Error("Cannot declare two labels in a row in $fileName:$line, each label must be followed by a match expression.");
            }
            $label = $node;
        } else if ($node instanceof Expression) {
            if (!$label) {
                throw new Error("The match expression in $fileName:$line must be preceeded by a label, typically named \"T\".");
            }
            $expression = $node;
        } else if ($node instanceof Use_) {
            foreach ($node->uses as $use) {
                $fullClassName = "\\$use->name";
                $classname     = $use->name->parts[count($use->name->parts) - 1];
                using($classname, $fullClassName);
            }
            $label      = false;
            $expression = false;
        } else {
            $label      = false;
            $expression = false;
        }
        if ($label && $expression) {
            $definitions[] = matcher($fileName, $nameSpace, $label, $expression->expr);
            $label         = false;
            $expression    = false;
        }
    }

    return $definitions;
}

/**
 * @param  string                  $fileName
 * @param  string                  $code
 * @throws LogicException
 * @throws Error
 * @return array<DefinitionWalker>
 */
function parse(string $fileName, string $code) {
    static $parser = null;

    if (!$parser) {
        $parser = (new ParserFactory)->create(ParserFactory::PREFER_PHP7);
    }

    $ast        = $parser->parse($code);
    $nameSpaces = array_filter($ast, fn (Stmt $node) => $node instanceof Namespace_);
    $count      = count($nameSpaces);
    if (0 === $count) {
        return [mirror($fileName, $ast)];
    } else if (1 === $count) {
        /** @var Namespace_*/
        $nameSpace = $nameSpaces[0];
        $ast       = $nameSpace->stmts;
        return [mirror($fileName, $ast, (string)$nameSpace->name)];
    } else if ($count > 1) {
        $definitions = [];
        /** @var array<Namespace_> $nameSpaces*/
        foreach ($nameSpaces as $nameSpace) {
            $ast           = $nameSpace->stmts;
            $definitions[] = mirror($fileName, $ast, (string)$nameSpace->name);
        }
        return $definitions;
    }

    return [];
}

/**
 * @param  string                           $path
 * @return Promise<array<DefinitionWalker>>
 */
function load(string $path):Promise {
    return call(function() use ($path) {
        $list = [];
        if (yield isDirectory($path)) {
            /** @var array<array<string,mixed>> $file */
            foreach ((yield listFilesRecursively($path)) as $fileName) {
                if (!str_ends_with($fileName, '.d.php')) {
                    continue;
                }
                $code = yield read($fileName);
                $list = [...$list,...parse($path, $code)];
            }
        } else if (yield isFile($path)) {
            $code = yield read($path);
            $list = [...$list,...parse($path, $code)];
        }
        return flatten($list);
    });
}

function extractClasses(DefinitionWalker $walker) {
}

function createClassFromDefinitionWalker(
    string $nameSpacePrefix,
    string $path,
    DefinitionWalker $walker,
    callable $onWalkerDetected,
):WritableClass {
    $nameSpace = $walker->nameSpace;
    $className = $walker->className;
    $props     = [];
    $docs      = [];
    /** @var DefinitionProperty $prop */
    foreach ($walker->props as $name => $prop) {
        $isArray = $prop->isArray;
        $walker  = $prop->definitionWalker;

        if (is_string($walker)) {
            if ($isArray) {
                $docs[] = <<<PHP
                    /** @param array<$walker> \$$name*/
                    PHP;
                $props[] = <<<PHP
                    public array \$$name,
                    PHP;
            } else {
                $docs[] = <<<PHP
                    /** @param $walker \$$name*/
                    PHP;
                $props[] = "public $walker \$$name,";
            }
        } else {
            $onWalkerDetected($walker);

            if ($isArray) {
                if ($walker->nameSpace) {
                    $docs[] = <<<PHP
                        /** @param array<\\{$walker->nameSpace}\\{$walker->className}> \$$name*/
                        PHP;
                    $props[] = <<<PHP
                        public array \$$name,
                        PHP;
                } else {
                    $docs[] = <<<PHP
                        /** @param array<\\{$walker->className}> \$$name*/
                        PHP;
                    $props[] = <<<PHP
                        public array \$$name,
                        PHP;
                }
            } else {
                if ($walker->nameSpace) {
                    $docs[] = <<<PHP
                        /** @param \\{$walker->nameSpace}\\{$walker->className} \$$name*/
                        PHP;
                    $props[] = "public \\{$walker->nameSpace}\\{$walker->className} $name,";
                } else {
                    $docs[] = <<<PHP
                        /** @param \\{$walker->className} \$$name*/
                        PHP;
                    $props[] = "public \\{$walker->className} \$$name,";
                }
            }
        }
    }

    $prefixedNameSpace = trim($nameSpacePrefix.$nameSpace);
    $prefixedNameSpace = preg_replace('/\\\\+$/', '', $prefixedNameSpace);
    $prefixedNameSpace = preg_replace('/^\\\\+/', '', $prefixedNameSpace);

    $nameSpaceDfinition   = $prefixedNameSpace?"namespace $prefixedNameSpace;":'';
    $propertiesDefinition = join("\n", $props);
    $docsDefinition       = join("\n", $docs);

    $absoluteDirectoryName = join('/', array_slice($pieces = explode('/', $path), 0, ($count = count($pieces) - 1)));
    $relativeFileName      = $pieces[$count];

    return new WritableClass(
        absoluteDirectoryName:$absoluteDirectoryName,
        relativeFileName:$relativeFileName,
        code: <<<PHP
            <?php
            $nameSpaceDfinition
            class $className {
                $docsDefinition
                public function __construct(
                    $propertiesDefinition
                ){}
            }
            PHP
    );
}

/**
 * @param  DefinitionWalker     $walker
 * @return array<WritableClass>
 */
function stringify(
    string $nameSpacePrefix,
    string $path,
    DefinitionWalker $walker
):array {
    $classes   = [];
    $classes[] = createClassFromDefinitionWalker(
        nameSpacePrefix: $nameSpacePrefix,
        path: $path,
        walker: $walker,
        onWalkerDetected: function(DefinitionWalker $walker) use (&$classes, $nameSpacePrefix) {
            $path    = preg_replace('/^\/+/', '', str_replace('\\', '/', "$walker->nameSpace\\$walker->className"));
            $classes = [...$classes,...stringify($nameSpacePrefix, $path, $walker)];
        },
    );
    return $classes;
}

function dumpDefinitions(
    string $from,
    string $to,
    bool $clear,
    string $nameSpacePrefix,
) {
    return call(function() use ($from, $to, $clear, $nameSpacePrefix) {
        $nameSpacePrefix = trim($nameSpacePrefix);

        if ('\\' === $nameSpacePrefix) {
            $nameSpacePrefix = '';
        }

        if ($nameSpacePrefix && !str_ends_with($nameSpacePrefix, '\\')) {
            $nameSpacePrefix = "$nameSpacePrefix\\";
        }

        if ($clear && yield isDirectory($to)) {
            yield deleteDirectoryRecursively($to);
            yield createDirectoryRecursively($to);
        }

        if (!yield isDirectory($to)) {
            yield createDirectoryRecursively($to);
        }

        $from = realpath($from);
        $to   = realpath($to);
        /** @var array<DefinitionWalker> */
        $walkers = yield load($from);
        /** @var array<WritableClass> */
        $classes = [];
        foreach ($walkers as $walker) {
            $path    = preg_replace('/^\/+/', '', str_replace('\\', '/', "$walker->nameSpace\\$walker->className"));
            $classes = [...$classes, ...stringify($nameSpacePrefix, $path, $walker)];
        }
        

        foreach ($classes as $class) {
            if (!yield isDirectory("$to/$class->absoluteDirectoryName")) {
                yield createDirectoryRecursively("$to/$class->absoluteDirectoryName");
            }
            yield write("$to/$class->absoluteDirectoryName/$class->relativeFileName.php", $class->code);
        }
    });
}