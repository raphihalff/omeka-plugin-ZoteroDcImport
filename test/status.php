<?php
/**
 * Tests the import-report lifecycle with Omeka's option store stubbed.
 *
 * Run: php test/status.php
 */

$GLOBALS['options'] = array();
function set_option($name, $value) { $GLOBALS['options'][$name] = $value; }
function get_option($name) {
    return isset($GLOBALS['options'][$name]) ? $GLOBALS['options'][$name] : null;
}
function __($msg) {
    $args = array_slice(func_get_args(), 1);
    return $args ? vsprintf(str_replace('%s', '%s', $msg), $args) : $msg;
}
abstract class Omeka_Job_AbstractJob { protected $_options = array(); }

require __DIR__ . '/../models/ZoteroDcImport/Job.php';

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

/** Write a status as though it were $secondsAgo seconds old. */
function writeStatus($state, $secondsAgo) {
    set_option(ZoteroDcImport_Job::STATUS_OPTION, json_encode(array(
        'state' => $state,
        'message' => 'test',
        'updated' => date('c', time() - $secondsAgo),
    )));
}

/* ------------------------------------------------------------------ */

check(ZoteroDcImport_Job::currentStatus() === null, 'no status before any import');

writeStatus('done', 10);
$status = ZoteroDcImport_Job::currentStatus();
check($status !== null, 'a fresh report is shown');
check($status['ageLabel'] === 'just now', 'fresh report reads "just now"');

writeStatus('done', 2 * 3600);
$status = ZoteroDcImport_Job::currentStatus();
check($status !== null, 'a report from two hours ago is still shown');
check($status['ageLabel'] === '2 hours ago', 'age label: ' . $status['ageLabel']);

// The reported bug: a finished report stayed on the page indefinitely.
writeStatus('done', ZoteroDcImport_Job::FINISHED_TTL + 60);
check(ZoteroDcImport_Job::currentStatus() === null,
    'a finished report older than the TTL is dropped');

writeStatus('error', ZoteroDcImport_Job::FINISHED_TTL + 60);
check(ZoteroDcImport_Job::currentStatus() === null, 'an old error report is dropped too');

// A stuck job stays visible however old: that is the symptom worth seeing.
writeStatus('queued', 30 * 86400);
$status = ZoteroDcImport_Job::currentStatus();
check($status !== null, 'a stuck "queued" is still shown after a month');
check($status['ageLabel'] === '30 days ago', 'stuck age: ' . $status['ageLabel']);

writeStatus('running', 30 * 86400);
check(ZoteroDcImport_Job::currentStatus() !== null, 'a stuck "running" is still shown');

// Dismissing clears it.
writeStatus('done', 10);
ZoteroDcImport_Job::clear();
check(ZoteroDcImport_Job::currentStatus() === null, 'clear() dismisses the report');

// A new run replaces the previous report.
writeStatus('done', 10);
ZoteroDcImport_Job::setQueued();
$status = ZoteroDcImport_Job::currentStatus();
check($status['state'] === 'queued', 'queueing replaces the previous report');

// Malformed data must not fatal the admin page.
set_option(ZoteroDcImport_Job::STATUS_OPTION, 'not json');
check(ZoteroDcImport_Job::currentStatus() === null, 'malformed status is ignored');
set_option(ZoteroDcImport_Job::STATUS_OPTION, json_encode(array('no state' => 1)));
check(ZoteroDcImport_Job::currentStatus() === null, 'status without a state is ignored');

echo "\n" . ($failures ? "$failures failed\n" : "all passed\n");
exit($failures ? 1 : 0);
