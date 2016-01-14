<?php

/**
 * File info?
 */

/**
 * The default file storage class for H5P. Will carry out the requested file
 * operations using PHP's standard file operation functions.
 *
 * Some implementations of H5P that doesn't use the standard file system will
 * want to create their own implementation of the \H5P\FileStorage interface.
 *
 * @package    H5P
 * @copyright  2016 Joubel AS
 * @license    MIT
 */
class DefaultStorage implements \H5PFileStorage {
  private $path;

  /**
   * The great Constructor!
   *
   * @param string $path
   *  The base location of H5P files
   */
  function __construct($path) {
    // Set H5P storage path
    $this->path = $path;
  }

  /**
   * Store the library folder.
   *
   * @param array $library
   *  Library properties
   */
  public function saveLibrary($library) {
    $dest = $this->path . '/libraries/' . \H5PCore::libraryToString($library, TRUE);

    // Make sure destination dir doesn't exist
    \H5PCore::deleteFileTree($dest);

    // Move library folder
    self::copyFileTree($library['uploadDirectory'], $dest);
  }

  /**
   * Store the content folder.
   *
   * @param string $source
   *  Path on file system to content directory.
   * @param int $id
   *  What makes this content unique.
   */
  public function saveContent($source, $id) {
    $dest = "{$this->path}/content/{$id}";

    // Remove any old content
    \H5PCore::deleteFileTree($dest);

    self::copyFileTree($source, $dest);
  }

  /**
   * Remove content folder.
   *
   * @param int $id
   *  Content identifier
   */
  public function deleteContent($id) {
    \H5PCore::deleteFileTree("{$this->path}/content/{$id}");
  }

  /**
   * Creates a stored copy of the content folder.
   *
   * @param string $id
   *  Identifier of content to clone.
   * @param int $newId
   *  The cloned content's identifier
   */
  public function cloneContent($id, $newId) {
    $path = $this->path . '/content/';
    self::copyFileTree($path . $id, $path . $newId);
  }

  /**
   * Get path to a new unique tmp folder.
   *
   * @return string
   *  Path
   */
  public function getTmpPath() {
    return $this->path . '/temp/' . uniqid('h5p-');
  }

  /**
   * Fetch content folder and save in target directory.
   *
   * @param int $id
   *  Content identifier
   * @param string $target
   *  Where the content folder will be saved
   */
  public function exportContent($id, $target) {
    self::copyFileTree("{$this->path}/content/{$id}", $target);
  }

  /**
   * Fetch library folder and save in target directory.
   *
   * @param array $library
   *  Library properties
   * @param string $target
   *  Where the library folder will be saved
   */
  public function exportLibrary($library, $target) {
    $folder = \H5PCore::libraryToString($library, TRUE);
    self::copyFileTree("{$this->path}/libraries/{$folder}", $target);
  }

  /**
   * Save export in file system
   *
   * @param string $source
   *  Path on file system to temporary export file.
   * @param string $filename
   *  Name of export file.
   */
  public function saveExport($source, $filename) {
    $this->deleteExport($filename);
    self::dirReady("{$this->path}/exports");
    copy($source, "{$this->path}/exports/{$filename}");
  }

  /**
   * Removes given export file
   *
   * @param string $filename
   */
  public function deleteExport($filename) {
    $target = "{$this->path}/exports/{$filename}";
    if (file_exists($target)) {
      unlink($target);
    }
  }

