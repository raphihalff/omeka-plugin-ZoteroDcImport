<?php
/**
 * Parses a Zotero RDF export into normalized item arrays.
 *
 * Structure, per Zotero's own RDF export translator:
 *   bib:Article / bib:Book / rdf:Description   the item, plus z:itemType
 *   bib:authors > rdf:Seq > foaf:Person        surname, givenName
 *   dcterms:isPartOf > bib:Journal             title, prism:volume/number
 *   dc:description                             the Extra field
 *   dc:subject                                 tags, or dcterms:LCC call numbers
 *   link:link > z:Attachment                   z:path, link:type
 */
class ZoteroDcImport_Parser_Rdf
{
    const NS_RDF     = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#';
    const NS_BIB     = 'http://purl.org/net/biblio#';
    const NS_DC      = 'http://purl.org/dc/elements/1.1/';
    const NS_DCTERMS = 'http://purl.org/dc/terms/';
    const NS_PRISM   = 'http://prismstandard.org/namespaces/1.2/basic/';
    const NS_FOAF    = 'http://xmlns.com/foaf/0.1/';
    const NS_LINK    = 'http://purl.org/rss/1.0/modules/link/';
    const NS_Z       = 'http://www.zotero.org/namespaces/export#';

    protected $_xpath;
    protected $_doc;

    /** @var array rdf:about/nodeID => DOMElement */
    protected $_byId = array();

    /**
     * @param string $xml
     * @return array List of normalized items.
     * @throws Exception on unparseable XML.
     */
    public function parse($xml)
    {
        $previous = libxml_use_internal_errors(true);
        $this->_doc = new DOMDocument();
        $loaded = $this->_doc->loadXML($xml);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            $message = $errors ? trim($errors[0]->message) : 'unknown error';
            throw new Exception('Could not parse the RDF file: ' . $message);
        }

        $this->_xpath = new DOMXPath($this->_doc);
        foreach (array('rdf' => self::NS_RDF, 'bib' => self::NS_BIB,
                     'dc' => self::NS_DC, 'dcterms' => self::NS_DCTERMS,
                     'prism' => self::NS_PRISM, 'foaf' => self::NS_FOAF,
                     'link' => self::NS_LINK, 'z' => self::NS_Z) as $prefix => $uri) {
            $this->_xpath->registerNamespace($prefix, $uri);
        }

        $this->indexNodes();

