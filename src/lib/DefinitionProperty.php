<?php

class DefinitionProperty {
    public function __construct(
        public bool $isArray,
        public DefinitionWalker|string $definitionWalker,
    ) {
    }
}