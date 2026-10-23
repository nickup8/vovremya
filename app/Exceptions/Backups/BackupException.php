<?php

namespace App\Exceptions\Backups;

use RuntimeException;

/**
 * Базовое исключение семантических ошибок резервирования.
 * Сообщения пишутся вручную и не должны содержать секретов.
 */
class BackupException extends RuntimeException {}
