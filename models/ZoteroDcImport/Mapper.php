<?php
/**
 * Maps a normalized Zotero item onto Dublin Core element texts.
 *
 * Every value lands in one of the 15 core Dublin Core elements; no element set
 * is created. Original-language values come first, since Omeka keeps element
 * texts in order and displays the first, leaving the romanization searchable
 * beneath it.
 *
 * Conventions: Title in the original script, Publisher the periodical name,
 * Source the full citation, Language spelled out.
 */
class ZoteroDcImport_Mapper
{
    /** Elements to fill; false leaves one empty. */
    public static $elements = array(
        'Title'       => true,
        'Creator'     => true,
        'Contributor' => true,
        'Subject'     => true,
        'Description' => true,
        'Source'      => true,
        'Publisher'   => true,
        'Date'        => true,
        'Language'    => true,
        'Identifier'  => true,
        'Rights'      => false,
        'Type'        => false,
        'Format'      => false,
        'Relation'    => false,
        'Coverage'    => false,
    );

    /** Include the romanization as a second value on the same element. */
    public static $includeRomanization = true;

    /** Added to Description when the original was reconstructed, not catalogued. */
    public static $reconstructedNote =
        'Original-language reading reconstructed from the romanization; not verified.';

    /**
     * Drop a trailing "(Place, 1926-1938)" disambiguator from periodical
     * titles. Only parentheticals containing a year, so titles that genuinely
     * end in brackets survive.
     */
    public static $stripPeriodicalQualifier = true;

    /** Creator types mapped to DC Creator; the rest become DC Contributor. */
    public static $creatorRoles = array('author', 'artist', 'cartographer',
        'composer', 'director', 'interviewee', 'inventor', 'performer',
        'podcaster', 'presenter', 'programmer');

    /** Language codes to spelled-out names. Unknown codes pass through. */
    public static $languages = array(
        'yid' => 'Yiddish', 'yi' => 'Yiddish',
        'heb' => 'Hebrew',  'he' => 'Hebrew',
        'eng' => 'English', 'en' => 'English',
        'ger' => 'German',  'deu' => 'German', 'de' => 'German',
        'pol' => 'Polish',  'pl' => 'Polish',
        'rus' => 'Russian', 'ru' => 'Russian',
        'fre' => 'French',  'fra' => 'French', 'fr' => 'French',
        'spa' => 'Spanish', 'es' => 'Spanish',
        'ukr' => 'Ukrainian', 'uk' => 'Ukrainian',
        'lit' => 'Lithuanian', 'lt' => 'Lithuanian',
        'hun' => 'Hungarian', 'hu' => 'Hungarian',
        'rum' => 'Romanian', 'ron' => 'Romanian', 'ro' => 'Romanian',
        'lat' => 'Latin', 'la' => 'Latin',
        'ara' => 'Arabic', 'ar' => 'Arabic',
        'por' => 'Portuguese', 'pt' => 'Portuguese',
        'ita' => 'Italian', 'it' => 'Italian',
        'dut' => 'Dutch', 'nld' => 'Dutch', 'nl' => 'Dutch',
        'cze' => 'Czech', 'ces' => 'Czech', 'cs' => 'Czech',
        'bel' => 'Belarusian', 'be' => 'Belarusian',
    );

    /** Item types to DC Type, used only when Type is enabled. */
    public static $types = array(
        'journalArticle'  => 'Text',
        'magazineArticle' => 'Text',
        'newspaperArticle' => 'Text',
        'bookSection'     => 'Text',
        'book'            => 'Text',
        'manuscript'      => 'Text',
        'thesis'          => 'Text',
        'letter'          => 'Text',
        'document'        => 'Text',
        'map'             => 'Image',
        'artwork'         => 'Image',
        'audioRecording'  => 'Sound',
        'videoRecording'  => 'MovingImage',
    );