        $items = array();
        foreach ($this->_doc->documentElement->childNodes as $node) {
            if ($node->nodeType !== XML_ELEMENT_NODE) {
                continue;
            }
            if ($this->isAttachment($node) || $this->isSupporting($node)) {
                continue;
            }
            $item = $this->parseItem($node);
            if ($item && !empty($item['title'])) {
                $items[] = $item;
            }
        }
        return $items;
    }

    protected function indexNodes()
    {
        foreach ($this->_xpath->query('//*[@rdf:about or @rdf:nodeID]') as $node) {
            $id = $node->getAttributeNS(self::NS_RDF, 'about');
            if (!$id) {
                $id = '_:' . $node->getAttributeNS(self::NS_RDF, 'nodeID');
            }
            if ($id !== '' && !isset($this->_byId[$id])) {
                $this->_byId[$id] = $node;
            }
        }
    }

    protected function isAttachment(DOMElement $node)
    {
        return $this->hasType($node, self::NS_Z, 'Attachment');
    }

    /** True for a typed element, or rdf:Description with a matching rdf:type. */
    protected function hasType(DOMElement $node, $ns, $localName)
    {
        if ($node->namespaceURI === $ns && $node->localName === $localName) {
            return true;
        }
        if ($node->localName !== 'Description') {
            return false;
        }
        $type = $node->getAttributeNS(self::NS_RDF, 'type');
        if ($type === $ns . $localName) {
            return true;
        }
        foreach ($this->_xpath->query('./rdf:type', $node) as $typeNode) {
            if ($typeNode->getAttributeNS(self::NS_RDF, 'resource') === $ns . $localName) {
                return true;
            }
        }
        return false;
    }

    /** Containers, people, tags and collections are reached via their parent. */
    protected function isSupporting(DOMElement $node)
    {
        $supporting = array(
            self::NS_BIB => array('Journal', 'Periodical', 'Newspaper', 'Series',
                'CourtReporter', 'Memo'),
            self::NS_Z   => array('Collection', 'AutomaticTag', 'Blog', 'Forum', 'Website'),
            self::NS_FOAF => array('Person'),
        );
        foreach ($supporting as $ns => $names) {
            foreach ($names as $name) {
                if ($this->hasType($node, $ns, $name)) {
                    return true;
                }
            }
        }
        return false;
    }

    protected function parseItem(DOMElement $node)
    {
        $item = array(
            'itemType' => $this->text($node, 'z:itemType'),
            'title' => $this->text($node, 'dc:title'),
            'date' => $this->text($node, 'dc:date'),
            'pages' => $this->text($node, 'bib:pages'),
            'extra' => $this->text($node, 'dc:description'),
            'abstractNote' => $this->text($node, 'dcterms:abstract'),
            'rights' => $this->text($node, 'dc:rights'),
            'language' => $this->text($node, 'z:language'),
            'shortTitle' => $this->text($node, 'z:shortTitle'),
            'libraryCatalog' => $this->text($node, 'z:libraryCatalog'),
            'archive' => $this->text($node, 'z:archive'),
            'callNumber' => $this->callNumber($node),
            'creators' => $this->creators($node),
            'tags' => $this->tags($node),
            'notes' => $this->notes($node),
            'attachments' => $this->attachments($node),
            'url' => $this->url($node),
        );

        $item = array_merge($item, $this->container($node));
        $item = array_merge($item, $this->identifiers($node));

        return array_filter($item, function ($v) {
            return $v !== null && $v !== '' && $v !== array();
        });
    }

    /**
     * dc:identifier > dcterms:URI > rdf:value. The URI node may be typed or an
     * rdf:Description, so match rdf:value anywhere beneath.
     */
    protected function url(DOMElement $node)
    {
        $direct = $this->text($node, 'z:url');
        if ($direct) {
            return $direct;
        }
        foreach ($this->_xpath->query('./dc:identifier', $node) as $identifier) {
            $scope = $this->resolve($identifier);
            $scope = $scope ? $scope : $identifier;
            foreach ($this->_xpath->query('.//rdf:value | ./rdf:value', $scope) as $value) {
                $text = trim($value->textContent);
                if (strlen($text)) {
                    return $text;
                }
            }
        }
        return null;
    }

    protected function identifiers(DOMElement $node)
    {
        $out = array();
        $targets = array($node);
        foreach ($this->_xpath->query('./dcterms:isPartOf', $node) as $part) {
            $resolved = $this->resolve($part);
            if ($resolved) {
                $targets[] = $resolved;
            }
        }
        foreach ($targets as $target) {
            foreach ($this->_xpath->query('./dc:identifier', $target) as $identifier) {
                $text = trim($identifier->textContent);
                if (preg_match('/^(ISSN|ISBN|DOI)\s+(.+)$/i', $text, $m)) {
                    $key = strtoupper($m[1]);
                    if (!isset($out[$key])) {
                        $out[$key] = trim($m[2]);
                    }
                }
            }
        }
        return $out;
    }

    protected function container(DOMElement $node)
    {
        foreach ($this->_xpath->query('./dcterms:isPartOf', $node) as $part) {
            $container = $this->resolve($part);
            if (!$container) {
                continue;
            }
            // A bib:Series wrapping the journal is skipped over.
            if ($this->hasType($container, self::NS_BIB, 'Series')) {
                continue;
            }
            return array_filter(array(
                'publicationTitle' => $this->text($container, 'dc:title'),
                'volume' => $this->text($container, 'prism:volume'),
                'issue' => $this->text($container, 'prism:number'),
            ));
        }
        return array();
    }

    protected function creators(DOMElement $node)
    {
        $out = array();
        $expr = './bib:authors | ./bib:editors | ./bib:contributors'
            . ' | ./*[namespace-uri()="' . self::NS_Z . '" and substring(local-name(), string-length(local-name()) - 1) = "rs"]';
        foreach ($this->_xpath->query($expr, $node) as $group) {
            $role = $this->roleFromTag($group->localName);
            $scope = $this->resolve($group);
            $scope = $scope ? $scope : $group;
            foreach ($this->_xpath->query('.//foaf:Person', $scope) as $person) {
                $out[] = array(
                    'creatorType' => $role,
                    'lastName' => $this->text($person, 'foaf:surname'),
                    'firstName' => $this->text($person, 'foaf:givenName'),
                );
            }
        }
        return $out;
    }

    /** "authors" -> "author", "seriesEditors" -> "seriesEditor". */
    protected function roleFromTag($tag)
    {
        return preg_replace('/s$/', '', $tag);
    }

    protected function tags(DOMElement $node)
    {
        $out = array();
        foreach ($this->_xpath->query('./dc:subject', $node) as $subject) {
            $resolved = $this->resolve($subject);
            if ($resolved) {
                // Call numbers also arrive on dc:subject; they are not tags.
                if ($this->hasType($resolved, self::NS_DCTERMS, 'LCC')) {
                    continue;
                }
                $value = $this->text($resolved, 'rdf:value');
                if ($value === null) {
                    $value = trim($resolved->textContent);
                }
            } else {
                $value = trim($subject->textContent);
            }
            if (strlen((string) $value) && !in_array($value, $out, true)) {
                $out[] = $value;
            }
        }
        return $out;
    }

    /** Zotero writes call numbers as dc:subject > dcterms:LCC > rdf:value. */
    protected function callNumber(DOMElement $node)
    {
        foreach ($this->_xpath->query('./dc:subject', $node) as $subject) {
            $resolved = $this->resolve($subject);
            if ($resolved && $this->hasType($resolved, self::NS_DCTERMS, 'LCC')) {
                $value = $this->text($resolved, 'rdf:value');
                if ($value !== null) {
                    return $value;
                }
            }
        }
        return null;
    }

    /** A value held either in an rdf:resource attribute or as element text. */
    protected function resourceOrText(DOMElement $node, $expr)
    {
        foreach ($this->_xpath->query('./' . $expr, $node) as $found) {
            $resource = $found->getAttributeNS(self::NS_RDF, 'resource');
            if ($resource !== '') {
                return $resource;
            }
            $text = trim($found->textContent);
            if (strlen($text)) {
                return $text;
            }
        }
        return null;
    }

    protected function notes(DOMElement $node)
    {
        $out = array();
        foreach ($this->_xpath->query('./dcterms:isReferencedBy', $node) as $ref) {
            $memo = $this->resolve($ref);
            if ($memo) {
                $text = $this->text($memo, 'rdf:value');
                if ($text !== null) {
                    $out[] = $text;
                }
            }
        }
        return $out;
    }

    protected function attachments(DOMElement $node)
    {
        $out = array();
        foreach ($this->_xpath->query('./link:link', $node) as $link) {
            $target = $this->resolve($link);
            if (!$target || !$this->isAttachment($target)) {
                continue;
            }
            $path = $this->resourceOrText($target, 'z:path');
            // Attachments carry their URL the same way items do.
            $url = $this->url($target);
            if ($url === null) {
                $about = $target->getAttributeNS(self::NS_RDF, 'about');
                // rdf:about is an internal "#item_3" anchor unless it is a URL.
                if ($about !== '' && preg_match('~^[a-z][a-z0-9+.-]*://~i', $about)) {
                    $url = $about;
                }
            }
            $out[] = array_filter(array(
                'title' => $this->text($target, 'dc:title'),
                'path' => $path,
                'url' => $url,
                'mimeType' => $this->text($target, 'link:type'),
            ));
        }
        return $out;
    }

    /**
     * Follow an rdf:resource / rdf:nodeID reference, or return the inline
     * child element when the value is nested rather than referenced.
     */
    protected function resolve(DOMElement $node)
    {
        $ref = $node->getAttributeNS(self::NS_RDF, 'resource');
        if ($ref !== '' && isset($this->_byId[$ref])) {
            return $this->_byId[$ref];
        }
        $nodeId = $node->getAttributeNS(self::NS_RDF, 'nodeID');
        if ($nodeId !== '' && isset($this->_byId['_:' . $nodeId])) {
            return $this->_byId['_:' . $nodeId];
        }
        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_ELEMENT_NODE) {
                return $child;
            }
        }
        return null;
    }

    protected function text(DOMElement $node, $expr)
    {
        foreach ($this->_xpath->query('./' . $expr, $node) as $found) {
            $text = trim($found->textContent);
            if (strlen($text)) {
                return $text;
            }
        }
        return null;
    }
}
