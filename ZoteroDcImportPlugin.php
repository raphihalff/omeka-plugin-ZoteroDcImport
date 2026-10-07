<?php
/**
 * Imports Zotero exports into Dublin Core, original language first.
 * Installs no element set.
 */
class ZoteroDcImportPlugin extends Omeka_Plugin_AbstractPlugin
{
    protected $_hooks = array('install', 'uninstall', 'define_acl', 'config', 'config_form');

    protected $_filters = array(
        'admin_navigation_main',
        // No-op unless Collection Tree Select is active.
        'collection_tree_select_pages',
    );

    protected $_options = array(
        'zotero_dc_import_include_romanization' => 1,
        'zotero_dc_import_elements' => '',
        'zotero_dc_import_skip_duplicates' => 1,
        'zotero_dc_import_status' => '',
    );

    public function hookInstall()
    {
        $this->_options['zotero_dc_import_elements'] = json_encode(ZoteroDcImport_Mapper::$elements);
        $this->_installOptions();
    }

    public function hookUninstall()
    {
        $this->_uninstallOptions();
    }

    public function hookDefineAcl($args)
    {
        $acl = $args['acl'];
        $acl->addResource('ZoteroDcImport_Index');
        $acl->allow(array('super', 'admin'), 'ZoteroDcImport_Index');
    }

    public function hookConfigForm()
    {
        $elements = self::elementSettings();
        include dirname(__FILE__) . '/views/admin/index/config-form.php';
    }

    public function hookConfig($args)
    {
        $post = $args['post'];
        set_option('zotero_dc_import_include_romanization',
            empty($post['include_romanization']) ? 0 : 1);
        set_option('zotero_dc_import_skip_duplicates',
            empty($post['skip_duplicates']) ? 0 : 1);

        $elements = array();
        foreach (array_keys(ZoteroDcImport_Mapper::$elements) as $name) {
            $elements[$name] = !empty($post['elements'][$name]);
        }
        set_option('zotero_dc_import_elements', json_encode($elements));
    }

    public function filterAdminNavigationMain($nav)
    {
        $nav[] = array(
            'label' => __('Zotero DC Import'),
            'uri' => url('zotero-dc-import'),
            'resource' => 'ZoteroDcImport_Index',
            'privilege' => 'index',
        );
        return $nav;
    }

    public function filterCollectionTreeSelectPages($pages)
    {
        $pages['zotero-dc-import']['index'] = array('index');
        return $pages;
    }

    /** Stored element toggles, over the mapper's defaults. */
    public static function elementSettings()
    {
        $stored = json_decode((string) get_option('zotero_dc_import_elements'), true);
        if (!is_array($stored)) {
            return ZoteroDcImport_Mapper::$elements;
        }
        return array_merge(ZoteroDcImport_Mapper::$elements, $stored);
    }

    /** Apply stored settings to the mapper before importing. */
    public static function applySettings()
    {
        ZoteroDcImport_Mapper::$elements = self::elementSettings();
        ZoteroDcImport_Mapper::$includeRomanization =
            (bool) get_option('zotero_dc_import_include_romanization');
    }
}
