<?php
declare(strict_types=1);

namespace MarcAndreAppel\Backup;

use AFM\Rsync\Rsync;
use FilesystemIterator;
use MarcAndreAppel\Backup\Exceptions\ZipCommandFailed;
use MarcAndreAppel\Backup\Exceptions\ZipExecutableNotFound;
use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Spatie\TemporaryDirectory\Exceptions\PathAlreadyExists;
use Spatie\TemporaryDirectory\TemporaryDirectory;

class Backup
{
    private Filesystem $destinationDisk;
    private string $timestamp;
    private TemporaryDirectory $temporaryDirectory;
    public string $backupName;

    protected string $zipCommand = "/usr/bin/zip";

    /**
     * @throws PathAlreadyExists
     * @throws ZipExecutableNotFound
     */
    public function __construct()
    {
        $this->checkRequirements();

        $this->destinationDisk = app(Factory::class)->disk(config('backup.destination_disk'));
        if (!$this->destinationDisk->exists(config('backup.backup_name'))) {
            $this->destinationDisk->makeDirectory(config('backup.backup_name'));
        }
        $this->timestamp  = Carbon::now()->format('YmdHis');
        $this->backupName = 'backup_'.$this->timestamp;

        $this->temporaryDirectory = (new TemporaryDirectory(storage_path('app/backup-temp')))
            ->name($this->backupName)
            ->force()
            ->create()
            ->empty();
    }

    /**
     * @throws ZipCommandFailed
     */
    public static function run()
    {
        $instance = new static();
        $instance
            ->cleanBackups()
            ->syncFilesystem()
            ->createArchive()
            ->uploadBackup()
            ->cleanUp();
    }

    private function syncFilesystem(): self
    {
        $rsync = new Rsync();

        $rsync->setExclude(config('backup.exclude', []));
        $rsync->sync(base_path(), $this->temporaryDirectory->path('content'));

        return $this;
    }

    /**
     * @throws ZipCommandFailed
     */
    private function createArchive(): self
    {
        $currentDirectory = getcwd();
        chdir($this->temporaryDirectory->path());

        $archivePath = $this->temporaryDirectory->path().DIRECTORY_SEPARATOR.$this->timestamp.'.zip';
        $command     = "$this->zipCommand -qrs 750m $archivePath content";

        if (shell_exec($command) === null) {
            $this->deleteDirectory($this->temporaryDirectory->path('content'));
            chdir($currentDirectory);

            return $this;
        } else {
            throw new ZipCommandFailed();
        }
    }

    private function uploadBackup(): self
    {
        $path = config('backup.backup_name').DIRECTORY_SEPARATOR.$this->backupName;
        $this->destinationDisk->makeDirectory($path);

        foreach ((new RecursiveDirectoryIterator($this->temporaryDirectory->path(), FilesystemIterator::SKIP_DOTS)) as $file) {
            $this->destinationDisk->put($path.DIRECTORY_SEPARATOR.$file->getFilename(), file_get_contents($file->getRealPath()));
        }

        return $this;
    }

    private function cleanBackups(): self
    {
        $directories = collect($this->destinationDisk->allDirectories(config('backup.backup_name')))
            ->sortByDesc(function ($dirname) {
                return $dirname;
            });
        if ($directories->count() > 4) {
            $obsolete = $directories->last();
            $this->destinationDisk->deleteDirectory($obsolete);
        }

        return $this;
    }

    private function deleteDirectory(string $contentDirectory): void
    {
        $directoryIterator = new RecursiveDirectoryIterator($contentDirectory, FilesystemIterator::SKIP_DOTS);
        $iteratorIterator  = new RecursiveIteratorIterator($directoryIterator, RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iteratorIterator as $file) {
            if ($file->isDir()) {
                rmdir($file->getRealPath());
            } else {
                unlink($file->getRealPath());
            }
        }
        rmdir($contentDirectory);
    }

    private function cleanUp()
    {
        $this->deleteDirectory($this->temporaryDirectory->path());
    }

    /**
     * @throws ZipExecutableNotFound
     */
    private function checkRequirements()
    {
        if (!is_executable($this->zipCommand)) {
            throw new ZipExecutableNotFound();
        }
        if (empty(config('backup.backup_name')) || empty(config('backup.destination_disk'))) {
            throw new InvalidArgumentException();
        }
    }
}
