<?php
/**
 * DokuWiki Plugin farmer (Admin Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Michael Große <grosse@cosmocode.de>
 */

// must be run within Dokuwiki
if(!defined('DOKU_INC')) die();

class admin_plugin_farmer_createAnimal extends DokuWiki_Admin_Plugin {

    private $InitializeFarm = false;
    private $errorMessages = array();
    private $failOnce;

    private function succeeded($testResult) {
        if ($testResult === false) {
            $this->failOnce = true;
        }
    }

    private function initFailOnce() {
        $this->failOnce = false;
    }

    private function checkFailOnce() {
        return $this->failOnce;
    }

    /** @var helper_plugin_farmer $helper */
    private $helper;

    /**
     * @return int sort number in admin menu
     */
    public function getMenuSort() {
        return 42;
    }

    /**
     * @return bool true if only access for superuser, false is for superusers and moderators
     */
    public function forAdminOnly() {
        return true;
    }

    public function createNewAnimal($name, $adminSetup, $adminPassword, $subdomain) {
        $this->initFailOnce();

        if (DOKU_FARMTYPE === 'subdomain') {
            $animal = $subdomain;
        } elseif (DOKU_FARMTYPE === 'htaccess') {
            $animal = $name;
        } else {
            throw new Exception('invalid value for $serverSetup');
        }
        $animaldir = DOKU_FARMDIR . $animal;

        if (!file_exists(DOKU_FARMDIR . '_animal')) {
            $this->helper->downloadTemplate(DOKU_FARMDIR);
        }

        try {
            $this->helper->io_copyDir(DOKU_FARMDIR . '_animal', $animaldir);
        } catch (Exception $e) {
            dbglog(dbg_backtrace());
            return false;
        }

        $confFile = file_get_contents($animaldir . '/conf/local.php');
        $confFile = str_replace('Animal Wiki Title', $name, $confFile);
        $this->succeeded(io_saveFile($animaldir . '/conf/local.php', $confFile));

        if ($adminSetup === 'newAdmin') {
            $cryptAdminPassword = auth_cryptPassword($adminPassword);
            $usersAuth = file_get_contents($animaldir . '/conf/users.auth.php');
            $usersAuth = str_replace('$1$cce258b2$U9o5nK0z4MhTfB5QlKF23/', $cryptAdminPassword, $usersAuth);
            $this->succeeded(io_saveFile($animaldir . '/conf/users.auth.php', $usersAuth));
        } elseif ($adminSetup === 'importUsers') {
            copy(DOKU_CONF . 'users.auth.php', $animaldir . '/conf/users.auth.php');
        } elseif ($adminSetup === 'currentAdmin') {
            $masterUsers = file_get_contents(DOKU_CONF . 'users.auth.php');
            $user = $_SERVER['REMOTE_USER'];
            $masterUsers = trim(strstr($masterUsers,"\n". $user . ":"));
            $newAdmin = substr($masterUsers,0,strpos($masterUsers,"\n")+1);
            $this->succeeded(io_saveFile($animaldir . '/conf/users.auth.php', $newAdmin));
        } else {
            throw new Exception('invalid value for $adminSetup');
        }

        if (DOKU_FARMTYPE === 'htaccess') {
            $protectedConf = file_get_contents($animaldir . '/conf/local.protected.php');
            $protectedConf .= '$conf["basedir"] = \'' . DOKU_FARMRELDIR . $name . "/';\n"; //@todo confirm that this is really the correct value, maybe we need userinput/confirmation
            $this->succeeded(io_saveFile($animaldir . '/conf/local.protected.php', $protectedConf));
            $animalLink = '<a href="' . DOKU_FARMRELDIR . $name. '">' . $name . '</a>';
        } else {
            $animalLink = '<a href="' . $subdomain . '">' . $name . '</a>';
        }

        if ($this->getConf('deactivated plugins') === '') {
            $deactivatedPluginsList = array('farmer',);
        } else {
            $deactivatedPluginsList = explode(',', $this->getConf('deactivated plugins'));
            array_push($deactivatedPluginsList,'farmer');
        }
        foreach ($deactivatedPluginsList as $plugin) {
            $this->helper->deactivatePlugin(trim($plugin),$animal);
        }

        if ($this->checkFailOnce()) {
            return false;
        } else {
            return $animalLink;
        }
    }

