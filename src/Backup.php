<?php
declare(strict_types=1);

namespace MarcAndreAppel\Backup;

use Exception;
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

class Backup
{
    private Filesystem $destination;
    private string $timestamp;
    private TemporaryDirectory $temporaryDirectory;
    public string $backupName;

    protected string $zipCommand = "/usr/bin/zip";
    private string $basePath;
    private string $baseName;
    private string $relativeTempPath;
    private ?DbDumper $databaseDumper = null;
    private int $backupCount;

    /**
     * @throws PathAlreadyExists|ZipExecutableNotFound|Exceptions\CannotCreateDbDumper
     */
    public function __construct()
    {
        $this->checkRequirements();

        $this->baseName    = Str::camel(strtolower(config('backup.base_name')));
        $this->basePath    = config('backup.base_path') ?? base_path();
        $this->timestamp   = Carbon::now()->format('YmdHis');
        $this->backupName  = 'backup_'.$this->timestamp;
        $this->backupCount = (int) config('backup.backup_count', 7);

        $this->temporaryDirectory = (new TemporaryDirectory(config('backup.temp_path') ?? ''))
            ->name($this->backupName)
            ->force()
            ->create()
            ->empty();

        $this->relativeTempPath = str_replace($this->basePath, '.', $this->temporaryDirectory->path());

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
    public static function run(): void
    {
        console_output()->info("Preflight OK. Starting the backup process");

        $instance = new static();
        $instance
            ->cleanBackups()
            ->dumpDatabase()
            ->createArchive()
            ->uploadBackup()
            ->cleanUp();
    }

    private function dumpDatabase(): self
    {
        if ($this->databaseDumper !== null) {
            console_output()->info("Dumping database");

            $dbType = mb_strtolower(basename(str_replace('\\', '/', get_class($this->databaseDumper))));

            $dbName = $this->databaseDumper->getDbName();
            if ($this->databaseDumper instanceof Sqlite) {
                $dbName = '1-database';
            }
            $fileName = "$dbType-$dbName.sql";

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
        console_output()->info("Creating the ZIP archive");

        $currentDirectory = getcwd();
        chdir($this->basePath);

        $exclude = '';
        if (!empty($excludeDirectories = config('backup.exclude_directories', []))) {
            $exclude         = '-x '.$this->relativeTempPath.DIRECTORY_SEPARATOR.'\* ';
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
        console_output()->info("Uploading the ZIP archive");

        $path = $this->baseName.DIRECTORY_SEPARATOR.$this->backupName;
        $this->destination->makeDirectory($path);

        $sources  = $this->temporaryDirectory->path();
        $iterator = new RecursiveDirectoryIterator($sources, FilesystemIterator::SKIP_DOTS);

        do {
            foreach ($iterator as $file) {
                try {
                    $this->destination->put($path.DIRECTORY_SEPARATOR.$file->getFilename(), fopen($file->getRealPath(), 'r+'));
                    unlink($file->getRealPath());
                } catch (Exception) {
                    console_output()->error('Upload failed for '.$file->getFilename());
                }
            }
        } while (count(glob($sources.DIRECTORY_SEPARATOR."*")) !== 0);

        console_output()->info('Upload succeeded');

        return $this;
    }

    private function cleanBackups(): self
    {
        console_output()->info("Cleaning old backups");

        $directories = collect($this->destination->allDirectories($this->baseName))
            ->sortByDesc(function ($dirname) {
                return $dirname;
            });

        if ($directories->count() > $this->backupCount) {
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
        console_output()->info("Deleting the temporary folders and files");

        $this->temporaryDirectory->delete();
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
