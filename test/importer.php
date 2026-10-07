<?php
/**
 * Tests for file attachment and the removal of staged files, with Omeka's
 * global helpers stubbed. Removal deletes real files, so the rules that decide
 * whether to delete are worth proving on real ones.
 *
 * Run: php test/importer.php
 */

$GLOBALS['inserted'] = array();
$GLOBALS['ingested'] = array();
$GLOBALS['refuse'] = array();

function insert_item($metadata = array(), $elementTexts = array()) {
    $item = new stdClass();
    $item->id = count($GLOBALS['inserted']) + 1;
    $GLOBALS['inserted'][] = array($metadata, $elementTexts);
    return $item;
}

function insert_files_for_item($item, $strategy, $paths, $options = array()) {
    foreach ($paths as $path) {
        if (in_array(basename($path), $GLOBALS['refuse'], true)) {
            throw new Exception('invalid file');
        }
        $GLOBALS['ingested'][] = $path;
    }
    return array();
}

function release_object(&$var) { $var = null; }
function get_db() { throw new Exception('the database should not be touched here'); }

$tmp = sys_get_temp_dir() . '/zdc-test-' . bin2hex(random_bytes(4));
mkdir($tmp . '/plugins/Dropbox/files', 0700, true);
mkdir($tmp . '/export/files/2', 0700, true);
define('PLUGIN_DIR', $tmp . '/plugins');

require __DIR__ . '/../models/ZoteroDcImport/Mapper.php';
require __DIR__ . '/../models/ZoteroDcImport/Importer.php';

$failures = 0;
function check($condition, $message) {
    global $failures;
    if ($condition) {
        echo "  ok   $message\n";
    } else {
        $failures++;
        echo "  FAIL $message\n";
    }
}

function stage($path) {
    file_put_contents($path, '%PDF-1.4 test');
    return $path;
}

function itemWith($path) {
    return array(
        'title' => 'Test', 'itemType' => 'book',
        'attachments' => array(array('path' => $path, 'title' => 'Scan')),
    );
}

function run(array $options, array $item) {
    $GLOBALS['ingested'] = array();
    $importer = new ZoteroDcImport_Importer(array_merge(
        array('skip_duplicates' => false), $options));
    return $importer->import(array($item));
}

/* ------------------------------------------------------------------ */

$dropbox = $tmp . '/plugins/Dropbox/files';

stage($dropbox . '/a.pdf');
$results = run(array('file_source' => 'dropbox', 'remove_after_attach' => true),
    itemWith('files/2/a.pdf'));
check($results['files'] === 1, 'dropbox: file attached');
check($results['removed'] === 1, 'dropbox: counted as removed');
check(!file_exists($dropbox . '/a.pdf'), 'dropbox: staged file deleted after attaching');

stage($dropbox . '/b.pdf');
$results = run(array('file_source' => 'dropbox', 'remove_after_attach' => false),
    itemWith('files/2/b.pdf'));
check($results['files'] === 1, 'dropbox, removal off: file attached');
check(file_exists($dropbox . '/b.pdf'), 'dropbox, removal off: staged file kept');
unlink($dropbox . '/b.pdf');

// An export folder is the user's own Zotero export and must never be pruned.
stage($tmp . '/export/files/2/c.pdf');
$results = run(array('file_source' => 'path', 'base_dir' => $tmp . '/export',
    'remove_after_attach' => true), itemWith('files/2/c.pdf'));
check($results['files'] === 1, 'export folder: file attached');
check($results['removed'] === 0, 'export folder: nothing reported removed');
check(file_exists($tmp . '/export/files/2/c.pdf'),
    'export folder: file left in place even with removal requested');

// A file Omeka refuses must not be deleted.
stage($dropbox . '/bad.pdf');
$GLOBALS['refuse'] = array('bad.pdf');
$results = run(array('file_source' => 'dropbox', 'remove_after_attach' => true),
    itemWith('files/2/bad.pdf'));
$GLOBALS['refuse'] = array();
check($results['files'] === 0, 'refused file: not counted as attached');
check(file_exists($dropbox . '/bad.pdf'), 'refused file: kept in the Dropbox folder');
check(count($results['messages']) > 0, 'refused file: reported');
unlink($dropbox . '/bad.pdf');

// A missing file is reported, not fatal.
$results = run(array('file_source' => 'dropbox', 'remove_after_attach' => true),
    itemWith('files/2/absent.pdf'));
check($results['files'] === 0, 'missing file: nothing attached');
check($results['created'] === 1, 'missing file: the item still imports');

// Containment: a traversing path must not reach outside the Dropbox folder.
stage($tmp . '/outside.pdf');
$results = run(array('file_source' => 'dropbox', 'remove_after_attach' => true),
    itemWith('../../../outside.pdf'));
check(file_exists($tmp . '/outside.pdf'),
    'traversal: a file outside the Dropbox folder is neither attached nor deleted');
check($results['files'] === 0, 'traversal: nothing attached');

/* ------------------------------------------------------------------ */

// Clean up.
foreach (new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST) as $path) {
    $path->isDir() ? rmdir($path->getPathname()) : unlink($path->getPathname());
}
rmdir($tmp);

echo "\n" . ($failures ? "$failures failed\n" : "all passed\n");
exit($failures ? 1 : 0);
