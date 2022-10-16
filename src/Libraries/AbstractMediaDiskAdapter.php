<?php

namespace AttractCores\LaravelCoreMedia\Libraries;

use AttractCores\LaravelCoreMedia\Contracts\MediaDiskContract;
use AttractCores\LaravelCoreMedia\MediaStorage;
use GuzzleHttp\Psr7\Query;
use Illuminate\Contracts\Filesystem\FileExistsException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Symfony\Component\ErrorHandler\Error\FatalError;

/**
 * Class AbstractMediaDiskAdapter
 *
 * @package AttractCores\LaravelCoreMedia\Libraries
 * Date: 20.01.2021
 * Version: 1.0
 * Author: Yure Nery <yurenery@gmail.com>
 */
abstract class AbstractMediaDiskAdapter implements MediaDiskContract
{
    /**
     * Driver config.
     *
     * @var array
     */
    protected array $config;

    /**
     * @var \Illuminate\Contracts\Filesystem\Filesystem
     */
    protected Filesystem $storage;


    /**
     * S3MediaDisk constructor.
     *
     * @param array      $config
     * @param Filesystem $storage
     */
    public function __construct(Filesystem $storage, array $config)
    {
        $this->config = $config;

        $this->storage = $storage;
    }

    /**
     * Calls forwarding.
     *
     * @param       $method
     * @param mixed $arguments
     *
     * @return mixed
     */
    public function __call($method, $arguments)
    {
        return $this->storage->$method(...$arguments);
    }

    /**
     * Determine visibility of adapter files.
     *
     * @return bool
     */
    public function visibility() : bool
    {
        return $this->getConfigValue('visibility', 'private') == 'public';
    }

    /**
     * Return temporary url for given path.
     *
     * @param       $path
     * @param       $expiration
     * @param array $options
     *
     * @return string
     */
    public function temporaryUrl($path, $expiration, $options = []) : string
    {
        try {
            return $this->storage->temporaryUrl($path, $expiration, $options);
        } catch ( \Throwable $e ) {
            return $path;
        }
    }

    /**
     * Return temporary relative path for given path.
     *
     * @param       $path
     * @param       $expiration
     * @param array $options
     *
     * @return string
     */
    public function temporaryPath($path, $expiration, $options = []) : string
    {
        $url = $this->temporaryUrl($path, $expiration, $options);

        if ( Str::contains($url, '?') ) {
            return sprintf('%s?%s', $path, Query::build(
                Query::parse(
                    explode('?', $url)[ 1 ]
                )
            ));
        }

        return $path;
    }

    /**
     * Return current storage instance.
     *
     * @return \Illuminate\Contracts\Filesystem\Filesystem
     */
    public function storage()
    {
        return $this->storage;
    }

    /**
     * Delete file by path.
     *
     * @param string $path
     *
     * @return bool
     */
    public function delete(string $path) : bool
    {
        if ( $this->storage->exists($path) ) {
            return $this->storage->delete($path);
        }

        return true;
    }

    /**
     * Delete directory by path.
     *
     * @param string $path
     *
     * @return bool
     */
    public function deleteDirectory(string $path) : bool
    {
        if ( $this->storage->exists($path) ) {
            return $this->storage->deleteDirectory($path);
        }

        return true;
    }

    /**
     * Move given file inside one disk.
     *
     * @param string      $pathFrom
     * @param string      $pathTo
     * @param string|null $toDisk
     *
     * @return bool
     * @throws \Illuminate\Contracts\Filesystem\FileExistsException|\Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    public function move(string $pathFrom, string $pathTo, string $toDisk = NULL) : bool
    {
        if ( ! $toDisk ) {
            //Remove $pathTo
            $this->delete($pathTo);

            $result = $this->storage->move($pathFrom, $pathTo);
            $this->storage->setVisibility($pathTo, $this->getConfigValue('visibility', 'private'));
        } else { // Move cross disks.
            $result = $this->moveThroughDisks($toDisk, $pathFrom, $pathTo);
        }

        if ( $result ) {
            $storage = $toDisk ? MediaStorage::adapterByDisk($toDisk) : $this->storage;
            $storage->setVisibility($pathTo, $this->getConfigValue('visibility', 'private'));
        }

        return $result;
    }

    /**
     * Move file from one disk to current.
     *
     * @param string $toDisk
     * @param string $pathOnFromDisk
     * @param string $pathToOnCurrentDisk
     *
     * @return bool
     * @throws \Illuminate\Contracts\Filesystem\FileExistsException|\Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    protected function moveThroughDisks(string $toDisk, string $pathOnFromDisk, string $pathToOnCurrentDisk) : bool
    {
        $toStorage = MediaStorage::adapterByDisk($toDisk);

        if ( $this->storage->exists($pathOnFromDisk) ) {
            $mime = $this->storage->getMimetype($pathOnFromDisk);
            if ( preg_match('/^image\/.*/', $mime) || app()->runningInConsole() ) {
                // Delete $pathToOnCurrentDisk
                $toStorage->delete($pathToOnCurrentDisk);

                return $toStorage->writeStream($pathToOnCurrentDisk, $this->storage->readStream($pathOnFromDisk));
            } else {
                throw new FileExistsException(__("Given file isn't an image or you are trying to move files cross disks in sync request."), 500);
            }
        } else {
            throw new FileExistsException(__("Given file doesn't exist."), 500);
        }
    }

    /**
     * Return config value.
     *
     * @param      $name
     * @param null $default
     *
     * @return array|\ArrayAccess|mixed
     */
    protected function getConfigValue($name, $default = NULL)
    {
        return Arr::get($this->config, $name, $default);
    }

}