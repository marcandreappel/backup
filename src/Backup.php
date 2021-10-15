<?php
declare(strict_types=1);

namespace MarcAndreAppel\Backup;

use FilesystemIterator;
use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use InvalidArgumentException;
use MarcAndreAppel\Backup\Exceptions\ZipCommandFailed;
use MarcAndreAppel\Backup\Exceptions\ZipExecutableNotFound;
use MarcAndreAppel\Backup\Tasks\DbDumperFactory;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Spatie\DbDumper\Compressors\GzipCompressor;
use Spatie\DbDumper\Databases\Sqlite;
use Spatie\DbDumper\DbDumper;
use Spatie\TemporaryDirectory\Exceptions\PathAlreadyExists;
use Spatie\TemporaryDirectory\TemporaryDirectory;
use Symfony\Component\Console\Output\ConsoleOutput;

class Backup
{
    private Filesystem $destination;
    private string $timestamp;
    private TemporaryDirectory $temporaryDirectory;
    public string $backupName;

    protected string $zipCommand = "/usr/bin/zip";
    private string $basePath;
    private string $baseName;
    private string $tempPath;
    private string $relativeTempPath;
    private string|array $relativeTempBasePath;
    private ?DbDumper $databaseDumper = null;

    /**
     * @throws PathAlreadyExists|ZipExecutableNotFound|Exceptions\CannotCreateDbDumper
     */
    public function __construct()
    {
        $this->checkRequirements();

        $this->baseName   = Str::camel(strtolower(config('backup.base_name')));
        $this->basePath   = config('backup.base_path') ?? base_path();
        $this->tempPath   = (config('backup.temp_path') ?? '').DIRECTORY_SEPARATOR.'_temp_'.$this->baseName;
        $this->timestamp  = Carbon::now()->format('YmdHis');
        $this->backupName = 'backup_'.$this->timestamp;

        $this->temporaryDirectory = (new TemporaryDirectory($this->tempPath))
            ->name($this->backupName)
            ->force()
            ->create()
            ->empty();

        $this->relativeTempPath     = str_replace($this->basePath, '.', $this->temporaryDirectory->path());
        $this->relativeTempBasePath = str_replace($this->basePath, '.', $this->tempPath);

        $this->destination = app(Factory::class)
            ->disk(config('backup.disk'));
        if (!$this->destination->exists($this->baseName)) {
            $this->destination->makeDirectory($this->baseName);
        }

        if (($database = config('backup.database')) !== null) {
            $this->databaseDumper = DbDumperFactory::createFromConnection($database);
        }
    }

    /**
     * @throws ZipCommandFailed
     */
    public static function run()
    {
        app(ConsoleOutput::class)->info("Preflight OK. Starting the backup process");

        $instance = new static();
        $instance
            ->cleanBackups()
            ->dumpDatabase()
            ->createArchive()
            ->uploadBackup()
            ->cleanUp();
    }

    private function dumpDatabase()
    {
        if ($this->databaseDumper !== null) {
            app(ConsoleOutput::class)->info("Dumping database");

            $dbType = mb_strtolower(basename(str_replace('\\', '/', get_class($this->databaseDumper))));

            $dbName = $this->databaseDumper->getDbName();
            if ($this->databaseDumper instanceof Sqlite) {
                $dbName = '1-database';
            }
            $fileName = "{$dbType}-{$dbName}.sql";

            $this->databaseDumper->useCompressor(new GzipCompressor());
            $fileName .= '.'.$this->databaseDumper->getCompressorExtension();

            File::ensureDirectoryExists($this->basePath.DIRECTORY_SEPARATOR.'db-dumps');
            $temporaryFilePath = $this->basePath.DIRECTORY_SEPARATOR.'db-dumps'.DIRECTORY_SEPARATOR.$fileName;

            $this->databaseDumper->dumpToFile($temporaryFilePath);
        }

        return $this;
    }

    /**
     * @throws ZipCommandFailed
     */
    private function createArchive(): self
    {
        app(ConsoleOutput::class)->info("Creating the ZIP archive");

        $currentDirectory = getcwd();
        chdir($this->basePath);

        $exclude = '';
        if (!empty($excludeDirectories = config('backup.exclude_directories', []))) {
            $exclude         = '-x '.$this->relativeTempBasePath.DIRECTORY_SEPARATOR.'\* ';
            $destinationDisk = config('filesystems.disks.'.config('backup.disk'));
            if ($destinationDisk['driver'] === 'local') {
                $destinationPath = str_replace($this->basePath, '', $destinationDisk['root']);
                $exclude         .= '.'.$destinationPath.DIRECTORY_SEPARATOR.$this->baseName.DIRECTORY_SEPARATOR.'\* ';
            }
            foreach ($excludeDirectories as $directory) {
                $exclude .= '.'.DIRECTORY_SEPARATOR.$directory.'\* ';
            }
        }

        $size = config('backup.parts_size');

        $archivePath = $this->relativeTempPath.DIRECTORY_SEPARATOR.'backup_'.$this->timestamp.'.zip';
        $command     = "$this->zipCommand -qrs $size $archivePath . $exclude";

        if (shell_exec($command) === null) {
            chdir($currentDirectory);

            return $this;
        } else {
            throw new ZipCommandFailed();
        }
    }

    private function uploadBackup(): self
    {
        app(ConsoleOutput::class)->info("Uploading the ZIP archive");

        $path = $this->baseName.DIRECTORY_SEPARATOR.$this->backupName;
        $this->destination->makeDirectory($path);

        foreach ((new RecursiveDirectoryIterator($this->temporaryDirectory->path(), FilesystemIterator::SKIP_DOTS)) as $file) {
            $this->destination->put($path.DIRECTORY_SEPARATOR.$file->getFilename(), file_get_contents($file->getRealPath()));
        }

        return $this;
    }

    private function cleanBackups(): self
    {
        app(ConsoleOutput::class)->info("Cleaning old backups");

        $directories = collect($this->destination->allDirectories($this->baseName))
            ->sortByDesc(function ($dirname) {
                return $dirname;
            });

        if ($directories->count() > 4) {
            $obsolete = $directories->last();
            $this->destination->deleteDirectory($obsolete);
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
        app(ConsoleOutput::class)->info("Deleting the temporary folders and files");

        $this->deleteDirectory($this->tempPath);
        $this->deleteDirectory($this->basePath.DIRECTORY_SEPARATOR.'db-dumps');
    }

    /**
     * @throws ZipExecutableNotFound
     */
    private function checkRequirements()
    {
        if (!is_executable($this->zipCommand)) {
            throw new ZipExecutableNotFound();
        }
        if (empty(config('backup.base_name')) || empty(config('backup.disk'))) {
            throw new InvalidArgumentException();
        }
    }
}