    /**
     * @param array $item Normalized item from a parser.
     * @return array Omeka elementTexts structure for insert_item().
     */
    public function map(array $item)
    {
        $extra = $this->parseExtra(isset($item['extra']) ? $item['extra'] : '');
        $texts = array();

        $this->add($texts, 'Title', $this->pair(
            $this->firstExtra($extra, 'original-title'),
            isset($item['title']) ? $item['title'] : null
        ));

        list($creators, $contributors) = $this->creators($item, $extra);
        $this->add($texts, 'Creator', $creators);
        $this->add($texts, 'Contributor', $contributors);

        $this->add($texts, 'Subject', $this->subjects($item, $extra));

        $this->add($texts, 'Description', $this->descriptions($item, $extra));

        $this->add($texts, 'Source', $this->sources($item, $extra));

        $this->add($texts, 'Publisher', $this->publishers($item, $extra));

        $this->add($texts, 'Date', $this->values($item, 'date'));

        $this->add($texts, 'Language', $this->language($item));

        $this->add($texts, 'Identifier', $this->identifiers($item));

        $this->add($texts, 'Rights', $this->values($item, 'rights'));

        if (isset($item['itemType']) && isset(self::$types[$item['itemType']])) {
            $this->add($texts, 'Type', array(self::$types[$item['itemType']]));
        }

        return $texts;
    }

    /**
     * Extra carries "label: value" lines, including the original-script
     * readings this plugin reads back out.
     *
     * @return array lowercased label => list of values
     */
    public function parseExtra($extra)
    {
        $out = array();
        if (!strlen(trim((string) $extra))) {
            return $out;
        }
        foreach (preg_split('/\r\n|\r|\n/', $extra) as $line) {
            if (!preg_match('/^\s*([^:]{1,60}?)\s*:\s*(.+?)\s*$/u', $line, $m)) {
                continue;
            }
            $label = mb_strtolower($m[1], 'UTF-8');
            if (!isset($out[$label])) {
                $out[$label] = array();
            }
            $out[$label][] = $m[2];
        }
        return $out;
    }

    protected function firstExtra(array $extra, $label, $index = 0)
    {
        return isset($extra[$label][$index]) ? $extra[$label][$index] : null;
    }

    public function stripQualifier($title)
    {
        if (!self::$stripPeriodicalQualifier || $title === null) {
            return $title;
        }
        $pattern = '/\s*\(([^()]*\b(?:1[5-9][0-9]{2}|20[0-9]{2})\b[^()]*)\)\s*$/u';
        $stripped = preg_replace($pattern, '', $title);
        return ($stripped !== null && strlen(trim($stripped))) ? trim($stripped) : $title;
    }

    /** Original first, romanization second; blanks and duplicates dropped. */
    protected function pair($original, $romanized)
    {
        $out = array();
        foreach (array($original, $romanized) as $i => $value) {
            $value = trim((string) $value);
            if (!strlen($value)) {
                continue;
            }
            if ($i === 1 && (!self::$includeRomanization || in_array($value, $out, true))) {
                continue;
            }
            $out[] = $value;
        }
        // With no original, the romanization is the only reading there is.
        return $out;
    }

    protected function creators(array $item, array $extra)
    {
        $creators = array();
        $contributors = array();
        $originals = isset($extra['original-author']) ? $extra['original-author'] : array();

        $seen = 0;
        foreach ((isset($item['creators']) ? $item['creators'] : array()) as $creator) {
            $name = $this->formatName($creator);
            if (!strlen($name)) {
                continue;
            }
            $role = isset($creator['creatorType']) ? $creator['creatorType'] : 'author';
            $isCreator = in_array($role, self::$creatorRoles, true);

            // Extra lists originals in creator order, so pair them positionally.
            $original = isset($originals[$seen]) ? $originals[$seen] : null;
            $seen++;

            $values = $this->pair($original, $name);
            foreach ($values as $value) {
                if ($isCreator) {
                    $creators[] = $value;
                } else {
                    $contributors[] = $value;
                }
            }
        }
        return array($creators, $contributors);
    }

    /** Zotero stores names split; Dublin Core takes them inverted. */
    protected function formatName(array $creator)
    {
        if (!empty($creator['name'])) {
            return trim($creator['name']);
        }
        $last = isset($creator['lastName']) ? trim($creator['lastName']) : '';
        $first = isset($creator['firstName']) ? trim($creator['firstName']) : '';
        if (strlen($last) && strlen($first)) {
            return $last . ', ' . $first;
        }
        return strlen($last) ? $last : $first;
    }

