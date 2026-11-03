<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
$sourcePath = $projectRoot.'/database/schema/mariadb-schema.sql';
$schemaDirectory = $projectRoot.'/storage/phpstan/schema';
$schemaPath = $schemaDirectory.'/mariadb-schema.sql';

$schema = file_get_contents($sourcePath);

if ($schema === false) {
    throw new RuntimeException(sprintf('Unable to read MariaDB schema snapshot at %s.', $sourcePath));
}

$schema = preg_replace('~/\*.*?\*/~s', '', $schema);

if ($schema === null) {
    throw new RuntimeException('Unable to remove MariaDB dump comments for PHPStan schema parsing.');
}

$schema = preg_replace('/\buuid\b/i', 'char(36)', $schema);

if ($schema === null) {
    throw new RuntimeException('Unable to normalize UUID columns for PHPStan schema parsing.');
}

if (! is_dir($schemaDirectory) && ! mkdir($schemaDirectory, 0775, true) && ! is_dir($schemaDirectory)) {
    throw new RuntimeException(sprintf('Unable to create PHPStan schema directory at %s.', $schemaDirectory));
}

$temporaryPath = $schemaPath.'.'.getmypid().'.tmp';

if (file_put_contents($temporaryPath, $schema, LOCK_EX) === false) {
    throw new RuntimeException(sprintf('Unable to write generated PHPStan schema at %s.', $temporaryPath));
}

if (! rename($temporaryPath, $schemaPath)) {
    unlink($temporaryPath);

    throw new RuntimeException(sprintf('Unable to replace generated PHPStan schema at %s.', $schemaPath));
}
