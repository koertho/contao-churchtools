<?php

declare(strict_types=1);

namespace Koertho\ContaoChurchTools\Calendar\Image;

interface CachedImageStoreInterface
{
    /** @param array{id: int, url: string} $image */
    public function obtain(array $image): string;
}
