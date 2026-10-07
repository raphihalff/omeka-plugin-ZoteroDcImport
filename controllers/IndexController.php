<?php
/**
 * Point the plugin at a Zotero export and import it.
 *
 * A real export exceeds typical upload limits, so it normally arrives as a
 * server path and runs as a background job.
 */
class ZoteroDcImport_IndexController extends Omeka_Controller_AbstractActionController
{
    protected static $_allowed = array('rdf', 'xml', 'json', 'zip');

    public function indexAction()
    {
        $this->view->collections = $this->_collectionOptions();
        $this->view->settings = ZoteroDcImportPlugin::elementSettings();
        $this->view->status = ZoteroDcImport_Job::currentStatus();
        $dropboxDir = $this->_dropboxDir();
        $this->view->dropboxDir = $dropboxDir;
        $this->view->dropboxFileCount = $dropboxDir === null
            ? 0 : $this->_countFiles($dropboxDir);
        $this->view->limits = array(
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'post_max_size' => ini_get('post_max_size'),
        );

        $this->view->diagnostics = $this->_diagnostics();

        if (!$this->getRequest()->isPost()) {
            return;
        }

        try {
            $this->_start();
        } catch (Exception $e) {
            $this->_helper->flashMessenger($e->getMessage(), 'error');
        }
    }

    /** Dismiss the last import report. */
    public function clearAction()
    {
        ZoteroDcImport_Job::clear();
        $this->_helper->flashMessenger(__('Import report cleared.'), 'success');
        $this->_helper->redirector->gotoSimple('index');
    }

    protected function _start()
    {
        $post = $this->getRequest()->getPost();
        list($dataFile, $baseDir) = $this->_locateExport($post);

        $fileSource = isset($post['file_source']) ? $post['file_source'] : 'none';
        if ($fileSource === 'dropbox' && $this->_dropboxDir() === null) {
            throw new Exception(__('The Dropbox plugin\'s files directory was not found. '
                . 'Install and activate Dropbox, or choose another file source.'));
        }

        $importOptions = array(
            'collection_id' => empty($post['collection_id']) ? null : (int) $post['collection_id'],
            'public' => !empty($post['public']),
            'featured' => !empty($post['featured']),
            'file_source' => $fileSource,
            'base_dir' => $baseDir,
            'remove_after_attach' => !empty($post['remove_after_attach']),
            'add_tags' => !empty($post['add_tags']),
            'skip_duplicates' => !empty($post['skip_duplicates']),
        );

        $settings = array(
            'elements' => ZoteroDcImportPlugin::elementSettings(),
            'includeRomanization' => (bool) get_option('zotero_dc_import_include_romanization'),
        );

        $jobOptions = array(
            'path' => $dataFile,
            'import' => $importOptions,
            'settings' => $settings,
        );

        ZoteroDcImport_Job::setQueued();
        try {
            Zend_Registry::get('job_dispatcher')
                ->sendLongRunning('ZoteroDcImport_Job', $jobOptions);
        } catch (Exception $e) {
            ZoteroDcImport_Job::setError('Could not start the background job: '
                . $e->getMessage());
            throw new Exception(__('Could not start the background job: %s '
                . 'See the diagnostics below.', $e->getMessage()));
        }

        $this->_helper->flashMessenger(
            __('Import queued. Reload this page for progress.'), 'success');
        $this->_helper->redirector->gotoSimple('index');
    }

    /** @return array [data file, directory its relative paths resolve against] */
    protected function _locateExport(array $post)
    {
        $serverPath = isset($post['server_path']) ? trim($post['server_path']) : '';

        if (strlen($serverPath)) {
            $real = realpath($serverPath);
            if ($real === false || !is_readable($real)) {
                throw new Exception(__('Cannot read "%s". Check the path and its permissions.',
                    $serverPath));
            }
            if (is_dir($real)) {
                $dataFile = $this->_findDataFile($real);
                if ($dataFile === null) {
                    throw new Exception(__('No .rdf or .json file in "%s".', $serverPath));
                }
                return array($dataFile, $real);
            }
            return array($real, dirname($real));
        }

        if (empty($_FILES['export']) || $_FILES['export']['error'] !== UPLOAD_ERR_OK) {
            if (!empty($_FILES['export']) && $_FILES['export']['error'] === UPLOAD_ERR_INI_SIZE) {
                throw new Exception(__('That file is larger than the server\'s %s upload limit. '
                    . 'Put the export on the server and give its path instead.',
                    ini_get('upload_max_filesize')));
            }
            throw new Exception(__('Give a path on the server, or choose a file to upload.'));
        }

        return $this->_storeUpload($_FILES['export']);
    }

