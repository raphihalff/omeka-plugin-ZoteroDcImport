<?php
$title = __('Zotero DC Import');
echo head(array('title' => $title, 'bodyclass' => 'zotero-dc-import'));
?>
<?php echo flash(); ?>

<?php if ($status):
    $finished = in_array($status['state'], array('done', 'error'), true);
    ?>
    <div class="<?php echo $status['state'] === 'error' ? 'error' : 'success'; ?>">
        <p>
            <strong><?php echo $finished
                ? __('Last import') : __('Import %s', $status['state']); ?>:</strong>
            <?php echo html_escape(isset($status['message']) ? $status['message'] : ''); ?>
            <?php if (!empty($status['ageLabel'])): ?>
                <em>(<?php echo html_escape($status['ageLabel']); ?>)</em>
            <?php endif; ?>
            <?php if (isset($status['created'])): ?>
                <br><?php echo __('%s imported, %s file(s) attached, %s skipped.',
                    (int) $status['created'],
                    isset($status['files']) ? (int) $status['files'] : 0,
                    isset($status['skipped']) ? (int) $status['skipped'] : 0); ?>
            <?php endif; ?>
        </p>
        <?php if (!$finished): ?>
            <p><?php echo __('Reload this page for progress.'); ?></p>
        <?php endif; ?>
        <form method="post" action="<?php echo html_escape(url('zotero-dc-import/index/clear')); ?>">
            <?php echo $this->formSubmit('clear', __('Dismiss'), array('class' => 'button')); ?>
        </form>
    </div>
    <?php if (!empty($status['messages'])): ?>
        <div class="zotero-dc-messages">
            <h3><?php echo __('Notes from this import'); ?></h3>
            <ul>
                <?php foreach (array_slice($status['messages'], 0, 50) as $message): ?>
                    <li><?php echo html_escape($message); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
<?php endif; ?>

<p>
    <?php echo __('Export from Zotero with %s. Values go into Dublin Core only; '
        . 'where a record has an original-language reading it is written first, '
        . 'with the romanization second.',
        '<strong>File &rarr; Export Library &rarr; Zotero RDF</strong>'); ?>
</p>