    public function createPreloadPHP($animalpath, $setuptype, $htaccessBaseDir) {
        $this->helper->downloadTemplate($animalpath);

        $content = "<?php\n";
        $content .= "if(!defined('DOKU_FARMDIR')) define('DOKU_FARMDIR', '$animalpath');\n";
        $content .= "if(!defined('DOKU_FARMTYPE')) define('DOKU_FARMTYPE', '$setuptype');\n";
        if ($setuptype === 'htaccess') {
            $content .= "if(!defined('DOKU_FARMRELDIR')) define('DOKU_FARMRELDIR', '$htaccessBaseDir');\n";
        }
        $content .= "include(fullpath(dirname(__FILE__)).'/farm.php');\n";


        $writeSuccess = io_saveFile($animalpath . '/.htaccess', $this->createHtaccess(DOKU_REL));
        if (!$writeSuccess) {
            return false;
        }

        return io_saveFile(DOKU_INC . 'inc/preload.php',$content);
    }

    public function createHtaccess ($doku_rel) {
        $content = "RewriteEngine On\n\n";
        $content .= 'RewriteRule ^/?([^/]+)/(.*)  ' . $doku_rel . '$2?animal=$1 [QSA]' . "\n";
        $content .= 'RewriteRule ^/?([^/]+)$      ' . $doku_rel . '?animal=$1 [QSA]' . "\n";
        $content .= 'Options +FollowSymLinks';
        return $content;
    }

    /**
     * Should carry out any processing required by the plugin.
     */
    public function handle() {
        global $INPUT;

        $this->helper = plugin_load('helper','farmer');

        // Is preload.php already enabled?
        if (!$this->helper->checkFarmSetup()) {
            $this->InitializeFarm = true;
            if (file_exists(DOKU_INC . 'inc/preload.php')) {
                msg($this->getLang('overwrite_preload'));
             }
            if ($INPUT->has('farmdir')) {
                $farmdir = rtrim(hsc(trim($INPUT->str('farmdir','',true))),'/');
                if ($farmdir === '') {
                    $this->errorMessages['farmdir'] = $this->getLang('farmdir_missing');
                } else {
                    if ($this->helper->isInPath($farmdir, DOKU_INC) !== false) {
                        $this->errorMessages['farmdir'] = $this->getLang('farmdir_in_dokuwiki');
                    } elseif (!io_mkdir_p($farmdir)) {
                        $this->errorMessages['farmdir'] = $this->getLang('farmdir_uncreatable');
                    } elseif (!is_writeable($farmdir)) {
                        $this->errorMessages['farmdir'] = $this->getLang('farmdir_unwritable');
                    } elseif (count(scandir($farmdir)) > 2) {
                        $this->errorMessages['farmdir'] = $this->getLang('farmdir_notEmpty');
                    }
                }

                if ($INPUT->str('serversetup','',true) === '') {
                    $this->errorMessages['serversetup'] = $this->getLang('serversetup_missing');
                } elseif ($INPUT->str('serversetup') === 'htaccess') {
                    if($INPUT->str('htaccess_basedir', '', true) === '') {
                        $this->errorMessages['htaccess_basedir'] = $this->getLang('htaccess_basedir_missing'); //@todo: more validation? e.g. not containing a dot?
                    }
                }

                if (empty($this->errorMessages)) {
                    $ret = $this->createPreloadPHP(realpath($farmdir) . "/", $INPUT->str('serversetup'), $INPUT->str('htaccess_basedir', '', true));
                    if ($ret === true) {
                        msg('inc/preload.php has been succesfully created', 1);
                        $this->helper->reloadAdminPage();
                    } else {
                        msg('there was an error creating inc/preload.php',-1);
                    }
                }
            }
        } else {
            if ($INPUT->has('farmer__submit')) {
                $animalsubdomain = null;
                $animalname = null;
                if ($INPUT->str('animalname','',true) === '') {
                    $this->errorMessages['animalname'] = $this->getLang('animalname_missing');
                } else {
                    $animalname = hsc(trim($INPUT->str('animalname')));
                    if (!preg_match("/^[a-z0-9]+(-[a-z0-9]+)*$/i",$animalname)) { //@todo: tests for regex
                        $this->errorMessages['animalname'] = $this->getLang('animalname_invalid');
                    }
                }

                if ($INPUT->str('adminsetup') === 'newAdmin') {
                    if ($INPUT->str('adminPassword','',true) === '') {
                        $this->errorMessages['adminPassword'] = $this->getLang('adminPassword_empty');
                    }
                }

                if (DOKU_FARMTYPE === 'subdomain') {
                    if ($INPUT->str('animalsubdomain','',true) === '') {
                        $this->errorMessages['animalsubdomain'] = $this->getLang('animalsubdomain_missing');
                    } else {
                        $animalsubdomain = hsc(trim($INPUT->str('animalsubdomain')));
                        if (!preg_match("/^[a-z0-9]+([\.-][a-z0-9]+)*$/i",$animalsubdomain)) { //@todo: tests for regex
                            $this->errorMessages['animalsubdomain'] =  $this->getLang('animalsubdomain_invalid');
                        } elseif (file_exists(DOKU_FARMDIR . $animalsubdomain)) {
                            $this->errorMessages['animalsubdomain'] =  $this->getLang('animalsubdomain_preexisting');
                        }
                    }
                } elseif ($INPUT->str('serversetup') === 'htaccess') {
                    if (file_exists(DOKU_FARMDIR . $animalname)) {
                        $this->errorMessages['animalname'] =  $this->getLang('animalname_preexisting');
                    }
                }

                if (empty($this->errorMessages)) {
                    $ret = $this->createNewAnimal($animalname, $INPUT->str('adminsetup'), $INPUT->str('adminPassword'), $animalsubdomain);
                    if ($ret !== false) {
                        msg(sprintf($this->getLang('animal creation success'),$ret), 1);
                        $this->helper->reloadAdminPage();
                    } else {
                        // should never happen
                        msg('there has been an error creating the animal', -1);
                    }
                }
            }
        }
    }

