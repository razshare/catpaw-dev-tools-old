<?php

class WritableClass {
    public function __construct(
        public string $absoluteDirectoryName,
        public string $relativeFileName,
        public string $code,
    ) {
    }
}