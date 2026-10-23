<?php

namespace Tests\Support;

use Closure;
use Illuminate\Contracts\Filesystem\Filesystem;

/**
 * Обёртка над реальным диском для тестов: перехватывает запись и чтение,
 * чтобы сымитировать конкурентную смену аватара или повреждение
 * проверяемого содержимого. С реальным S3 не используется.
 */
final class InterceptingFilesystem implements Filesystem
{
    /** @var ?Closure(string, mixed): void */
    public ?Closure $beforePut = null;

    /** @var ?Closure(string): string */
    public ?Closure $tamperRead = null;

    public function __construct(private Filesystem $inner) {}

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
        $value = $this->inner->get($path);

        if ($this->tamperRead !== null && is_string($value)) {
            return ($this->tamperRead)($value);
        }

        return $value;
    }

    public function readStream($path)
    {
        return $this->inner->readStream($path);
    }

    public function put($path, $contents, $options = [])
    {
        if ($this->beforePut !== null) {
            ($this->beforePut)($path, $contents);
        }

        return $this->inner->put($path, $contents, $options);
    }

    public function putFile($path, $file = null, $options = [])
    {
        return $this->inner->putFile($path, $file, $options);
    }

    public function putFileAs($path, $file, $name = null, $options = [])
    {
        return $this->inner->putFileAs($path, $file, $name, $options);
    }

    public function writeStream($path, $resource, array $options = [])
    {
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
        return $this->inner->deleteDirectory($directory);
    }
}
