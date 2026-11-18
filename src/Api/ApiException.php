<?php

declare(strict_types=1);

namespace Koertho\ContaoChurchTools\Api;

/** Only fixed messages and HTTP status codes; never chain transport exceptions. */
final class ApiException extends \RuntimeException
{
}
