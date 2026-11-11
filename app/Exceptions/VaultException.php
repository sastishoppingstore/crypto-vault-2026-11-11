<?php

namespace App\Exceptions;

use RuntimeException;

/** Generic domain/configuration error (rendered as HTTP 422 by the API). */
class VaultException extends RuntimeException
{
}
