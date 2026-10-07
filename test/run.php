<?php
/**
 * Standalone tests for the parser and mapper. These classes take plain arrays
 * and strings, so they run without Omeka bootstrapped.
 *
 * Run: php test/run.php
 */

require __DIR__ . '/../models/ZoteroDcImport/Parser/Rdf.php';
require __DIR__ . '/../models/ZoteroDcImport/Parser/CslJson.php';
require __DIR__ . '/../models/ZoteroDcImport/Mapper.php';

$tests = array();
$failures = 0;

function test($name, $fn) {
    global $tests;
    $tests[] = array($name, $fn);
}

function ok($condition, $message) {
    if (!$condition) {
        throw new Exception($message);
    }
}

function same($expected, $actual, $message) {
    if ($expected !== $actual) {
        throw new Exception($message . "\n       expected: " . var_export($expected, true)
            . "\n       actual:   " . var_export($actual, true));
    }
}

/** Flatten the elementTexts structure to element => list of strings. */
function flatten(array $texts) {
    $out = array();
    foreach ($texts['Dublin Core'] as $element => $values) {
        foreach ($values as $value) {
            $out[$element][] = $value['text'];
        }
    }
    return $out;
}

function mapFixture($file, $index = 0) {
    $parser = new ZoteroDcImport_Parser_Rdf();
    $items = $parser->parse(file_get_contents(__DIR__ . '/fixtures/' . $file));
    $mapper = new ZoteroDcImport_Mapper();
    return array($items[$index], flatten($mapper->map($items[$index])));
}

/* ------------------------------------------------------------------ */

test('RDF parsing finds the items and skips attachments and containers', function () {
    $parser = new ZoteroDcImport_Parser_Rdf();
    $items = $parser->parse(file_get_contents(__DIR__ . '/fixtures/nli-article.rdf'));
    same(3, count($items), 'expected 3 items (article + book + untyped)');
    same('journalArticle', $items[0]['itemType'], 'first item type');
    same('book', $items[1]['itemType'], 'second item type');
    // rdf:Description carries the types with no biblio class.
    same('encyclopediaArticle', $items[2]['itemType'], 'untyped item');
    same('Singer, Israel Joshua', $items[2]['title'], 'untyped item title');
});

test('the article parses into normalized fields', function () {
    list($item, ) = mapFixture('nli-article.rdf');
    same("S'iz umetik on Lutski", $item['title'], 'title');
    same('1957', $item['date'], 'date');
    same('4', $item['pages'], 'pages');
    same('yid', $item['language'], 'language');
    same('Ilustrirte literarishe bleter (Buenos-Ayres, 1953–1960)',
        $item['publicationTitle'], 'container title keeps its qualifier when parsed');
    same('5', $item['volume'], 'volume');
    same('9-10', $item['issue'], 'issue');
    same(array('Lutski, A.', 'Nekrolog'), $item['tags'], 'tags');
    same('Pet', $item['creators'][0]['lastName'], 'creator surname');
    same('author', $item['creators'][0]['creatorType'], 'creator role');
    ok(strpos($item['url'], 'merhav.nli.org.il') !== false, 'url: ' . $item['url']);
    same('files/2/lutski.pdf', $item['attachments'][0]['path'], 'attachment path');
    same('application/pdf', $item['attachments'][0]['mimeType'], 'attachment type');
});

test('attachment paths come from the rdf:resource attribute', function () {
    list($item, ) = mapFixture('nli-article.rdf');
    same('files/2/lutski.pdf', $item['attachments'][0]['path'], 'z:path as attribute');
});

test('a call number on dc:subject is not imported as a Subject', function () {
    list($item, $dc) = mapFixture('nli-article.rdf');
    same('IYP no. 4919', $item['callNumber'], 'callNumber');
    ok(!in_array('IYP no. 4919', $dc['Subject'], true),
        'call number leaked into Subject: ' . implode(' | ', $dc['Subject']));
});

test('the periodical qualifier is stripped from Publisher and Source', function () {
    list(, $dc) = mapFixture('nli-article.rdf');
    foreach ($dc['Publisher'] as $publisher) {
        ok(strpos($publisher, '(') === false, 'qualifier left in Publisher: ' . $publisher);
    }
    // Only the periodical name is checked: the citation that follows carries
    // its own parentheses for the whole number, "(49-50)".
    foreach ($dc['Source'] as $source) {
        $periodical = explode(',', $source);
        ok(strpos($periodical[0], '(') === false,
            'qualifier left in Source: ' . $source);
    }
    ok(strpos($dc['Source'][0], '(49') !== false, 'whole number should survive');
});

test('the original language comes first in every element', function () {
    list(, $dc) = mapFixture('nli-article.rdf');

    same(array("ס'איז אומעטיק אָן לוצקי", "S'iz umetik on Lutski"), $dc['Title'], 'Title');
    same(array('פּעט, חיים', 'Pet, Khayim'), $dc['Creator'], 'Creator');
    same(array('אילוסטרירטע ליטעראַרישע בלעטער', 'Ilustrirte literarishe bleter'),
        $dc['Publisher'], 'Publisher');
    same(array('לוצקי, אַ.', 'Lutski, A.', 'נעקראָלאָג', 'Nekrolog'), $dc['Subject'], 'Subject');
});

test('Source carries the whole citation, original first', function () {
    list(, $dc) = mapFixture('nli-article.rdf');
    same("אילוסטרירטע ליטעראַרישע בלעטער, 5, נומ' 9–10 (49–50), סעפּטעמבער–אָקטאָבער 1957, ז' 4",
        $dc['Source'][0], 'original citation');
    same('Ilustrirte literarishe bleter, v. 5, no. 9-10, 1957: 4',
        $dc['Source'][1], 'romanized citation');
});

