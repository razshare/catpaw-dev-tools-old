<?php

T:match (User::class) {
    $picture => match (Picture::class) {
        $contents => string::class,
        $size     => match (Rectangle::class) {
            $width  => int::class,
            $height => int::class,
        },
    }
};