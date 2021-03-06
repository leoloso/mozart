<?php

namespace CoenJacobs\Mozart;

use CoenJacobs\Mozart\Composer\Autoload\Classmap;
use CoenJacobs\Mozart\Composer\Autoload\NamespaceAutoloader;
use CoenJacobs\Mozart\Composer\Package;
use League\Flysystem\Adapter\Local;
use League\Flysystem\Filesystem;
use Symfony\Component\Finder\Finder;

class Mover
{
    /** @var string */
    protected $workingDir;

    /** @var string */
    protected $targetDir;

    /** @var \stdClass */
    protected $config;

    /** @var Filesystem */
    protected $filesystem;

    /** @var array */
    protected $movedPackages = [];

    public function __construct($workingDir, $config)
    {
        $this->workingDir = $workingDir;
        $this->targetDir = $config->dep_directory;
        $this->config = $config;

        $this->filesystem = new Filesystem(new Local($this->workingDir));
    }

    public function deleteTargetDirs()
    {
        $this->filesystem->deleteDir($this->config->dep_directory);
        $this->filesystem->createDir($this->config->dep_directory);
        $this->filesystem->deleteDir($this->config->classmap_directory);
        $this->filesystem->createDir($this->config->classmap_directory);
    }

    public function movePackage(Package $package)
    {
        if (in_array($package->config->name, $this->movedPackages)) {
            return;
        }

        foreach ($package->autoloaders as $autoloader) {
            if ($autoloader instanceof NamespaceAutoloader) {
                $finder = new Finder();

                foreach ($autoloader->paths as $path) {
                    $source_path = $this->workingDir . '/vendor/' . $package->config->name . '/' . $path;

                    $finder->files()->in($source_path);

                    foreach ($finder as $file) {
                        $this->moveFile($package, $autoloader, $file, $path);
                    }
                }
            } elseif ($autoloader instanceof Classmap) {
                $finder = new Finder();

                foreach ($autoloader->files as $file) {
                    $source_path = $this->workingDir . '/vendor/' . $package->config->name;
                    $finder->files()->name($file)->in($source_path);

                    foreach ($finder as $foundFile) {
                        $this->moveFile($package, $autoloader, $foundFile);
                    }
                }

                $finder = new Finder();

                foreach ($autoloader->paths as $path) {
                    $source_path = $this->workingDir . '/vendor/' . $package->config->name . '/' . $path;

                    $finder->files()->in($source_path);

                    foreach ($finder as $file) {
                        $this->moveFile($package, $autoloader, $file);
                    }
                }
            }

            $this->movedPackages[] = $package->config->name;
        }

        $this->deletePackageVendorDirectories();
    }

    /**
     * @param Package $package
     * @param $autoloader
     * @param $file
     * @param $path
     * @return mixed
     */
    public function moveFile(Package $package, $autoloader, $file, $path = '')
    {
        if ($autoloader instanceof NamespaceAutoloader) {
            $namespacePath = $autoloader->getNamespacePath();
            $replaceWith = $this->config->dep_directory . $namespacePath;
            $targetFile = str_replace($this->workingDir, $replaceWith, $file->getRealPath());

            $packageVendorPath = '/vendor/' . $package->config->name . '/' . $path;
            $packageVendorPath = str_replace('/', DIRECTORY_SEPARATOR, $packageVendorPath);
            $targetFile = str_replace($packageVendorPath, '', $targetFile);
        } else {
            $namespacePath = $package->config->name;
            $replaceWith = $this->config->classmap_directory . '/' . $namespacePath;
            $targetFile = str_replace($this->workingDir, $replaceWith, $file->getRealPath());

            $packageVendorPath = '/vendor/' . $package->config->name . '/';
            $packageVendorPath = str_replace('/', DIRECTORY_SEPARATOR, $packageVendorPath);
            $targetFile = str_replace($packageVendorPath, DIRECTORY_SEPARATOR, $targetFile);
        }

        $this->filesystem->copy(
            str_replace($this->workingDir, '', $file->getRealPath()),
            $targetFile
        );

        return $targetFile;
    }

    /**
     * Deletes all the packages that are moved from the /vendor/ directory to
     * prevent packages that are prefixed/namespaced from being used or
     * influencing the output of the code. They just need to be gone.
     */
    protected function deletePackageVendorDirectories()
    {
        foreach ($this->movedPackages as $movedPackage) {
            $packageDir = '/vendor/' . $movedPackage;
            $this->filesystem->deleteDir($packageDir);
        }
    }
}