    protected function subjects(array $item, array $extra)
    {
        $originals = isset($extra['original-subject']) ? $extra['original-subject'] : array();
        $tags = isset($item['tags']) ? $item['tags'] : array();
        $out = array();
        foreach ($tags as $i => $tag) {
            $original = isset($originals[$i]) ? $originals[$i] : null;
            foreach ($this->pair($original, $tag) as $value) {
                if (!in_array($value, $out, true)) {
                    $out[] = $value;
                }
            }
        }
        return $out;
    }

    /** Marker written into Extra by the reconstruction tool. */
    const RECONSTRUCTED = 'original-script';

    protected function descriptions(array $item, array $extra = array())
    {
        $out = array();
        if (isset($extra[self::RECONSTRUCTED]) && self::$reconstructedNote !== null) {
            $out[] = self::$reconstructedNote;
        }
        foreach (array('abstractNote') as $key) {
            if (!empty($item[$key])) {
                $out[] = trim($item[$key]);
            }
        }
        foreach ((isset($item['notes']) ? $item['notes'] : array()) as $note) {
            $note = trim(strip_tags((string) $note));
            if (strlen($note)) {
                $out[] = $note;
            }
        }
        return $out;
    }

    /** Source holds the containing publication's full citation. */
    protected function sources(array $item, array $extra)
    {
        $originalTitle = $this->firstExtra($extra, 'original-container-title');
        $originalCitation = $this->firstExtra($extra, 'original-citation');

        $originalTitle = $this->stripQualifier($originalTitle);
        if (!$originalTitle && !$originalCitation) {
            return $this->pair(null, $this->citation($item));
        }

        $original = null;
        if ($originalTitle || $originalCitation) {
            $original = trim(implode(', ', array_filter(array($originalTitle, $originalCitation))));
        }

        return $this->pair($original, $this->citation($item));
    }

    /** Citation built from the structured fields, for the romanized reading. */
    protected function citation(array $item)
    {
        if (empty($item['publicationTitle'])) {
            return null;
        }
        $parts = array($this->stripQualifier($item['publicationTitle']));
        if (!empty($item['volume'])) {
            $parts[] = 'v. ' . $item['volume'];
        }
        if (!empty($item['issue'])) {
            $parts[] = 'no. ' . $item['issue'];
        }
        if (!empty($item['date'])) {
            $parts[] = $item['date'];
        }
        if (!$parts) {
            return null;
        }
        $citation = implode(', ', $parts);
        if (!empty($item['pages'])) {
            $citation .= ': ' . $item['pages'];
        }
        return $citation;
    }

    protected function publishers(array $item, array $extra)
    {
        // For an article the periodical stands as the publisher.
        $original = $this->stripQualifier($this->firstExtra($extra, 'original-container-title'));
        $romanized = !empty($item['publicationTitle'])
            ? $item['publicationTitle']
            : (isset($item['publisher']) ? $item['publisher'] : null);

        if (!$original && !$romanized && !empty($item['publisher'])) {
            $romanized = $item['publisher'];
        }
        return $this->pair($original, $this->stripQualifier($romanized));
    }

    protected function language(array $item)
    {
        if (empty($item['language'])) {
            return array();
        }
        $raw = trim($item['language']);
        $key = mb_strtolower($raw, 'UTF-8');
        return array(isset(self::$languages[$key]) ? self::$languages[$key] : $raw);
    }

    protected function identifiers(array $item)
    {
        $out = array();
        foreach (array('url', 'DOI', 'ISBN', 'ISSN', 'callNumber') as $key) {
            if (!empty($item[$key])) {
                $out[] = trim($item[$key]);
            }
        }
        return $out;
    }

    protected function values(array $item, $key)
    {
        return empty($item[$key]) ? array() : array(trim($item[$key]));
    }

    protected function add(array &$texts, $element, array $values)
    {
        if (empty(self::$elements[$element]) || !$values) {
            return;
        }
        foreach ($values as $value) {
            $value = trim((string) $value);
            if (!strlen($value)) {
                continue;
            }
            $texts['Dublin Core'][$element][] = array(
                'text' => $value,
                'html' => false,
            );
        }
    }
}
