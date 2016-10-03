<?php

namespace TalvBansal\MediaManager\Services;

use Carbon\Carbon;
use Dflydev\ApacheMimeTypes\PhpRepository;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use TalvBansal\MediaManager\Contracts\FileUploaderInterface;
use TalvBansal\MediaManager\Contracts\UploadedFilesInterface;

/**
 * Class MediaManager
 * @package TalvBansal\MediaManager\Services
 */
class MediaManager implements FileUploaderInterface
{

    /**
     * @var FilesystemAdapter
     */
    protected $disk;

    /**
     * @var PhpRepository
     */
    protected $mimeDetect;

    /**
     * @var array
     */
    private $errors = [];


    /**
     * UploadsManager constructor.
     *
     * @param PhpRepository $mimeDetect
     */
    public function __construct(PhpRepository $mimeDetect)
    {
        $this->disk       = Storage::disk('public');
        $this->mimeDetect = $mimeDetect;
    }


    /**
     * Fetch any errors generated by the class when operations have been performed.
     *
     * @return array
     */
    public function errors()
    {
        return $this->errors;
    }


    /**
     * Return files and directories within a folder.
     *
     * @param string $folder
     *
     * @return array of [
     *               'folder' => 'path to current folder',
     *               'folderName' => 'name of just current folder',
     *               'breadCrumbs' => breadcrumb array of [ $path => $foldername ],
     *               'subfolders' => array of [ $path => $foldername] of each subfolder,
     *               'files' => array of file details on each file in folder,
     *               'itemsCount' => a combined count of the files and folders within the current folder
     *               ]
     */
    public function folderInfo($folder = '/')
    {
        $folder      = $this->cleanFolder($folder);
        $breadcrumbs = $this->breadcrumbs($folder);
        $folderName  = $breadcrumbs->pop();

        // Get the names of the sub folders within this folder
        $subfolders = collect($this->disk->directories($folder))->reduce(function ($subfolders, $subFolder) {
            $subfolders["/$subFolder"] = basename($subFolder);

            return $subfolders;
        }, collect([]));

        // Get all files within this folder
        $files = collect($this->disk->files($folder))->reduce(function ($files, $path) {
            // Don't show hidden files or folders
            if ( ! starts_with(last(explode(DIRECTORY_SEPARATOR, $path)), '.')) {
                $files[] = $this->fileDetails($path);
            }

            return $files;
        }, collect([]));

        $itemsCount = $subfolders->count() + $files->count();

        return compact('folder', 'folderName', 'breadcrumbs', 'subfolders', 'files', 'itemsCount');
    }


    /**
     * Sanitize the folder name.
     *
     * @param $folder
     *
     * @return string
     */
    protected function cleanFolder($folder)
    {
        return '/' . trim(str_replace('..', '', $folder), '/');
    }


    /**
     * Return breadcrumbs to current folder.
     *
     * @param $folder
     *
     * @return Collection
     */
    protected function breadcrumbs($folder)
    {
        $folder  = trim($folder, '/');
        $folders = collect(explode('/', $folder));
        $path    = '';

        return $folders->reduce(function ($crumbs, $folder) use ($path) {
            $path .= '/' . $folder;
            $crumbs[$path] = $folder;

            return $crumbs;
        }, collect())->prepend('Root', '/');
    }


    /**
     * Return an array of file details for a file.
     *
     * @param $path
     *
     * @return array
     */
    protected function fileDetails($path)
    {
        $path = '/' . ltrim($path, '/');

        return [
            'name'         => basename($path),
            'fullPath'     => $path,
            'webPath'      => $this->fileWebpath($path),
            'mimeType'     => $this->fileMimeType($path),
            'size'         => $this->fileSize($path),
            'modified'     => $this->fileModified($path),
            'relativePath' => $this->fileRelativePath($path),
        ];
    }


    /**
     * Return the full web path to a file.
     *
     * @param $path
     *
     * @return string
     */
    public function fileWebpath($path)
    {
        $path = $this->fileRelativePath($path);

        return url($path);
    }


    /**
     * Return the mime type.
     *
     * @param $path
     *
     * @return string
     */
    public function fileMimeType($path)
    {
        return $this->mimeDetect->findType(pathinfo($path, PATHINFO_EXTENSION));
    }


    /**
     * Return the file size.
     *
     * @param $path
     *
     * @return int
     */
    public function fileSize($path)
    {
        return $this->disk->size($path);
    }


