<?php

class Picture {
    /** @param string $contents*/
    /** @param \Rectangle $size*/
    public function __construct(
        public string $contents,
        public \Rectangle $size,
    ) {
    }
}