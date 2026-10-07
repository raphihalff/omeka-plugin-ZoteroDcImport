<?php
/**
 * Parses a Zotero CSL JSON export into the same shape as the RDF parser.
 * Metadata only: no attachments, and Extra arrives as "note".
 */
class ZoteroDcImport_Parser_CslJson
{
    /** CSL type => Zotero item type. */
    protected static $_types = array(
        'article-journal'   => 'journalArticle',
        'article-magazine'  => 'magazineArticle',
        'article-newspaper' => 'newspaperArticle',
        'chapter'           => 'bookSection',
        'book'              => 'book',
        'manuscript'        => 'manuscript',
        'thesis'            => 'thesis',
        'personal_communication' => 'letter',
        'map'               => 'map',
        'graphic'           => 'artwork',
        'song'              => 'audioRecording',
        'motion_picture'    => 'videoRecording',
        'document'          => 'document',
    );

    protected static $_roles = array(
        'author' => 'author', 'editor' => 'editor', 'translator' => 'translator',
        'container-author' => 'bookAuthor', 'collection-editor' => 'seriesEditor',
        'director' => 'director', 'composer' => 'composer',
        'interviewer' => 'interviewer', 'recipient' => 'recipient',
        'illustrator' => 'contributor',
    );

    public function parse($json)
    {
        $data = json_decode($json, true);
        if ($data === null) {
            throw new Exception('Could not parse the JSON file: ' . json_last_error_msg());
        }
        if (isset($data['id']) || isset($data['title'])) {
            $data = array($data);
        }
        if (!is_array($data)) {
            throw new Exception('The JSON file does not contain a list of items.');
        }

        $items = array();
        foreach ($data as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $item = $this->parseItem($entry);
            if (!empty($item['title'])) {
                $items[] = $item;
            }
        }
        return $items;
    }

    protected function parseItem(array $entry)
    {
        $type = isset($entry['type']) ? $entry['type'] : '';
        $item = array(
            'itemType' => isset(self::$_types[$type]) ? self::$_types[$type] : 'document',
            'title' => $this->str($entry, 'title'),
            'publicationTitle' => $this->str($entry, 'container-title'),
            'volume' => $this->str($entry, 'volume'),
            'issue' => $this->str($entry, 'issue'),
            'pages' => $this->str($entry, 'page'),
            'publisher' => $this->str($entry, 'publisher'),
            'language' => $this->str($entry, 'language'),
            'abstractNote' => $this->str($entry, 'abstract'),
            'rights' => $this->str($entry, 'rights'),
            'url' => $this->str($entry, 'URL'),
            'DOI' => $this->str($entry, 'DOI'),
            'ISBN' => $this->str($entry, 'ISBN'),
            'ISSN' => $this->str($entry, 'ISSN'),
            'callNumber' => $this->str($entry, 'call-number'),
            // Zotero writes the Extra field here.
            'extra' => $this->str($entry, 'note'),
            'date' => $this->date($entry),
            'creators' => $this->creators($entry),
            'tags' => array(),
            'notes' => array(),
            'attachments' => array(),
        );

        return array_filter($item, function ($v) {
            return $v !== null && $v !== '' && $v !== array();
        });
    }

    protected function creators(array $entry)
    {
        $out = array();
        foreach (self::$_roles as $cslRole => $zoteroRole) {
            if (empty($entry[$cslRole]) || !is_array($entry[$cslRole])) {
                continue;
            }
            foreach ($entry[$cslRole] as $person) {
                if (!is_array($person)) {
                    continue;
                }
                if (isset($person['literal'])) {
                    $out[] = array('creatorType' => $zoteroRole, 'name' => $person['literal']);
                    continue;
                }
                $out[] = array(
                    'creatorType' => $zoteroRole,
                    'lastName' => isset($person['family']) ? $person['family'] : '',
                    'firstName' => isset($person['given']) ? $person['given'] : '',
                );
            }
        }
        return $out;
    }

    /** CSL dates are date-parts arrays, with "raw"/"literal" as fallbacks. */
    protected function date(array $entry)
    {
        foreach (array('issued', 'event-date', 'original-date') as $key) {
            if (empty($entry[$key]) || !is_array($entry[$key])) {
                continue;
            }
            $date = $entry[$key];
            if (!empty($date['date-parts'][0])) {
                $parts = array_filter($date['date-parts'][0], function ($p) {
                    return $p !== null && $p !== '';
                });
                if ($parts) {
                    return implode('-', array_map(function ($p) {
                        return is_numeric($p) && $p < 10 ? '0' . (int) $p : (string) $p;
                    }, $parts));
                }
            }
            foreach (array('raw', 'literal') as $fallback) {
                if (!empty($date[$fallback])) {
                    return (string) $date[$fallback];
                }
            }
        }
        return null;
    }

    protected function str(array $entry, $key)
    {
        if (!isset($entry[$key]) || is_array($entry[$key])) {
            return null;
        }
        $value = trim((string) $entry[$key]);
        return strlen($value) ? $value : null;
    }
}
