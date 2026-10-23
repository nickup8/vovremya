<?php

namespace Tests\Support\Backups;

use Illuminate\Contracts\Filesystem\Filesystem;
use RuntimeException;

/**
 * Заглушка S3-диска для тестов: делегирует на фейковый диск Storage::fake,
 * позволяет сымитировать сбой записи/чтения/удаления (исключение или
 * false) и подменить содержимое при проверочном чтении.
 * Не final: тесты наследуют анонимными классами для точечных сбоев.
 */
class BackupDiskStub implements Filesystem
{
    public bool $failWrites = false;

    public bool $failDeletes = false;

    public bool $deleteReturnsFalse = false;

    public bool $tamperReads = false;

    /** @var list<string> */
    public array $deleted = [];

    public function __construct(private readonly Filesystem $inner) {}

    public function path($path)
    {
        return $this->inner->path($path);
    }

    public function exists($path)
    {
        return $this->inner->exists($path);
    }

    public function get($path)
    {
        return $this->inner->get($path);
    }

    public function readStream($path)
    {
        $stream = $this->inner->readStream($path);

        if ($this->tamperReads && is_resource($stream)) {
            fclose($stream);

            $replacement = fopen('php://temp', 'r+');
            fwrite($replacement, 'tampered-different-content');
            rewind($replacement);

            return $replacement;
        }

        return $stream;
    }

    public function put($path, $contents, $options = [])
    {
        if ($this->failWrites) {
            throw new RuntimeException('S3 write failed (test stub)');
        }

        return $this->inner->put($path, $contents, $options);
    }

    public function putFile($path, $file = null, $options = [])
    {
        if ($this->failWrites) {
            throw new RuntimeException('S3 write failed (test stub)');
        }

        return $this->inner->putFile($path, $file, $options);
    }

    public function putFileAs($path, $file, $name = null, $options = [])
    {
        if ($this->failWrites) {
            throw new RuntimeException('S3 write failed (test stub)');
        }

        return $this->inner->putFileAs($path, $file, $name, $options);
    }

    public function writeStream($path, $resource, array $options = [])
    {
        if ($this->failWrites) {
            throw new RuntimeException('S3 write failed (test stub)');
        }

        return $this->inner->writeStream($path, $resource, $options);
    }

    public function getVisibility($path)
    {
        return $this->inner->getVisibility($path);
    }

    public function setVisibility($path, $visibility)
    {
        return $this->inner->setVisibility($path, $visibility);
    }

    public function prepend($path, $data)
    {
        return $this->inner->prepend($path, $data);
    }

    public function append($path, $data)
    {
        return $this->inner->append($path, $data);
    }

    public function delete($paths)
    {
        foreach ((array) $paths as $path) {
            $this->deleted[] = (string) $path;
        }

        if ($this->failDeletes) {
            throw new RuntimeException('S3 delete failed (test stub)');
        }

        if ($this->deleteReturnsFalse) {
            return false;
        }

        return $this->inner->delete($paths);
    }

    public function copy($from, $to)
    {
        return $this->inner->copy($from, $to);
    }

    public function move($from, $to)
    {
        return $this->inner->move($from, $to);
    }

    public function size($path)
    {
        return $this->inner->size($path);
    }

    public function lastModified($path)
    {
        return $this->inner->lastModified($path);
    }

    public function files($directory = null, $recursive = false)
    {
        return $this->inner->files($directory, $recursive);
    }

    public function allFiles($directory = null)
    {
        return $this->inner->allFiles($directory);
    }

    public function directories($directory = null, $recursive = false)
    {
        return $this->inner->directories($directory, $recursive);
    }

    public function allDirectories($directory = null)
    {
        return $this->inner->allDirectories($directory);
    }

    public function makeDirectory($path)
    {
        return $this->inner->makeDirectory($path);
    }

    public function deleteDirectory($directory)
    {
        if ($this->failDeletes) {
            throw new RuntimeException('S3 delete failed (test stub)');
        }

        return $this->inner->deleteDirectory($directory);
    }
}
