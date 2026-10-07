# Zotero DC Import

An Omeka Classic plugin that imports Zotero exports into **Dublin Core**,
writing the original-language reading first. It creates no element set.

## Why

Omeka's existing [Zotero Import][zi] plugin installs a `Zotero` element set of
roughly 150 elements — `Publication Title`, `Library Catalog`, `Num Pages` —
running in parallel to Dublin Core. Records imported that way do not resemble
the rest of a Dublin Core catalogue and will not display in a theme that reads
DC. It also fetches through a deprecated Zotero API and is marked as tested
only up to Omeka 2.0.

This plugin takes a Zotero export file and writes it into the 15 core Dublin
Core elements, nothing else.

[zi]: https://omeka.org/classic/plugins/ZoteroImport/

## Original language first

Bibliographic records for non-Latin-script material often hold a romanization
in the main fields and the original script elsewhere. Zotero has no field for
the original, so it conventionally lives in **Extra**:

```
title:  S'iz umetik on Lutski
extra:  original-title: ס'איז אומעטיק אָן לוצקי
        original-author: פּעט, חיים
        original-container-title: אילוסטרירטע ליטעראַרישע בלעטער
        original-citation: 5, נומ' 9–10 (49–50), סעפּטעמבער–אָקטאָבער 1957, ז' 4
```

The plugin inverts that. Each element receives the original reading **first**
and the romanization second. Omeka keeps element texts in order and displays
the first, so the original is what a visitor sees and what sorts, while the
romanization stays searchable:

```
Title      ס'איז אומעטיק אָן לוצקי
Title      S'iz umetik on Lutski
Creator    פּעט, חיים
Creator    Pet, Khayim
Publisher  אילוסטרירטע ליטעראַרישע בלעטער
Source     אילוסטרירטע ליטעראַרישע בלעטער, 5, נומ' 9–10 (49–50), 1957, ז' 4
Date       1957
Language   Yiddish
```

Recognized Extra labels: `original-title`, `original-author`,
`original-container-title`, `original-citation`, `original-subject`. Records
without any of them simply get one value per element.

Untick **Keep the romanized reading as a second value** in the plugin settings
to import the original only.

## Requirements

Omeka Classic 3.0 or later. Tested on 3.2.1.

Optional: [Dropbox][db] as a file source, and [Collection Tree Select][cts]
for a hierarchical collection picker. Neither is required.

[db]: https://omeka.org/classic/plugins/Dropbox/
[cts]: https://github.com/raphihalff/omeka-plugin-CollectionTreeSelect

## Install

Put the `ZoteroDcImport` directory in `plugins/` and activate it under
**Settings → Plugins**. A **Zotero DC Import** entry appears in the admin
navigation.

## Use

Export from Zotero with **File → Export Library → Zotero RDF**, ticking
**Export Files** if you want attachments.

Give the import page either a **path on the server** — a directory holding the
`.rdf` and its `files/` folder, or the `.rdf` itself — or upload a file. A path
is usually the only workable route, since a real export exceeds typical
`upload_max_filesize` limits.

| Format | Notes |
| --- | --- |
| `.rdf` | Metadata, plus attachments if the files are reachable |
| `.zip` | RDF with files, for small exports |
| `.json` | CSL JSON; metadata only, Extra arrives as `note` |

Imports run as a **background job**, because a library of any size will exceed
`max_execution_time`. Reload the page for progress.

### Files

| Source | Behaviour |
| --- | --- |
| Export folder | Resolves Zotero's own `files/2/name.pdf` layout under the path given |
| Dropbox plugin | Matches by filename in `plugins/Dropbox/files/`, and drains it |
| None | Metadata only |

Omeka ingests files with `copy()`, so a staged file survives the import. As the
Dropbox plugin does, attached files are then deleted from the Dropbox folder so
what remains is what still needs attention. Only files Omeka accepted are
removed; anything refused or missing stays and is reported. An **export folder
is never modified**.

## Configuring the mapping

Plugin settings choose which Dublin Core elements get filled. Defaults:

- **On** — Title, Creator, Contributor, Subject, Description, Source,
  Publisher, Date, Language, Identifier
- **Off** — Rights, Type, Format, Relation, Coverage

The mapping itself is a set of plain tables at the top of
`models/ZoteroDcImport/Mapper.php`:

```php
public static $creatorRoles = array('author', 'artist', ...);  // else Contributor
public static $languages = array('yid' => 'Yiddish', ...);     // spelled-out names
public static $types = array('journalArticle' => 'Text', ...); // only if Type is on
```

`map()` makes one call per element, so moving a value — sending the catalogue
URL to Relation instead of Identifier, say — is a one-line change.

Periodical titles lose a trailing `(Place, 1926-1938)` disambiguator, since
catalogues add it but Dublin Core Publisher wants the bare name. Only
parentheticals containing a year are stripped, so a title that genuinely ends
in brackets survives. Set `$stripPeriodicalQualifier = false` to keep them.

## Reconstructed originals

If an external tool reconstructs an original-language reading rather than
reading it from the catalogue, it can mark that by adding a line to Extra:

```
original-script: reconstructed by detransliteration
```

The plugin then adds a note to Dublin Core Description so the record can be
reviewed later. Set `ZoteroDcImport_Mapper::$reconstructedNote = null` to
import such records silently.

The import report names when it ran and can be dismissed. A finished report
stops showing after a day; a job stuck on **queued** or **running** keeps
showing however old, since that is the symptom worth seeing.

## Diagnostics

Background jobs run through `exec()`, which many shared hosts disable. The
import page reports whether `exec` is available, which job adapter is
configured, the PHP-CLI path, the live time and memory limits, and recent rows
from Omeka's `processes` table. If the status stays on **queued**, that panel
says why.

## Tests

The parser and mapper take plain arrays and strings, so they run without
bootstrapping Omeka:

```
php test/run.php        # parsing and Dublin Core mapping
php test/importer.php   # file attachment and staged-file removal
php test/status.php     # import-report lifecycle
```

The importer tests stub Omeka's helpers and operate on real temporary files,
because removal deletes things: they cover the Dropbox folder draining, an
export folder being left alone, a file Omeka refuses being kept rather than
deleted, and a traversing path reaching neither.

The RDF fixture reproduces a real Zotero export, including the details that are
easy to get wrong: `z:path` carrying its value in an `rdf:resource` attribute,
call numbers arriving as `dc:subject > dcterms:LCC`, and item types with no
biblio class serialized as `rdf:Description`. The parser has been run against a
5.7 MB export of 2,299 items.

## Limitations

- Extra pairs originals with creators and subjects **positionally**. A
  hand-edited Extra whose entries are out of order will mis-pair.
- Duplicate detection matches on exact Title text.
- The Dropbox folder is flat while Zotero nests files per item, so matching
  there is by filename and two attachments with the same filename cannot be
  told apart. The export-folder source keeps the nesting.
- Attachments that are web links rather than stored files have no path and are
  skipped.
- CSL JSON carries no attachments.

## Licence

GPLv3. See `LICENSE`.