    /**
     * Return the last modified time.
     *
     * @param $path
     *
     * @return Carbon
     */
    public function fileModified($path)
    {
        return Carbon::createFromTimestamp($this->disk->lastModified($path));
    }


    /**
     * Create a new directory.
     *
     * @param $folder
     *
     * @return bool
     */
    public function createDirectory($folder)
    {
        $folder = $this->cleanFolder($folder);
        if ($this->disk->exists($folder)) {
            $this->errors[] = 'Folder "' . $folder . '" already exists.';

            return false;
        }

        return $this->disk->makeDirectory($folder);
    }


    /**
     * Delete a directory.
     *
     * @param $folder
     *
     * @return bool
     */
    public function deleteDirectory($folder)
    {
        $folder       = $this->cleanFolder($folder);
        $filesFolders = array_merge($this->disk->directories($folder), $this->disk->files($folder));
        if ( ! empty( $filesFolders )) {
            $this->errors[] = 'The directory must be empty to delete it.';

            return false;
        }

        return $this->disk->deleteDirectory($folder);
    }

    /**
     * Delete a file.
     *
     * @param $path
     *
     * @return bool
     */
    public function deleteFile($path)
    {
        $path = $this->cleanFolder($path);
        if ( ! $this->disk->exists($path)) {
            $this->errors[] = 'File does not exist.';

            return false;
        }

        return $this->disk->delete($path);
    }

    /**
     * @param $path
     * @param $originalFileName
     * @param $newFileName
     *
     * @return bool
     */
    public function rename($path, $originalFileName, $newFileName)
    {
        $path     = $this->cleanFolder($path);
        $nameName = $path . DIRECTORY_SEPARATOR . $newFileName;
        if ($this->disk->exists($nameName)) {
            $this->errors[] = 'The file "' . $newFileName . '" already exists in this folder.';

            return false;
        }

        return $this->disk->getDriver()->rename(( $path . DIRECTORY_SEPARATOR . $originalFileName ), $nameName);
    }

    /**
     * Show all directories that the selected item can be moved to.
     *
     * @return array
     */
    public function allDirectories()
    {
        $directories = $this->disk->allDirectories('/');

        return collect($directories)->map(function ($directory) {
            return DIRECTORY_SEPARATOR . $directory;
        })->reduce(function ($allDirectories, $directory) {
            $parts = explode('/', $directory);
            $name  = str_repeat('&nbsp;', ( count($parts) ) * 4) . basename($directory);

            $allDirectories[$directory] = $name;

            return $allDirectories;
        }, collect())->prepend('Root', '/');
    }

    /**
     * @param      $currentFile
     * @param      $newFile
     * @param bool $isFolder
     *
     * @return bool
     */
    public function moveFile($currentFile, $newFile, $isFolder = false)
    {
        if ($this->disk->exists($newFile)) {
            $this->errors[] = 'File already exists.';

            return false;
        }

        return $this->disk->getDriver()->rename($currentFile, $newFile);
    }

    /**
     * @param $currentFolder
     * @param $newFolder
     *
     * @return bool
     */
    public function moveFolder($currentFolder, $newFolder)
    {
        if ($newFolder == $currentFolder) {
            $this->errors[] = 'Please select another folder to move this folder into.';

            return false;
        }

        if (starts_with($newFolder, $currentFolder)) {
            $this->errors[] = 'You can not move this folder inside of itself.';

            return false;
        }

        return $this->disk->getDriver()->rename($currentFolder, $newFolder);
    }

    /**
     * @param $path
     *
     * @return string
     */
    private function fileRelativePath($path)
    {
        $path = str_replace(' ', '%20', $path);

        return '/storage/' . ltrim($path, '/');
    }

    /**
     * This method will take a collection of files that have been
     * uploaded during a request and then save those files to
     * the given path.
     *
     * @param UploadedFilesInterface $files
     * @param string                 $path
     *
     * @return int
     */
    public function saveUploadedFiles(UploadedFilesInterface $files, $path = '/')
    {
        return $files->getUploadedFiles()->reduce(function ($uploaded, UploadedFile $file) use ($path) {
            $fileName = $file->getClientOriginalName();
            if ($this->disk->exists($path . $fileName)) {
                $this->errors[] = 'File ' . $path . $fileName . ' already exists in this folder.';

                return $uploaded;
            }

            if ( ! $file->storeAs($path, $fileName, 'public')) {
                $this->errors[] = trans('media-manager::messages.upload_error', [ 'entity' => $fileName ]);

                return $uploaded;
            }
            $uploaded++;

            return $uploaded;
        }, 0);
    }
}
