<?php
/**
 * DokuWiki Plugin farmer (Admin Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Michael Große <grosse@cosmocode.de>
 */

// must be run within Dokuwiki
if(!defined('DOKU_INC')) die();

class admin_plugin_farmer_setup extends DokuWiki_Admin_Plugin {

    /** @var helper_plugin_farmer $helper */
    private $helper;

    /**
     * @return bool admin only!
     */
    public function forAdminOnly() {
        return true;
    }

    /**
     * Should carry out any processing required by the plugin.
     */
    public function handle() {
        global $INPUT;
        global $ID;

        if(!$INPUT->bool('farmdir')) return;
        if(!checkSecurityToken()) return;

        $this->helper = plugin_load('helper', 'farmer');

        $farmdir = trim($INPUT->str('farmdir', ''));
        if($farmdir[0] !== '/') $farmdir = DOKU_INC . $farmdir;
        $farmdir = fullpath($farmdir);

        $errors = array();
        if($farmdir === '') {
            $errors[] = $this->getLang('farmdir_missing');
        } elseif($this->helper->isInPath($farmdir, DOKU_INC) !== false) {
            $errors[] = $this->getLang('farmdir_in_dokuwiki');
        } elseif(!io_mkdir_p($farmdir)) {
            $errors[] = $this->getLang('farmdir_uncreatable');
        } elseif(!is_writeable($farmdir)) {
            $errors[] = $this->getLang('farmdir_unwritable');
        } elseif(count(scandir($farmdir)) > 2) {
            $errors[] = $this->getLang('farmdir_notEmpty');
        }

        if($INPUT->str('serversetup', '', true) === '') {
            $errors[] = $this->getLang('serversetup_missing');
        }

        if($errors) {
            foreach($errors as $error) {
                msg($error, -1);
            }
            return;
        }

        if($this->createPreloadPHP($farmdir . "/", $INPUT->str('serversetup'))) {
            msg($this->getLang('preload creation success'), 1);
            $link = wl($ID, array('do' => 'admin', 'page' => 'farmer'), true, '&');
            send_redirect($link);
        } else {
            msg($this->getLang('preload creation error'), -1);
        }
    }

    /**
     * Render HTML output, e.g. helpful text and a form
     */
    public function html() {
        // Is preload.php already enabled?
        if(file_exists(DOKU_INC . 'inc/preload.php')) {
            msg($this->getLang('overwrite_preload'), -1);
        }

        $form = new \dokuwiki\Form\Form();
        $form->addClass('plugin_farmer');
        $form->addFieldsetOpen($this->getLang('preloadPHPForm'));
        $form->addTextInput('farmdir', $this->getLang('farm dir'))->addClass('block edit');

        $form->addRadioButton('serversetup', $this->getLang('subdomain setup'))->val('subdomain')->attr('type', 'radio')->addClass('block edit')->id('subdomain__setup');
        $form->addRadioButton('serversetup', $this->getLang('htaccess setup'))->val('htaccess')->attr('type', 'radio')->addClass('block edit')->attr('checked', true)->id('htaccess__setup');

        $form->addButton('farmer__submit', $this->getLang('submit'))->attr('type', 'submit');
        $form->addFieldsetClose();
        echo $form->toHTML();

        echo sprintf($this->locale_xhtml('preload'), dirname(DOKU_REL) . '/farm/');

    }

    /**
     * @param string $animalpath path to where the animals are stored
     * @param bool $htaccess Should the .htaccess be adjusted?
     * @return bool
     */
    protected function createPreloadPHP($animalpath, $htaccess) {
        if($htaccess && !$this->createHtaccess()) {
            return false;
        }

        $content = "<?php\n";
        $content .= "# farm setup by farmer plugin\n";
        $content .= "if(!defined('DOKU_FARMDIR')) define('DOKU_FARMDIR', '$animalpath');\n";
        $content .= "include(fullpath(dirname(__FILE__)).'/../lib/plugins/farmer/farm.php');\n";
        return io_saveFile(DOKU_INC . 'inc/preload.php', $content);
    }

    /**
     * Appends the needed config to the main .htaccess for htaccess type setups
     *
     * @return bool true if saving was successful
     */
    protected function createHtaccess() {
        $content = "\n\n# Options added for farm setup by Farmer Plugin:\n";
        $content .= "RewriteEngine On\n";
        $content .= 'RewriteRule ^/?!([^/]+)/(.*)  ' . DOKU_REL . '$2?animal=$1 [QSA]' . "\n";
        $content .= 'RewriteRule ^/?!([^/]+)$      ' . DOKU_REL . '?animal=$1 [QSA]' . "\n";
        $content .= 'Options +FollowSymLinks'."\n";
        return io_saveFile(DOKU_INC . '.htaccess', $content, true);
    }

}

// vim:ts=4:sw=4:et: