<?php

namespace Cakebox\Controller;

use Silex\Application;
use SPLFileInfo;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * FileController
 */
class FileController {

    /**
     * Get file informations (size, name ...)
     *
     * @param Application $app     Silex Application
     * @param Request     $request Request parameters
     *
     * @return JsonResponse Object containing file informations
     */
    public function get(Application $app, Request $request) {

        if ($app["rights.canPlayMedia"] == false) {
            $app->abort(403, "This user doesn't have the rights to retrieve file informations");
        }

        $filepath = $app['service.main']->checkPath($app['cakebox.root'], $request->get('path'));

        if (!isset($filepath)) {
            $app->abort(400, "Missing parameters");
        }

        $file = new SPLFileInfo("{$app['cakebox.root']}/{$filepath}");

        $fileinfo             = [];
        $fileinfo["name"]     = $file->getBasename("." . $file->getExtension());
        $fileinfo["fullname"] = $file->getFilename();
        $fileinfo["mimetype"] = mime_content_type($file->getPathName());
        $fileinfo["access"]   =
            str_replace('%2F', '/', rawurlencode("{$app['cakebox.access']}/{$filepath}"));
        $fileinfo["size"]     = $file->getSize();

        $arrDirectory = $this->getCurrentDirectoryFiles($file, $app);

        if (count($arrDirectory) > 1) {
            $fileinfo['previousFile'] =
                $this->arrayKeyRelative($arrDirectory, $file->getRealPath(), -1);

            if ($fileinfo['previousFile']) {
                $fileinfo['previousFile'] =
                    str_replace($app['cakebox.root'], '', $fileinfo['previousFile']);
            }

            $fileinfo['nextFile'] = $this->arrayKeyRelative($arrDirectory, $file->getRealPath(), 1);

            if ($fileinfo['nextFile']) {
                $fileinfo['nextFile'] =
                    str_replace($app['cakebox.root'], '', $fileinfo['nextFile']);
            }
        }

        return $app->json($fileinfo, 200);
    }

    /**
     * Delete a file
     *
     * @param Application $app     Silex Application
     * @param Request     $request Request parameters
     *
     * @return JsonResponse Array of objects, directory content after the
     *                      delete process
     */
    public function delete(Application $app, Request $request) {

        if ($app["rights.canDelete"] == false) {
            $app->abort(403, "This user doesn't have the rights to delete this file");
        }

        $filepath = $app['service.main']->checkPath($app['cakebox.root'], $request->get('path'));

        if (!isset($filepath)) {
            $app->abort(400, "Missing parameters");
        }

        $file = "{$app['cakebox.root']}/{$filepath}";

        if (file_exists($file) === false) {
            $app->abort(404, "File not found");
        }

        if (is_file($file) === false) {
            $app->abort(403, "This is not a file");
        }

        if (is_writable($file) === false) {
            $app->abort(403, "This file is not writable");
        }

        unlink($file);

        $subRequest = Request::create('/api/directories', 'GET', ['path' => dirname($filepath)]);

        return $app->handle($subRequest, HttpKernelInterface::SUB_REQUEST);
    }

    /**
     * Get files in the current $file directory
     *
     * @param SPLFileInfo $file
     * @param Application $app
     *
     * @return array
     */
    private function getCurrentDirectoryFiles(SPLFileInfo $file, Application $app) {
        $finder = new Finder();
        $finder->files()->in($file->getPath())->depth('== 0')->ignoreVCS(true)
               ->ignoreDotFiles($app['directory.ignoreDotFiles'])->notName($app["directory.ignore"])
               ->filter(function ($curFile) use ($app) {
                   /**
                    * @var SPLFileInfo $curFile
                    */
                   if ($curFile->isReadable()
                       && in_array(strtolower($curFile->getExtension()), $app['extension.video'])
                   ) {
                       return true;
                   }

                   return false;
               })->sortByType();

        return iterator_to_array($finder->getIterator());
    }

    /**
     * Get desired offset in an array
     *
     * @param array      $array
     * @param int|string $current_key
     * @param int        $offset
     * @param bool       $strict
     *
     * @return mixed     return desired offset, if in array, or false if not
     */
    private function arrayKeyRelative($array, $current_key, $offset = 1, $strict = true) {

        $keys = array_keys($array);

        $current_key_index = array_search($current_key, $keys, $strict);

        if (isset($keys[$current_key_index + $offset])) {
            return $keys[$current_key_index + $offset];
        }

        return false;
    }
}