    /** Stored durably: the job runs after this request ends. */
    protected function _storeUpload(array $upload)
    {
        $extension = strtolower(pathinfo($upload['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, self::$_allowed, true)) {
            throw new Exception(__('Unsupported file type ".%s". Export as Zotero RDF '
                . '(.rdf, or .zip with files) or CSL JSON (.json).', $extension));
        }

        $dir = FILES_DIR . DIRECTORY_SEPARATOR . 'zotero-dc-import';
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            throw new Exception(__('Could not create %s.', $dir));
        }
        $stamp = date('Ymd-His') . '-' . bin2hex(random_bytes(4));
        $target = $dir . DIRECTORY_SEPARATOR . $stamp . '.' . $extension;

        if (!move_uploaded_file($upload['tmp_name'], $target)) {
            throw new Exception(__('Could not save the uploaded file.'));
        }

        if ($extension !== 'zip') {
            return array($target, $dir);
        }
        return $this->_unpackZip($target, $dir . DIRECTORY_SEPARATOR . $stamp);
    }

    /** Reject entries with absolute or traversing paths. */
    protected function _unpackZip($zipPath, $extractTo)
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new Exception(__('Could not open the ZIP archive.'));
        }
        if (!is_dir($extractTo) && !mkdir($extractTo, 0755, true)) {
            throw new Exception(__('Could not create %s.', $extractTo));
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === false || $name === '') {
                continue;
            }
            $normalized = str_replace('\\', '/', $name);
            if (substr($normalized, 0, 1) === '/'
                || preg_match('~(^|/)\.\.(/|$)~', $normalized)) {
                continue;
            }
            $zip->extractTo($extractTo, $name);
        }
        $zip->close();
        @unlink($zipPath);

        $dataFile = $this->_findDataFile($extractTo);
        if ($dataFile === null) {
            throw new Exception(__('No .rdf or .json file inside that ZIP archive.'));
        }
        return array($dataFile, $extractTo);
    }

    protected function _findDataFile($dir)
    {
        $best = null;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $extension = strtolower($file->getExtension());
            if ($extension === 'rdf') {
                return $file->getRealPath();
            }
            if (($extension === 'json' || $extension === 'xml') && $best === null) {
                $best = $file->getRealPath();
            }
        }
        return $best;
    }

    /**
     * Distinguishes a stalled import from a dead job runner: BackgroundProcess
     * shells out through exec(), which many hosts disable silently.
     */
    protected function _diagnostics()
    {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        $out = array(
            'exec available' => in_array('exec', $disabled, true) ? 'no (disabled)'
                : (function_exists('exec') ? 'yes' : 'no'),
            'long-running adapter' => $this->_configValue('jobs.dispatcher.longRunning',
                'Omeka_Job_Dispatcher_Adapter_Synchronous'),
            'PHP-CLI path' => $this->_configValue('background.php.path', '(autodetected)'),
            'max_execution_time' => ini_get('max_execution_time'),
            'memory_limit' => ini_get('memory_limit'),
        );

        $db = get_db();
        try {
            $rows = $db->fetchAll(
                "SELECT status, started, stopped, pid FROM {$db->Process}
                 WHERE class = ? ORDER BY id DESC LIMIT 5",
                array('ZoteroDcImport_Job'));
            $out['recent job processes'] = $rows
                ? $rows
                : 'none recorded -- the job was never queued by Omeka';
        } catch (Exception $e) {
            $out['recent job processes'] = 'could not read: ' . $e->getMessage();
        }
        return $out;
    }

    /** Read a dotted key from config.ini. */
    protected function _configValue($path, $default)
    {
        $config = Zend_Registry::get('bootstrap')->config;
        foreach (explode('.', $path) as $segment) {
            if (!isset($config->$segment)) {
                return $default;
            }
            $config = $config->$segment;
        }
        $value = (string) $config;
        return strlen($value) ? $value : $default;
    }

    protected function _countFiles($dir)
    {
        $count = 0;
        foreach (new DirectoryIterator($dir) as $entry) {
            if ($entry->isFile() && substr($entry->getFilename(), 0, 1) !== '.') {
                $count++;
            }
        }
        return $count;
    }

    protected function _dropboxDir()
    {
        $importer = new ZoteroDcImport_Importer();
        return $importer->dropboxDir();
    }

    protected function _collectionOptions()
    {
        $options = array('' => __('(no collection)'));
        foreach (get_db()->getTable('Collection')->findAll() as $collection) {
            $title = metadata($collection, array('Dublin Core', 'Title'));
            $options[$collection->id] = $title ? $title : __('Collection %s', $collection->id);
            release_object($collection);
        }
        return $options;
    }
}