test('a reconstructed original is flagged in Description', function () {
    list(, $dc) = mapFixture('reconstructed.rdf');
    same(array('טאָג-טעגלעך ברױט', 'Tog-teglekh broyt'), $dc['Title'], 'Title');
    same(array('װײַנפּער, זישע', 'Vaynper, Zishe'), $dc['Creator'], 'Creator');
    ok(isset($dc['Description'][0]) && strpos($dc['Description'][0], 'reconstructed') !== false,
        'expected a reconstruction note in Description');
});

test('a record whose original was catalogued carries no note', function () {
    list(, $dc) = mapFixture('reconstructed.rdf', 1);
    same(array('אין אונדזער ליטעראַטור', 'In undzer literatur'), $dc['Title'], 'Title');
    ok(!isset($dc['Description']), 'no note expected for a catalogued original');
});

test('a standalone work gets no Source', function () {
    // Source describes the containing publication; a book has none.
    list(, $dc) = mapFixture('reconstructed.rdf');
    ok(!isset($dc['Source']), 'Source should be absent, got: '
        . (isset($dc['Source']) ? implode(' | ', $dc['Source']) : ''));
});

test('language is spelled out the way the archive writes it', function () {
    list(, $dc) = mapFixture('nli-article.rdf');
    same(array('Yiddish'), $dc['Language'], 'Language');
});

test('no element set other than Dublin Core is produced', function () {
    $parser = new ZoteroDcImport_Parser_Rdf();
    $items = $parser->parse(file_get_contents(__DIR__ . '/fixtures/nli-article.rdf'));
    $mapper = new ZoteroDcImport_Mapper();
    $texts = $mapper->map($items[0]);
    same(array('Dublin Core'), array_keys($texts), 'element sets');

    $allowed = array('Title', 'Creator', 'Contributor', 'Subject', 'Description',
        'Source', 'Publisher', 'Date', 'Language', 'Identifier', 'Rights',
        'Type', 'Format', 'Relation', 'Coverage');
    foreach (array_keys($texts['Dublin Core']) as $element) {
        ok(in_array($element, $allowed, true), 'unexpected element: ' . $element);
    }
});

test('non-author creators become Contributor', function () {
    list(, $dc) = mapFixture('nli-article.rdf', 1);
    same(array('סוצקעװער, אַבֿרהם', 'Sutskever, Avrom'), $dc['Creator'], 'Creator');
    same(array('Niborski, Yitskhok'), $dc['Contributor'], 'Contributor');
});

test('an item with no original-language reading still imports', function () {
    $mapper = new ZoteroDcImport_Mapper();
    $dc = flatten($mapper->map(array(
        'title' => 'The Soviet Klezmer Orchestra',
        'creators' => array(array('creatorType' => 'author',
            'lastName' => 'Wollock', 'firstName' => 'Jeffrey')),
        'publicationTitle' => 'East European Jewish Affairs',
        'volume' => '30', 'issue' => '2', 'date' => '2000', 'pages' => '1-36',
        'language' => 'en',
    )));
    same(array('The Soviet Klezmer Orchestra'), $dc['Title'], 'Title');
    same(array('Wollock, Jeffrey'), $dc['Creator'], 'Creator');
    same(array('English'), $dc['Language'], 'Language');
    same(array('East European Jewish Affairs, v. 30, no. 2, 2000: 1-36'), $dc['Source'], 'Source');
});

test('romanization can be switched off', function () {
    ZoteroDcImport_Mapper::$includeRomanization = false;
    list(, $dc) = mapFixture('nli-article.rdf');
    ZoteroDcImport_Mapper::$includeRomanization = true;

    same(array("ס'איז אומעטיק אָן לוצקי"), $dc['Title'], 'Title');
    same(array('פּעט, חיים'), $dc['Creator'], 'Creator');
});

test('disabled elements are not written', function () {
    ZoteroDcImport_Mapper::$elements['Identifier'] = false;
    list(, $dc) = mapFixture('nli-article.rdf');
    ZoteroDcImport_Mapper::$elements['Identifier'] = true;
    ok(!isset($dc['Identifier']), 'Identifier should be absent');
});

test('Extra parses into labelled values', function () {
    $mapper = new ZoteroDcImport_Mapper();
    $extra = $mapper->parseExtra("original-title: א\noriginal-subject: ב\noriginal-subject: ג\nnonsense line");
    same(array('א'), $extra['original-title'], 'original-title');
    same(array('ב', 'ג'), $extra['original-subject'], 'repeated labels');
    ok(!isset($extra['nonsense line']), 'lines without a colon are ignored');
});

test('CSL JSON imports as a metadata-only fallback', function () {
    $parser = new ZoteroDcImport_Parser_CslJson();
    $items = $parser->parse(file_get_contents(__DIR__ . '/fixtures/nli-article.json'));
    same(1, count($items), 'one item');
    $mapper = new ZoteroDcImport_Mapper();
    $dc = flatten($mapper->map($items[0]));
    same(array("ס'איז אומעטיק אָן לוצקי", "S'iz umetik on Lutski"), $dc['Title'], 'Title');
    same(array('פּעט, חיים', 'Pet, Khayim'), $dc['Creator'], 'Creator');
    same(array('1957'), $dc['Date'], 'Date');
});

/* ------------------------------------------------------------------ */

foreach ($tests as $t) {
    list($name, $fn) = $t;
    try {
        $fn();
        echo "  ok   $name\n";
    } catch (Exception $e) {
        $failures++;
        echo "  FAIL $name\n       " . str_replace("\n", "\n       ", $e->getMessage()) . "\n";
    }
}
echo "\n" . (count($tests) - $failures) . '/' . count($tests) . " passed\n";
exit($failures ? 1 : 0);
