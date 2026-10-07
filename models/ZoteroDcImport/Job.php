<?php
/**
 * Runs an import in the background, beyond the web request's time and memory
 * limits. Progress is written to the options table for the admin page.
 */
class ZoteroDcImport_Job extends Omeka_Job_AbstractJob
{
    const STATUS_OPTION = 'zotero_dc_import_status';

    /** A finished report stops being shown after this long. */
    const FINISHED_TTL = 86400;

    public function perform()
    {
        $path = $this->_options['path'];
        $importOptions = $this->_options['import'];
        $settings = $this->_options['settings'];

        ZoteroDcImport_Mapper::$elements = $settings['elements'];
        ZoteroDcImport_Mapper::$includeRomanization = $settings['includeRomanization'];

        $this->status(array('state' => 'running', 'created' => 0, 'message' => 'Reading the export...'));

        try {
            $contents = file_get_contents($path);
            if ($contents === false) {
                throw new Exception('Could not read ' . $path);
            }
            $parser = strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'json'
                ? new ZoteroDcImport_Parser_CslJson()
                : new ZoteroDcImport_Parser_Rdf();
            $items = $parser->parse($contents);
            unset($contents);

            $total = count($items);
            $this->status(array('state' => 'running', 'total' => $total,
                'created' => 0, 'message' => 'Importing...'));

            $importer = new ZoteroDcImport_Importer($importOptions);

            // Slice so progress is visible.
            $results = array('created' => 0, 'skipped' => 0, 'files' => 0,
                'removed' => 0, 'messages' => array());
            $chunkSize = 25;
            for ($offset = 0; $offset < $total; $offset += $chunkSize) {
                $chunk = array_slice($items, $offset, $chunkSize);
                $chunkResults = $importer->import($chunk);
                foreach (array('created', 'skipped', 'files', 'removed') as $key) {
                    $results[$key] = $chunkResults[$key];
                }
                $results['messages'] = $chunkResults['messages'];

                $this->status(array(
                    'state' => 'running',
                    'total' => $total,
                    'created' => $results['created'],
                    'skipped' => $results['skipped'],
                    'files' => $results['files'],
                    'message' => sprintf('Imported %d of %d...', $offset + count($chunk), $total),
                ));
            }

            $this->status(array(
                'state' => 'done',
                'total' => $total,
                'created' => $results['created'],
                'skipped' => $results['skipped'],
                'files' => $results['files'],
                'messages' => array_slice($results['messages'], 0, 100),
                'removed' => $results['removed'],
                'message' => sprintf('Finished: %d imported, %d files attached '
                    . '(%d cleared from Dropbox), %d skipped.',
                    $results['created'], $results['files'],
                    $results['removed'], $results['skipped']),
            ));
        } catch (Exception $e) {
            $this->status(array('state' => 'error', 'message' => $e->getMessage()));
            throw $e;
        }
    }

    /**
     * Written before dispatch so the page always has something to show.
     * Stuck on "queued" means the job runner never started it.
     */
    public static function setQueued($total = null)
    {
        self::write(array(
            'state' => 'queued',
            'message' => 'Queued. If this does not change, the background job '
                . 'runner is not working on this server.',
        ));
    }

    public static function setError($message)
    {
        self::write(array('state' => 'error', 'message' => $message));
    }

    protected static function write(array $status)
    {
        $status['updated'] = date('c');
        set_option(self::STATUS_OPTION, json_encode($status));
    }

    protected function status(array $status)
    {
        self::write($status);
    }

    /**
     * The last status, or null. A finished run is dropped once it is older
     * than FINISHED_TTL; queued and running are always returned, since those
     * mean a job is active or stuck.
     */
    public static function currentStatus()
    {
        $raw = get_option(self::STATUS_OPTION);
        $status = $raw ? json_decode($raw, true) : null;
        if (!is_array($status) || !isset($status['state'])) {
            return null;
        }
        $age = self::age($status);
        if (in_array($status['state'], array('done', 'error'), true)
            && $age !== null && $age > self::FINISHED_TTL) {
            return null;
        }
        $status['age'] = $age;
        $status['ageLabel'] = self::describeAge($age);
        return $status;
    }

    /** "4 minutes ago" and the like; Omeka has no relative-time helper. */
    public static function describeAge($seconds)
    {
        if ($seconds === null) {
            return null;
        }
        $units = array(
            array(86400, 'day', 'days'),
            array(3600, 'hour', 'hours'),
            array(60, 'minute', 'minutes'),
        );
        foreach ($units as $unit) {
            list($size, $one, $many) = $unit;
            if ($seconds >= $size) {
                $count = (int) floor($seconds / $size);
                return __('%s ago', $count . ' ' . ($count === 1 ? $one : $many));
            }
        }
        return __('just now');
    }

    /** Seconds since the status was written, or null if unknown. */
    public static function age(array $status)
    {
        if (empty($status['updated'])) {
            return null;
        }
        $written = strtotime($status['updated']);
        return $written === false ? null : max(0, time() - $written);
    }

    public static function clear()
    {
        set_option(self::STATUS_OPTION, '');
    }
}
