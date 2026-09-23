<?php

declare(strict_types=1);

namespace Koertho\ChurchToolsBundle\Api;

/** Only fixed messages and HTTP status codes; never chain transport exceptions. */
final class ApiException extends \RuntimeException
{
}