<form method="post" enctype="multipart/form-data">

    <h2><?php echo __('Where is the export?'); ?></h2>

    <div class="field">
        <div class="two columns alpha">
            <label for="server_path"><?php echo __('Path on the server'); ?></label>
        </div>
        <div class="five columns omega inputs">
            <?php echo $this->formText('server_path', null, array('size' => 60,
                'placeholder' => '/home/user/zotero-export')); ?>
            <p class="explanation">
                <?php echo __('A directory holding the .rdf and its files/ folder, '
                    . 'or the .rdf itself. Preferred: uploads here are capped at %s.',
                    html_escape($limits['upload_max_filesize'])); ?>
            </p>
        </div>
    </div>

    <div class="field">
        <div class="two columns alpha">
            <label for="export"><?php echo __('or upload a file'); ?></label>
        </div>
        <div class="five columns omega inputs">
            <input type="file" name="export" id="export" accept=".rdf,.xml,.json,.zip">
            <p class="explanation">
                <?php echo __('.rdf, .zip (RDF with files) or .json. Maximum %s.',
                    html_escape($limits['upload_max_filesize'])); ?>
            </p>
        </div>
    </div>

    <h2><?php echo __('Files'); ?></h2>

    <div class="field">
        <div class="two columns alpha">
            <label for="file_source"><?php echo __('Attach files from'); ?></label>
        </div>
        <div class="five columns omega inputs">
            <?php
            $sources = array(
                'none' => __('Nothing — import metadata only'),
                'path' => __('The export folder given above'),
            );
            if ($dropboxDir !== null) {
                $sources['dropbox'] = __('The Dropbox plugin folder (%s file(s) waiting)',
                    $dropboxFileCount);
            }
            echo $this->formSelect('file_source', 'path', array(), $sources);
            ?>
            <?php if ($dropboxDir === null): ?>
                <p class="explanation">
                    <?php echo __('The Dropbox plugin folder was not found, so that '
                        . 'option is hidden. Expected at %s.',
                        '<code>plugins/Dropbox/files</code>'); ?>
                </p>
            <?php else: ?>
                <p class="explanation">
                    <?php echo __('Dropbox folder: %s. It is flat, so files are matched '
                        . 'by filename; the export folder keeps Zotero\'s per-item '
                        . 'nesting and is unambiguous.',
                        '<code>' . html_escape($dropboxDir) . '</code>'); ?>
                </p>
            <?php endif; ?>
        </div>
    </div>

    <h2><?php echo __('Options'); ?></h2>

    <div class="field">
        <div class="two columns alpha">
            <label for="collection_id"><?php echo __('Collection'); ?></label>
        </div>
        <div class="five columns omega inputs">
            <?php echo $this->formSelect('collection_id', null,
                array('id' => 'collection-id'), $collections); ?>
        </div>
    </div>

    <div class="field">
        <div class="two columns alpha">
            <label><?php echo __('Behaviour'); ?></label>
        </div>
        <div class="five columns omega inputs">
            <label><input type="checkbox" name="remove_after_attach" value="1" checked>
                <?php echo __('Remove files from the Dropbox folder once attached'); ?></label>
            <span class="explanation"><?php echo __('(Dropbox source only; an export '
                . 'folder is never modified)'); ?></span><br>
            <label><input type="checkbox" name="skip_duplicates" value="1" <?php
                echo get_option('zotero_dc_import_skip_duplicates') ? 'checked' : ''; ?>>
                <?php echo __('Skip items whose title is already in the archive'); ?></label><br>
            <label><input type="checkbox" name="add_tags" value="1">
                <?php echo __('Also add Zotero tags as Omeka tags'); ?></label><br>
            <label><input type="checkbox" name="public" value="1">
                <?php echo __('Make items public'); ?></label><br>
            <label><input type="checkbox" name="featured" value="1">
                <?php echo __('Feature items'); ?></label>
        </div>
    </div>

    <div class="field">
        <div class="two columns alpha">
            <label><?php echo __('Elements in use'); ?></label>
        </div>
        <div class="five columns omega inputs">
            <p class="explanation">
                <?php echo __('Writing to: %s. Change this under Settings for this plugin.',
                    '<strong>' . html_escape(implode(', ',
                        array_keys(array_filter($settings)))) . '</strong>'); ?>
            </p>
        </div>
    </div>

    <?php echo $this->formSubmit('submit', __('Start import'),
        array('class' => 'green button')); ?>
    <p class="explanation">
        <?php echo __('The import runs in the background, since this server stops a '
            . 'web request after %s seconds.', html_escape(ini_get('max_execution_time'))); ?>
    </p>
</form>

<h2><?php echo __('Diagnostics'); ?></h2>
<p class="explanation">
    <?php echo __('Imports run through Omeka\'s background job runner, which shells '
        . 'out with exec(). Many shared hosts disable that. If the status above '
        . 'stays on "queued", this is where to look.'); ?>
</p>
<table class="zotero-dc-diagnostics">
    <tbody>
    <?php foreach ($diagnostics as $label => $value): ?>
        <tr>
            <th style="text-align:left; padding-right:1em; vertical-align:top">
                <?php echo html_escape($label); ?></th>
            <td>
                <?php if (is_array($value)): ?>
                    <?php if (!$value): ?>
                        <em><?php echo __('none'); ?></em>
                    <?php else: ?>
                        <?php foreach ($value as $row): ?>
                            <div><?php echo html_escape(is_array($row)
                                ? implode('  |  ', array_map(function ($k, $v) {
                                    return $k . '=' . $v;
                                }, array_keys($row), $row))
                                : (string) $row); ?></div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                <?php else: ?>
                    <code><?php echo html_escape((string) $value); ?></code>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<?php echo foot(); ?>
