<?php

declare(strict_types=1);

namespace Ddrv\Flysystem\MacroSd\Exception;

use Exception;
use League\Flysystem\FilesystemException;

final class UnknownFilesystemException extends Exception implements FilesystemException
{
}
