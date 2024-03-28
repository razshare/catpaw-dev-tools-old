<?php

class DefinitionWalker {
    public function __construct(
        public string $nameSpace,
        public string $className,
        /** @var array<DefinitionProperty> */
        public array $props,
    ) {
    }
}