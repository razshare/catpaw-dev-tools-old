<?php

class User {
    /** @param \Picture $picture*/
    public function __construct(
        public \Picture $picture,
    ) {
    }
}