    /**
     * Render HTML output, e.g. helpful text and a form
     */
    public function html() {

        if ($this->InitializeFarm) {
            echo sprintf($this->locale_xhtml('preload'),realpath(DOKU_INC.'..') . '/animals/',dirname(DOKU_REL) . '/animals/');
            $form = new \dokuwiki\Form\Form();
            $form->addClass('plugin_farmer');
            $form->addFieldsetOpen($this->getLang('preloadPHPForm'));
            $form->addTextInput('farmdir', $this->getLang('farm dir'))->addClass('block edit')->attr('placeholder','farm dir');

            $form->addRadioButton('serversetup', $this->getLang('subdomain setup'))->val('subdomain')->attr('type','radio')->addClass('block edit');
            $form->addRadioButton('serversetup', $this->getLang('htaccess setup'))->val('htaccess')->attr('type','radio')->addClass('block edit');
            $form->addTextInput('htaccess_basedir', $this->getLang('htaccess_basedir'))->addClass('block edit');

            $form->addButton('farmer__submit',$this->getLang('submit'))->attr('type','submit');

            $form->addFieldsetClose();
            $this->helper->addErrorsToForm($form, $this->errorMessages);

            echo $form->toHTML();
        } else {
            $form = new \dokuwiki\Form\Form();
            $form->addClass('plugin_farmer');
            $form->addFieldsetOpen($this->getLang('animal configuration'));
            $form->addTextInput('animalname',$this->getLang('animal name'))->addClass('block edit')->attr('placeholder',$this->getLang('animal name placeholder'));
            if (DOKU_FARMTYPE === 'subdomain') {
                $form->addTextInput('animalsubdomain', $this->getLang('animal subdomain'))->addClass('block edit')->attr('placeholder', $this->getLang('animal subdomain placeholder'));
            }
            $form->addFieldsetClose();
            $form->addTag('br');

            $form->addFieldsetOpen($this->getLang('animal administrator'));
            $form->addRadioButton('adminsetup',$this->getLang('importUsers'))->val('importUsers')->addClass('block');
            $form->addRadioButton('adminsetup', $this->getLang('currentAdmin'))->val('currentAdmin')->addClass('block');
            $form->addRadioButton('adminsetup', $this->getLang('newAdmin'))->val('newAdmin')->addClass('block')->attr('checked','checked');
            $form->addPasswordInput('adminPassword',$this->getLang('admin password'))->addClass('block edit')->attr('placeholder',$this->getLang('admin password placeholder'));
            $form->addFieldsetClose();
            $form->addTag('br');

            $form->addButton('farmer__submit',$this->getLang('submit'))->attr('type','submit')->val('newAnimal');

            $this->helper->addErrorsToForm($form, $this->errorMessages);

            echo $form->toHTML();
        }

    }
}

// vim:ts=4:sw=4:et:
