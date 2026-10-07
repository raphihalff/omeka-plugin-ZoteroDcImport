<?php
/**
 * Turns parsed Zotero items into Omeka items.
 */
class ZoteroDcImport_Importer
{
    protected $_mapper;
    protected $_options;
    protected $_results = array('created' => 0, 'skipped' => 0, 'files' => 0,
        'removed' => 0, 'messages' => array());

    /**
     * @param array $options
     *   collection_id  int|null  collection to file items under
     *   public         bool
     *   featured       bool
     *   file_source    string    'none', 'dropbox' or 'path'
     *   base_dir       string    for 'path': root for relative paths
     *   remove_after_attach bool  for 'dropbox': delete once ingested
     *   add_tags       bool      also add Zotero tags as Omeka tags
     *   skip_duplicates bool     skip titles already present
     */
    public function __construct(array $options = array())
    {
        $this->_mapper = new ZoteroDcImport_Mapper();
        $this->_options = array_merge(array(
            'collection_id' => null,
            'public' => false,
            'featured' => false,
            'file_source' => 'none',
            'base_dir' => null,
            'remove_after_attach' => true,
            'add_tags' => false,
            'skip_duplicates' => true,
        ), $options);
    }

    public function import(array $items)
    {
        foreach ($items as $item) {
            try {
                $this->importItem($item);
            } catch (Exception $e) {
                $this->_results['messages'][] = sprintf(
                    'Could not import "%s": %s',
                    isset($item['title']) ? $item['title'] : '(untitled)',
                    $e->getMessage()
                );
            }
        }
        return $this->_results;
    }

    protected function importItem(array $item)
    {
        $elementTexts = $this->_mapper->map($item);
        $title = $this->firstText($elementTexts, 'Title');

        if ($this->_options['skip_duplicates'] && $title && $this->titleExists($title)) {
            $this->_results['skipped']++;
            $this->_results['messages'][] = sprintf('Skipped "%s": an item with this title already exists.', $title);
            return;
        }

        $metadata = array(
            'public' => (bool) $this->_options['public'],
            'featured' => (bool) $this->_options['featured'],
        );
        if ($this->_options['collection_id']) {
            $metadata['collection_id'] = (int) $this->_options['collection_id'];
        }
        if ($this->_options['add_tags'] && !empty($item['tags'])) {
            $metadata['tags'] = $item['tags'];
        }

        $omekaItem = insert_item($metadata, $elementTexts);
        $this->_results['created']++;

        if ($this->_options['file_source'] !== 'none') {
            $this->attachFiles($omekaItem, $item);
        }

        release_object($omekaItem);
    }

    protected function attachFiles($omekaItem, array $item)
    {
        $paths = array();
        foreach ((isset($item['attachments']) ? $item['attachments'] : array()) as $attachment) {
            if (empty($attachment['path'])) {
                continue;
            }
            $path = $this->_options['file_source'] === 'dropbox'
                ? $this->resolveInDropbox($attachment['path'])
                : $this->resolvePath($attachment['path']);
            if ($path === null) {
                $this->_results['messages'][] = sprintf(
                    'File not found for "%s": %s',
                    isset($item['title']) ? $item['title'] : '(untitled)',
                    $attachment['path']
                );
                continue;
            }
            $paths[] = $path;
        }
        if (!$paths) {
            return;
        }
        // ignore_invalid_files defaults to false, so one rejected file would
        // abort the batch. Ingest singly and report refusals.
        foreach ($paths as $path) {
            try {
                insert_files_for_item($omekaItem, 'Filesystem', array($path));
                $this->_results['files']++;
                $this->removeStagedFile($path);
            } catch (Exception $e) {
                $this->_results['messages'][] = sprintf('Omeka refused "%s": %s',
                    basename($path), $e->getMessage());
            }
        }
    }

    /**
     * Omeka ingests by copy(), so staged files survive. Drain the Dropbox
     * folder as the Dropbox plugin does. An export folder is never touched:
     * it is the user's own data, not a staging area.
     */
    protected function removeStagedFile($path)
    {
        if ($this->_options['file_source'] !== 'dropbox'
            || !$this->_options['remove_after_attach']) {
            return;
        }
        $dir = $this->dropboxDir();
        if ($dir === null || strpos($path, $dir . DIRECTORY_SEPARATOR) !== 0) {
            return;
        }
        if (@unlink($path)) {
            $this->_results['removed']++;
        } else {
            $this->_results['messages'][] = sprintf(
                'Attached "%s" but could not remove it from the Dropbox folder; '
                . 'check write permissions.', basename($path));
        }
    }

    /**
     * Zotero nests files as "files/2/name.pdf"; the Dropbox folder is flat, so
     * match on filename. Attachments sharing a filename are indistinguishable.
     */
    protected function resolveInDropbox($relative)
    {
        $dir = $this->dropboxDir();
        if ($dir === null) {
            return null;
        }
        $name = basename(str_replace('\\', '/', rawurldecode($relative)));
        $candidate = realpath($dir . DIRECTORY_SEPARATOR . $name);
        if ($candidate === false || !is_file($candidate)) {
            return null;
        }
        if (strpos($candidate, $dir . DIRECTORY_SEPARATOR) !== 0) {
            return null;
        }
        return $candidate;
    }

    /** The Dropbox plugin's files directory, if that plugin is present. */
    public function dropboxDir()
    {
        if (!defined('PLUGIN_DIR')) {
            return null;
        }
        $dir = realpath(PLUGIN_DIR . '/Dropbox/files');
        return ($dir !== false && is_dir($dir)) ? $dir : null;
    }

    /** Resolve against the export directory, refusing anything that escapes it. */
    protected function resolvePath($relative)
    {
        $base = realpath($this->_options['base_dir']);
        if ($base === false) {
            return null;
        }
        $relative = str_replace('\\', '/', rawurldecode($relative));
        $candidate = realpath($base . DIRECTORY_SEPARATOR . $relative);

        if ($candidate === false || !is_file($candidate)) {
            // The export may be rooted a level up or down; fall back to basename.
            $candidate = $this->findByBasename($base, basename($relative));
        }
        if ($candidate === false || $candidate === null) {
            return null;
        }
        if (strpos($candidate, $base . DIRECTORY_SEPARATOR) !== 0) {
            return null;
        }
        return $candidate;
    }

    protected function findByBasename($base, $name)
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getFilename() === $name) {
                return $file->getRealPath();
            }
        }
        return null;
    }

    protected function titleExists($title)
    {
        $db = get_db();
        $sql = "
            SELECT COUNT(et.id) FROM {$db->ElementText} et
            JOIN {$db->Element} e ON e.id = et.element_id
            JOIN {$db->ElementSet} es ON es.id = e.element_set_id
            WHERE et.record_type = 'Item'
              AND es.name = 'Dublin Core'
              AND e.name = 'Title'
              AND et.text = ?";
        return (int) $db->fetchOne($sql, array($title)) > 0;
    }

    protected function firstText(array $elementTexts, $element)
    {
        return isset($elementTexts['Dublin Core'][$element][0]['text'])
            ? $elementTexts['Dublin Core'][$element][0]['text']
            : null;
    }
}