  /**
   * Will concatenate all JavaScrips and Stylesheets into two files in order
   * to improve page performance.
   *
   * @param array $files
   *  A set of all the assets required for content to display
   * @param string $key
   *  Hashed key for cached asset
   */
  public function cacheAssets(&$files, $key) {
    foreach ($files as $type => $assets) {
      $content = '';

      foreach ($assets as $asset) {
        // Get content from asset file
        $assetContent = file_get_contents($this->path . $asset->path);
        $cssRelPath = preg_replace('/[^\/]+$/', '', $asset->path);

        // Get file content and concatenate
        if ($type === 'scripts') {
          $content .= $assetContent . ";\n";
        }
        else {
          // Rewrite relative URLs used inside stylesheets
          $content .= preg_replace_callback(
              '/url\([\'"]?([^"\')]+)[\'"]?\)/i',
              function ($matches) use ($cssRelPath) {
                return substr($matches[1], 0, 3) !== '../' ? $matches[0] : 'url("../' . $cssRelPath . $matches[1] . '")';
              },
              $assetContent
          ) . "\n";
        }
      }

      self::dirReady("{$this->path}/cachedassets");
      $ext = ($type === 'scripts' ? 'js' : 'css');
      file_put_contents("{$this->path}/cachedassets/{$key}.{$ext}", $content);
    }

    // Use the newly created cache
    $files = self::formatCachedAssets($key);
  }

  /**
   * Will check if there are cache assets available for content.
   *
   * @param string $key
   *  Hashed key for cached asset
   * @return array
   */
  public function getCachedAssets($key) {
    if (!file_exists("{$this->path}/cachedassets/{$key}.js") ||
        !file_exists("{$this->path}/cachedassets/{$key}.css") {
      return NULL;
    }
    return self::formatCachedAssets($key);
  }

  /**
   * Remove the aggregated cache files.
   *
   * @param array $keys
   *   The hash keys of removed files
   */
  public function deleteCachedAssets($keys) {
    $context = \context_system::instance();
    $fs = get_file_storage();

    foreach ($keys as $hash) {
      foreach (array('js', 'css') as $ext) {
        $path = "{$this->path}/cachedassets/{$key}.{$ext}";
        if (file_exists($path)) {
          unlink($path);
        }
      }
    }
  }

  /**
   * Format the cached assets data the way it's supposed to be.
   *
   * @param string $key
   *  Hashed key for cached asset
   * @return array
   */
  private static function formatCachedAssets($key) {
    return array(
      'scripts' => array((object) array(
        'path' => "/cachedassets/{$key}.js",
        'version' => ''
      )),
      'styles' => array((object) array(
        'path' => "/cachedassets/{$key}.css",
        'version' => ''
      ))
    );
  }

  /**
   * Recursive function for copying directories.
   *
   * @param string $source
   *  From path
   * @param string $destination
   *  To path
   * @return boolean
   *  Indicates if the directory existed.
   */
  private static function copyFileTree($source, $destination) {
    if (!self::dirReady($destination)) {
      throw new \Exception('unabletocopy');
    }

    $dir = opendir($source);
    if ($dir === FALSE) {
      trigger_error('Unable to open directory ' . $source, E_USER_WARNING);
      throw new \Exception('unabletocopy');
    }

    while (false !== ($file = readdir($dir))) {
      if (($file != '.') && ($file != '..') && $file != '.git' && $file != '.gitignore') {
        if (is_dir("{$source}/{$file}")) {
          self::copyFileTree("{$source}/{$file}", "{$destination}/{$file}");
        }
        else {
          copy("{$source}/{$file}", "{$destination}/{$file}");
        }
      }
    }
    closedir($dir);
  }

  /**
   * Recursive function that makes sure the specified directory exists and
   * is writable.
   *
   * @param string $path
   * @return bool
   */
  private static function dirReady($path) {
    if (!file_exists($path)) {
      $parent = preg_replace("/\/[^\/]+\/?$/", '', $path);
      if (!self::dirReady($parent)) {
        return FALSE;
      }

      mkdir($path, 0777, true);
    }

    if (!is_dir($path)) {
      trigger_error('Path is not a directory ' . $path, E_USER_WARNING);
      return FALSE;
    }

    if (!is_writable($path)) {
      trigger_error('Unable to write to ' . $path . ' – check directory permissions –', E_USER_WARNING);
      return FALSE;
    }

    return TRUE;
  }
}
