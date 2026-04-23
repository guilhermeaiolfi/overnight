<?php

declare(strict_types=1);

namespace ON\Config\Scanner\Exception;

use Exception;

/**
 * FileNotFoundException.
 * @author Riikka Kalliomäki <riikka.kalliomaki@gmail.com>
 * @copyright Copyright (c) 2019 Riikka Kalliomäki
 * @license http://opensource.org/licenses/mit-license.php MIT License
 */
class FileNotFoundException extends Exception implements ClassScannerException
{
}
