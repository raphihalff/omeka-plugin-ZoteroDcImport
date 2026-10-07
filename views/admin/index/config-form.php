<div class="field">
    <div class="two columns alpha">
        <label for="include_romanization"><?php echo __('Romanization'); ?></label>
    </div>
    <div class="five columns omega inputs">
        <label>
            <input type="checkbox" name="include_romanization" id="include_romanization"
                value="1" <?php echo get_option('zotero_dc_import_include_romanization') ? 'checked' : ''; ?>>
            <?php echo __('Keep the romanized reading as a second value'); ?>
        </label>
        <p class="explanation">
            <?php echo __('The original-language reading is always written first. '
                . 'Untick to import the original only and drop the romanization.'); ?>
        </p>
    </div>
</div>

<div class="field">
    <div class="two columns alpha">
        <label for="skip_duplicates"><?php echo __('Duplicates'); ?></label>
    </div>
    <div class="five columns omega inputs">
        <label>
            <input type="checkbox" name="skip_duplicates" id="skip_duplicates"
                value="1" <?php echo get_option('zotero_dc_import_skip_duplicates') ? 'checked' : ''; ?>>
            <?php echo __('Skip items whose title already exists, by default'); ?>
        </label>
    </div>
</div>

<div class="field">
    <div class="two columns alpha">
        <label><?php echo __('Dublin Core elements to fill'); ?></label>
    </div>
    <div class="five columns omega inputs">
        <?php foreach ($elements as $name => $enabled): ?>
            <label>
                <input type="checkbox" name="elements[<?php echo html_escape($name); ?>]"
                    value="1" <?php echo $enabled ? 'checked' : ''; ?>>
                <?php echo html_escape($name); ?>
            </label><br>
        <?php endforeach; ?>
        <p class="explanation">
            <?php echo __('Unticked elements are left empty. Defaults match the '
                . 'elements this archive already uses; no new element set is created.'); ?>
        </p>
    </div>
</div>
