<?php

/*
 *     phpMeccano v0.1.0. Web-framework written with php programming language. Core module [plugins.php].
 *     Copyright (C) 2015-2016  Alexei Muzarov
 * 
 *     This program is free software; you can redistribute it and/or modify
 *     it under the terms of the GNU General Public License as published by
 *     the Free Software Foundation; either version 2 of the License, or
 *     (at your option) any later version.
 * 
 *     This program is distributed in the hope that it will be useful,
 *     but WITHOUT ANY WARRANTY; without even the implied warranty of
 *     MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *     GNU General Public License for more details.
 * 
 *     You should have received a copy of the GNU General Public License along
 *     with this program; if not, write to the Free Software Foundation, Inc.,
 *     51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
 * 
 *     e-mail: azexmail@gmail.com
 *     e-mail: azexmail@mail.ru
 *     https://bitbucket.org/azexmail/phpmeccano
 */

namespace core;

require_once MECCANO_CORE_DIR.'/files.php';
require_once MECCANO_CORE_DIR.'/langman.php';
require_once MECCANO_CORE_DIR.'/logman.php';
require_once MECCANO_CORE_DIR.'/policy.php';

interface intPlugins {
    public function __construct(\mysqli $dbLink);
    public function unpack($package);
    public function delUnpacked($plugin);
    public function listUnpacked();
    public function aboutUnpacked($plugin);
    public function pluginData($plugin);
    public function install($plugin, $reset = FALSE, $log = TRUE);
    public function delInstalled($plugin, $keepData = TRUE, $log = TRUE);
    public function listInstalled();
    public function aboutInstalled($plugin);
}

class Plugins extends ServiceMethods implements intPlugins {
    private $langMan;
    private $logMan;
    private $policyMan;

    public function __construct(\mysqli $dbLink) {
        $this->dbLink = $dbLink;
        $this->langMan = new LangMan($dbLink);
        $this->logMan = new LogMan($dbLink);
        $this->policyMan = new Policy($dbLink);
    }
    
    private function lockPlugins($methodName) {
        $this->zeroizeError();
        if (is_file(MECCANO_TMP_DIR."/core_plugins_lock")) {
            $this->setError(ERROR_RESTRICTED_ACCESS, "$methodName: plugins are locked");
            return FALSE;
        }
        elseif (is_writable(MECCANO_TMP_DIR) && !is_file(MECCANO_TMP_DIR."/core_plugins_lock") && !is_dir(MECCANO_TMP_DIR."/core_plugins_lock")) {
            $lock = fopen(MECCANO_TMP_DIR."/core_plugins_lock", 'wb');
            fclose($lock);
            return TRUE;
        }
        else {
            $this->setError(ERROR_RESTRICTED_ACCESS, "$methodName: unable to lock plugins");
            return FALSE;
        }
    }
    
    public function unpack($package) {
        if (!$this->lockPlugins('unpack')) {
            return FALSE;
        }
        if ($this->usePolicy && !$this->checkFuncAccess('core', 'plugins_install')) {
            $this->setError(ERROR_RESTRICTED_ACCESS, "unpack: restricted by the policy");
            unlink(MECCANO_TMP_DIR."/core_plugins_lock");
            return FALSE;
        }
        $zip = new \ZipArchive();
        $zipOpen = $zip->open($package);
        if ($zipOpen === TRUE) {
            $tmpName = makeIdent();
            $unpackPath = MECCANO_UNPACKED_PLUGINS."/$tmpName";
            $tmpPath = MECCANO_TMP_DIR."/$tmpName";
            if (!@$zip->extractTo($tmpPath)) {
                $this->setError(ERROR_NOT_EXECUTED, "unpack: unable to extract package to $tmpPath");
                unlink(MECCANO_TMP_DIR."/core_plugins_lock");
                return FALSE;
            }
            $zip->close();
            // validate xml components
            $serviceData = new \DOMDocument();
            $xmlComponents = array(
                "languages.xml" => "langman-language-v01.rng",
                "policy.xml" => "policy-v01.rng",
                "log.xml" => "logman-events-v01.rng",
                "texts.xml" => "langman-text-v01.rng",
                "titles.xml" => "langman-title-v01.rng",
                "depends.xml" => "plugins-package-depends-v01.rng",
                "metainfo.xml" => "plugins-package-metainfo-v01.rng"
                );
            foreach ($xmlComponents as $valComponent=> $valSchema) {
                $xmlComponent = openRead($tmpPath."/$valComponent");
                if (!$xmlComponent) {
                    Files::remove($tmpPath);
                    $this->setError(ERROR_NOT_EXECUTED, "unpack: unable to read [$valComponent]");
                    unlink(MECCANO_TMP_DIR."/core_plugins_lock");
                    return FALSE;
                }
                if (mime_content_type($tmpPath."/$valComponent") != "application/xml") {
                    Files::remove($tmpPath);
                    $this->setError(ERROR_NOT_EXECUTED, "unpack: [$valComponent] is not XML-structured");
                    unlink(MECCANO_TMP_DIR."/core_plugins_lock");
                    return FALSE;
                }
                $serviceData->loadXML($xmlComponent);
                if (!@$serviceData->relaxNGValidate(MECCANO_CORE_DIR."/validation-schemas/$valSchema")) {
                    Files::remove($tmpPath);
                    $this->setError(ERROR_INCORRECT_DATA, "unpack: invalid [$valComponent] structure");
                    unlink(MECCANO_TMP_DIR."/core_plugins_lock");
                    return FALSE;
                }
            }
            // get data from metainfo.xml
            $packVersion = $serviceData->getElementsByTagName('metainfo')->item(0)->getAttribute('version');
            if ($packVersion != '0.2') {
                Files::remove($tmpPath);
                $this->setError(ERROR_INCORRECT_DATA, "unpack: installer is incompatible with the package specification [$packVersion]");
                unlink(MECCANO_TMP_DIR."/core_plugins_lock");
                return FALSE;
            }
            $shortName = $serviceData->getElementsByTagName('shortname')->item(0)->nodeValue;
            $qIsUnpacked = $this->dbLink->query("SELECT `id` "
                    . "FROM `".MECCANO_TPREF."_core_plugins_unpacked` "
                    . "WHERE `short`='$shortName' ;");
            if ($this->dbLink->errno) {
                Files::remove($tmpPath);
                $this->setError(ERROR_NOT_EXECUTED, 'unpack: unable to check whether the plugin is unpacked -> '.$this->dbLink->error);
                unlink(MECCANO_TMP_DIR."/core_plugins_lock");
                return FALSE;
            }
            if ($this->dbLink->affected_rows) {
                Files::remove($tmpPath);
                $this->setError(ERROR_ALREADY_EXISTS, "unpack: plugin [$shortName] was already unpacked");
                unlink(MECCANO_TMP_DIR."/core_plugins_lock");
                return FALSE;
            }
            $fullName = $this->dbLink->real_escape_string($serviceData->getElementsByTagName('fullname')->item(0)->nodeValue);
            $version = $serviceData->getElementsByTagName('version')->item(0)->nodeValue;
            $insertColumns = "`short`, `full`, `version`, `spec`, `dirname`";
            $insertValues = "'$shortName', '$fullName', '$version', '$packVersion', '$tmpName'";
            // get optional data
            $optionalData = array('about', 'credits', 'url', 'email', 'license');
            foreach ($optionalData as $optNode) {
                if ($optional = $serviceData->getElementsByTagName("$optNode")->item(0)->nodeValue) {
                    $optional = $this->dbLink->real_escape_string($optional);
                    $insertColumns = $insertColumns.", `$optNode`";
                    $insertValues = $insertValues.", '$optional'";
                }
            }
            // get list of the needed dependences
            $serviceData->load($tmpPath."/depends.xml");
            $depends = "";
            $dependsNodes = $serviceData->getElementsByTagName("plugin");
            foreach ($dependsNodes as $dependsNode) {
                $depends = $depends.$dependsNode->getAttribute('name')." (".$dependsNode->getAttribute('operator')." ".$dependsNode->getAttribute('version')."), ";
            }
            $depends = substr($depends, 0, -2);
            $insertColumns = $insertColumns.", `depends`";
            $insertValues = $insertValues.", '$depends'";
            $this->dbLink->query("INSERT INTO `".MECCANO_TPREF."_core_plugins_unpacked` ($insertColumns)"
                    . "VALUES ($insertValues) ;");
            if ($this->dbLink->errno) {
                Files::remove($tmpPath);
                $this->setError(ERROR_NOT_EXECUTED, 'unpack: '.$this->dbLink->error);
                unlink(MECCANO_TMP_DIR."/core_plugins_lock");
                return FALSE;
            }
            if (!Files::move($tmpPath, $unpackPath)) {
                $this->setError(Files::errId(), 'unpack: -> '.Files::errExp());
                unlink(MECCANO_TMP_DIR."/core_plugins_lock");
                return FALSE;
            }
        }
        else {
            $this->setError(ERROR_NOT_EXECUTED, "unpack: unable to open package. ZipArchive error: $zipOpen");
            unlink(MECCANO_TMP_DIR."/core_plugins_lock");
            return FALSE;
        }
        unlink(MECCANO_TMP_DIR."/core_plugins_lock");
        return $shortName;
    }
    
    public function delUnpacked($plugin) {
        if (!$this->lockPlugins('delUnpacked')) {
            return FALSE;
        }
        if ($this->usePolicy && !$this->checkFuncAccess('core', 'plugins_install')) {
            $this->setError(ERROR_RESTRICTED_ACCESS, "delUnpacked: restricted by the policy");
            unlink(MECCANO_TMP_DIR."/core_plugins_lock");
            return FALSE;
        }
        if (!pregPlugin($plugin)) {
            $this->setError(ERROR_INCORRECT_DATA, 'delUnpacked: incorrect plugin name');
            unlink(MECCANO_TMP_DIR."/core_plugins_lock");
            return FALSE;
        }
        $qUnpacked = $this->dbLink->query("SELECT `dirname` "
                . "FROM `".MECCANO_TPREF."_core_plugins_unpacked` "
                . "WHERE `short`='$plugin' ;");
        if ($this->dbLink->errno) {
            $this->setError(ERROR_NOT_EXECUTED, 'delUnpacked: '.$this->dbLink->error);
            unlink(MECCANO_TMP_DIR."/core_plugins_lock");
            return FALSE;
        }
        if (!$this->dbLink->affected_rows) {
            $this->setError(ERROR_NOT_FOUND, "delUnpacked: cannot find defined plugin");
            unlink(MECCANO_TMP_DIR."/core_plugins_lock");
            return FALSE;
        }
        list($dirName) = $qUnpacked->fetch_row();
        if (!Files::remove(MECCANO_UNPACKED_PLUGINS."/$dirName")) {
            $this->setError(Files::errId(), 'delUnpacked: -> '.Files::errExp());
            unlink(MECCANO_TMP_DIR."/core_plugins_lock");
            return FALSE;
        }
        $this->dbLink->query("DELETE FROM `".MECCANO_TPREF."_core_plugins_unpacked` "
                . "WHERE `short`='$plugin' ;");
        if ($this->dbLink->errno) {
            $this->setError(ERROR_NOT_EXECUTED, 'delUnpacked: unable to delete unpacked plugin ->'.$this->dbLink->error);
            unlink(MECCANO_TMP_DIR."/core_plugins_lock");
            return FALSE;
        }
        unlink(MECCANO_TMP_DIR."/core_plugins_lock");
        return TRUE;
    }
    
    public function listUnpacked() {
        $this->zeroizeError();
        if ($this->usePolicy && !$this->checkFuncAccess('core', 'plugins_install')) {
            $this->setError(ERROR_RESTRICTED_ACCESS, "listUnpacked: restricted by the policy");
            return FALSE;
        }
        $qUncpacked = $this->dbLink->query("SELECT `short`, `full`, `version` "
                . "FROM `".MECCANO_TPREF."_core_plugins_unpacked` ;");
        if ($this->dbLink->errno) {
            $this->setError(ERROR_NOT_EXECUTED, "listUnpacked: ".$this->dbLink->error);
            return FALSE;
        }
        if ($this->outputType == 'xml') {
            $xml = new \DOMDocument('1.0', 'utf-8');
            $unpackedNode = $xml->createElement('unpacked');
            $xml->appendChild($unpackedNode);
            while ($row = $qUncpacked->fetch_row()) {
                if ($curVersion = $this->pluginData($row[0])) {
                    $curSumVersion = calcSumVersion($curVersion["version"]);
                    $newSumVersion = calcSumVersion($row[2]);
                    if ($curSumVersion < $newSumVersion) {
                        $action = "upgrade";
                    }
                    elseif ($curSumVersion == $newSumVersion) {
                        $action = "reinstall";
                    }
                    elseif ($curSumVersion > $newSumVersion) {
                        $action = "downgrade";
                    }
                }
                else {
                    $action = "install";
                }
                $pluginNode = $xml->createElement('plugin');
                $unpackedNode->appendChild($pluginNode);
                $pluginNode->appendChild($xml->createElement('short', $row[0]));
                $pluginNode->appendChild($xml->createElement('full', $row[1]));
                $pluginNode->appendChild($xml->createElement('version', $row[2]));
                $pluginNode->appendChild($xml->createElement('action', $action));
            }
            return $xml;
        }
        else {
            $unpacked = array();
            while ($row = $qUncpacked->fetch_row()) {
                if ($curVersion = $this->pluginData($row[0])) {
                    $curSumVersion = calcSumVersion($curVersion["version"]);
                    $newSumVersion = calcSumVersion($row[2]);
                    if ($curSumVersion < $newSumVersion) {
                        $action = "upgrade";
                    }
                    elseif ($curSumVersion == $newSumVersion) {
                        $action = "reinstall";
                    }
                    elseif ($curSumVersion > $newSumVersion) {
                        $action = "downgrade";
                    }
                }
                else {
                    $action = "install";
                }
                $unpacked[] = array(
                    'short' => $row[0],
                    'full' => $row[1],
                    'version' => $row[2],
                    'action' => $action
                );
            }
            return json_encode($unpacked);
        }
    }
    
    public function aboutUnpacked($plugin) {
        $this->zeroizeError();
        if ($this->usePolicy && !$this->checkFuncAccess('core', 'plugins_install')) {
            $this->setError(ERROR_RESTRICTED_ACCESS, "aboutUnpacked: restricted by the policy");
            return FALSE;
        }
        if (!pregPlugin($plugin)) {
            $this->setError(ERROR_INCORRECT_DATA, 'aboutUnpacked: incorrect plugin name');
            return FALSE;
        }
        $qUncpacked = $this->dbLink->query("SELECT `short`, `full`, `version`, `about`, `credits`, `url`, `email`, `license`, `depends` "
                . "FROM `".MECCANO_TPREF."_core_plugins_unpacked` "
                . "WHERE `short`='$plugin' ;");
        if ($this->dbLink->errno) {
            $this->setError(ERROR_NOT_EXECUTED, "aboutUnpacked: ".$this->dbLink->error);
            return FALSE;
        }
        if (!$this->dbLink->affected_rows) {
            $this->setError(ERROR_NOT_FOUND, "aboutUnpacked: plugin not found");
            return FALSE;
        }
        list($shortName, $fullName, $version, $about, $credits, $url, $email, $license, $depends) = $qUncpacked->fetch_row();
        if ($curVersion = $this->pluginData($shortName)) {
            $curSumVersion = calcSumVersion($curVersion["version"]);
            $newSumVersion = calcSumVersion($version);
            if ($curSumVersion < $newSumVersion) {
                $action = "upgrade";
            }
            elseif ($curSumVersion == $newSumVersion) {
                $action = "reinstall";
            }
            elseif ($curSumVersion > $newSumVersion) {
                $action = "downgrade";
            }
        }
        else {
            $action = "install";
        }
        if ($this->outputType == 'xml') {
            $xml = new \DOMDocument('1.0', 'utf-8');
            $unpackedNode = $xml->createElement('unpacked');
            $xml->appendChild($unpackedNode);
            $unpackedNode->appendChild($xml->createElement('short', $shortName));
            $unpackedNode->appendChild($xml->createElement('full', $fullName));
            $unpackedNode->appendChild($xml->createElement('version', $version));
            $unpackedNode->appendChild($xml->createElement('about', $about));
            $unpackedNode->appendChild($xml->createElement('credits', $credits));
            $unpackedNode->appendChild($xml->createElement('url', $url));
            $unpackedNode->appendChild($xml->createElement('email', $email));
            $unpackedNode->appendChild($xml->createElement('license', $license));
            $unpackedNode->appendChild($xml->createElement('depends', $depends));
            $unpackedNode->appendChild($xml->createElement('action', $action));
            return $xml;
        }
        else {
            $unpacked = array(
                'short' => $shortName,
                'full' => $fullName,
                'version' =>$version,
                'about' => $about,
                'credits' => $credits,
                'url' => $url,
                'email' => $email,
                'license' => $license,
                'depends' => $depends,
                'action' => $action
            );
            return json_encode($unpacked);
        }
    }
    
    public function pluginData($plugin) {
        $this->zeroizeError();
        if (!pregPlugin($plugin)) {
            $this->setError(ERROR_INCORRECT_DATA, "pluginData: incorrect name");
            return FALSE;
        }
        $qPlugin = $this->dbLink->query("SELECT `id`, `version` "
                . "FROM `".MECCANO_TPREF."_core_plugins_installed` "
                . "WHERE `name`='$plugin'");
        if ($this->dbLink->errno) {
            $this->setError(ERROR_NOT_EXECUTED, "pluginData: unable to get plugin version -> ".$this->dbLink->error);
            return FALSE;
        }
        if (!$this->dbLink->affected_rows) {
            $this->setError(ERROR_NOT_FOUND, "pluginData: plugin not found");
            return FALSE;
        }
        list($id, $version) = $qPlugin->fetch_row();
        return array("id" => (int) $id, "version" => $version);
    }
    
    public function install($plugin, $reset = FALSE, $log = TRUE) {
        if (!$this->lockPlugins('install')) {
            return FALSE;
        }
        if ($this->usePolicy && !$this->checkFuncAccess('core', 'plugins_install')) {
            $this->setError(ERROR_RESTRICTED_ACCESS, "install: restricted by the policy");
            unlink(MECCANO_TMP_DIR."/core_plugins_lock");
            return FALSE;
        }
        if (!pregPlugin($plugin) || !is_bool($reset)) {
            $this->setError(ERROR_INCORRECT_DATA, "install: incorrect argument(s)");
            unlink(MECCANO_TMP_DIR."/core_plugins_lock");
            return FALSE;
        }
        $qPlugin = $this->dbLink->query("SELECT `short`, `full`, `version`, `spec`, `dirname`, `about`, `credits`, `url`, `email`, `license` "
                . "FROM `".MECCANO_TPREF."_core_plugins_unpacked` "
                . "WHERE `short`='$plugin' ;");
        if ($this->dbLink->errno) {
            $this->setError(ERROR_NOT_EXECUTED, "install: ".$this->dbLink->error);
            unlink(MECCANO_TMP_DIR."/core_plugins_lock");
            return FALSE;
        }
        if (!$this->dbLink->affected_rows) {
            $this->setError(ERROR_NOT_FOUND, "install: unpacked plugin [$plugin] not found");
            unlink(MECCANO_TMP_DIR."/core_plugins_lock");
            return FALSE;
        }
        list($shortName, $fullName, $version, $packVersion, $plugDir, $about, $credits, $url, $email, $license) = $qPlugin->fetch_row();
        if ($packVersion != '0.2') {
                $this->setError(ERROR_INCORRECT_DATA, "install: installer is incompatible with the package specification [$packVersion]");
                unlink(MECCANO_TMP_DIR."/core_plugins_lock");
                return FALSE;
            }
        // revalidate xml components
        $plugPath = MECCANO_UNPACKED_PLUGINS."/$plugDir";
        $serviceData = new \DOMDocument();
        $xmlComponents = array(
            "languages.xml" => "langman-language-v01.rng",
            "policy.xml" => "policy-v01.rng",
            "log.xml" => "logman-events-v01.rng",
            "texts.xml" => "langman-text-v01.rng",
            "titles.xml" => "langman-title-v01.rng",
            "depends.xml" => "plugins-package-depends-v01.rng"
            );
        foreach ($xmlComponents as $valComponent=> $valSchema) {
            $xmlComponent = openRead($plugPath."/$valComponent");
            if (!$xmlComponent) {
                $this->setError(ERROR_NOT_EXECUTED, "unpack: unable to read [$valComponent]");
                unlink(MECCANO_TMP_DIR."/core_plugins_lock");
                return FALSE;
            }
            if (mime_content_type($plugPath."/$valComponent") != "application/xml") {
                $this->setError(ERROR_NOT_EXECUTED, "unpack: [$valComponent] is not XML-structured");
                unlink(MECCANO_TMP_DIR."/core_plugins_lock");
                return FALSE;
            }
            $serviceData->loadXML($xmlComponent);
            if (!@$serviceData->relaxNGValidate(MECCANO_CORE_DIR."/validation-schemas/$valSchema")) {
                $this->setError(ERROR_INCORRECT_DATA, "unpack: invalid [$valComponent] structure");
                unlink(MECCANO_TMP_DIR."/core_plugins_lock");
                return FALSE;
            }
        }
        // check for plugin dependences
        $dependsNodes = $serviceData->getElementsByTagName("plugin");
        foreach ($dependsNodes as $dependsNode) {
            $depPlugin = $depends.$dependsNode->getAttribute('name');
            $depVersion = $dependsNode->getAttribute('version');
            $operator = $dependsNode->getAttribute('operator');
            $existDep = $this->pluginData($depPlugin);
            if (!$existDep || !compareVersions($existDep["version"], $depVersion, $operator)) {
                $this->setError(ERROR_NOT_FOUND, "install: required $depPlugin ($operator $depVersion)");
                unlink(MECCANO_TMP_DIR."/core_plugins_lock");
                return FALSE;
            }
        }
        // check existence of the required files and directories
        $requiredFiles = array("inst.php", "rm.php");
        foreach ($requiredFiles as $fileName) {
            if (!is_file($plugPath."/$fileName")) {
                $this->setError(ERROR_NOT_FOUND, "install: file [$fileName] is required");
                unlink(MECCANO_TMP_DIR."/core_plugins_lock");
                return FALSE;
            }
        }
        $requiredDirs = array($plugPath."/documents", $plugPath."/js", $plugPath."/php", MECCANO_DOCUMENTS_DIR, MECCANO_JS_DIR, MECCANO_PHP_DIR);
        foreach ($requiredDirs as $dirName) {
            if (!is_dir($dirName)) {
                $this->setError(ERROR_NOT_FOUND, "install: directory [$dirName] is required");
                unlink(MECCANO_TMP_DIR."/core_plugins_lock");
                return FALSE;
            }
        }
        // get identifier and version of the being installed plugin
        if ($idAndVersion = $this->pluginData($shortName)) {
            $existId = (int) $idAndVersion["id"]; // identifier of the being reinstalled/upgraded/downgraded plugin
            $existVersion = $idAndVersion["version"]; // version of the being reinstalled/upgraded/downgraded plugin
        }
        else {
            $this->dbLink->query(
                    "INSERT INTO `".MECCANO_TPREF."_core_plugins_installed` "
                    . "(`name`, `version`) "
                    . "VALUES ('$shortName', '$version') ;"
                    );
            if ($this->dbLink->errno) {
                $this->setError(ERROR_NOT_EXECUTED, "install: ".$this->dbLink->error);
                unlink(MECCANO_TMP_DIR."/core_plugins_lock");
                return FALSE;
            }
            $existId = (int) $this->dbLink->insert_id; // identifier if the being installed plugin
            $existVersion = ""; // empty version of the being installed plugin
        }
        // insert or update information about plugin
        if ($existVersion) {
            $sql = array(
                "UPDATE `".MECCANO_TPREF."_core_plugins_installed` "
                . "SET `version`='$version' "
                . "WHERE `id`=$existId ;",
                "UPDATE `".MECCANO_TPREF."_core_plugins_installed_about` "
                . "SET `full`='$fullName', "
                . "`about`='$about', "
                . "`credits`='$credits', "
                . "`url`='$url', "
                . "`email`='$email', "
                . "`license`='$license' "
                . "WHERE `id`=$existId"
            );
        }
        else {
            $sql = array(
                "INSERT INTO `".MECCANO_TPREF."_core_plugins_installed_about` "
                . "(`id`, `full`, `about`, `credits`, `url`, `email`, `license`) "
                . "VALUES ($existId, '$fullName', '$about', '$credits', '$url', '$email', '$license') ;"
            );
        }
        foreach ($sql as $value) {
            $this->dbLink->query($value);
            if ($this->dbLink->errno) {
                $this->setError(ERROR_NOT_EXECUTED, "install: ".$this->dbLink->error);
                unlink(MECCANO_TMP_DIR."/core_plugins_lock");
                return FALSE;
            }
        }
        // run preinstallation
        require_once $plugPath.'/inst.php';
        $instObject = new Install($this->dbLink, $existId, $existVersion, $reset);
        if (!$instObject->preinst()) {
            $this->setError($instObject->errId(), "install -> ".$instObject->errExp());
            unlink(MECCANO_TMP_DIR."/core_plugins_lock");
            return FALSE;
        }
        // install languages
        $serviceData->load($plugPath.'/languages.xml');
        if (!$this->langMan->installLang($serviceData, FALSE)) {
            $this->setError($this->errId(), "install -> ".$this->errExp());
            unlink(MECCANO_TMP_DIR."/core_plugins_lock");
            return FALSE;
        }
        // install policy access rules
        $serviceData->load($plugPath.'/policy.xml');
        if (!$this->policyMan->installPolicy($serviceData, FALSE)) {
            $this->setError($this->errId(), "install -> ".$this->errExp());
            unlink(MECCANO_TMP_DIR."/core_plugins_lock");
            return FALSE;
        }
        // install log events
        $serviceData->load($plugPath.'/log.xml');
        if (!$this->logMan->installEvents($serviceData, FALSE)) {
            $this->setError($this->errId(), "install -> ".$this->errExp());
            unlink(MECCANO_TMP_DIR."/core_plugins_lock");
            return FALSE;
        }
        // install texts
        $serviceData->load($plugPath.'/texts.xml');
        if (!$this->langMan->installTexts($serviceData, FALSE)) {
            $this->setError($this->errId(), "install -> ".$this->errExp());
            unlink(MECCANO_TMP_DIR."/core_plugins_lock");
            return FALSE;
        }
        // install titles
        $serviceData->load($plugPath.'/titles.xml');
        if (!$this->langMan->installTitles($serviceData, FALSE)) {
            $this->setError($this->errId(), "install -> ".$this->errExp());
            unlink(MECCANO_TMP_DIR."/core_plugins_lock");
            return FALSE;
        }
        // copy files and directories to their destinations
        if ($shortName == "core") {
            $docDest = MECCANO_CORE_DIR;
        }
        else {
            $docDest = MECCANO_PHP_DIR."/$shortName";
        }
        $beingCopied = array(
            "documents" => MECCANO_DOCUMENTS_DIR."/$shortName",
            "php" => $docDest,
            "js" => MECCANO_JS_DIR."/$shortName",
            "rm.php" => MECCANO_UNINSTALL."/$shortName.php"
        );
        foreach ($beingCopied as $source => $dest) {
            if (!Files::copy($plugPath."/$source", $dest, TRUE, TRUE, FALSE, FALSE, FALSE, FALSE, TRUE)) {
                $this->setError(Files::errId(), "install -> ".Files::errExp());
                unlink(MECCANO_TMP_DIR."/core_plugins_lock");
                return FALSE;
            }
        }
        // run postinstallation
        if (!$instObject->postinst()) {
            $this->setError($instObject->errId(), "install -> ".$instObject->errExp());
            unlink(MECCANO_TMP_DIR."/core_plugins_lock");
            return FALSE;
        }
        //
        if ($log && !$this->newLogRecord('core', 'plugins_install', "$shortName; v$version; ID: $existId")) {
            $this->setError(ERROR_NOT_CRITICAL, "install -> ".$this->errExp());
        }
        unlink(MECCANO_TMP_DIR."/core_plugins_lock");
        return TRUE;
    }
    
    public function delInstalled($plugin, $keepData = TRUE, $log = TRUE) {
        if (!$this->lockPlugins('delInstalled')) {
            return FALSE;
        }
        if ($this->usePolicy && !$this->checkFuncAccess('core', 'plugins_del_installed')) {
            $this->setError(ERROR_RESTRICTED_ACCESS, "delInstalled: restricted by the policy");
            unlink(MECCANO_TMP_DIR."/core_plugins_lock");
            return FALSE;
        }
        if (!pregPlugin($plugin) || !is_bool($keepData)) {
            $this->setError(ERROR_INCORRECT_DATA, "delInstalled: incorrect argument(s)");
            unlink(MECCANO_TMP_DIR."/core_plugins_lock");
            return FALSE;
        }
        // check whether the plugin installed
        $qPlugin = $this->dbLink->query("SELECT `id`, `name`, `version` "
                . "FROM `".MECCANO_TPREF."_core_plugins_installed` "
                . "WHERE `name`='$plugin' ;");
        if (!$this->dbLink->affected_rows) {
            $this->setError(ERROR_NOT_FOUND, "delInstalled: plugin not found");
            unlink(MECCANO_TMP_DIR."/core_plugins_lock");
            return FALSE;
        }
        if ($this->dbLink->errno) {
            $this->setError(ERROR_NOT_EXECUTED, "delInstalled: ".$this->dbLink->error);
            unlink(MECCANO_TMP_DIR."/core_plugins_lock");
            return FALSE;
        }
        list($id, $shortName, $version) = $qPlugin->fetch_row();
        if (strtolower($shortName) == "core") {
            $this->setError(ERROR_SYSTEM_INTERVENTION, "delInstalled: unable to remove [core]");
            unlink(MECCANO_TMP_DIR."/core_plugins_lock");
            return FALSE;
        }
        // check whether the removement script exists
        if (!is_file(MECCANO_UNINSTALL."/$shortName.php")) {
            $this->setError(ERROR_NOT_FOUND, "delInstalled: removement script [".MECCANO_UNINSTALL."/$shortName.php] not found");
            unlink(MECCANO_TMP_DIR."/core_plugins_lock");
            return FALSE;
        }
        //run preremovement
        require_once MECCANO_UNINSTALL."/$shortName.php";
        $rmObject = new Remove($this->dbLink, $id, $keepData);
        if (!$rmObject->prerm()) {
            $this->setError($rmObject->errId(), "delInstalled -> ".$rmObject->errExp());
            unlink(MECCANO_TMP_DIR."/core_plugins_lock");
            return FALSE;
        }
        // remove policy access rules
        if (!$this->policyMan->delPolicy($shortName)) {
            $this->setError($this->errId(), "delInstalled -> ".$this->errExp());
            unlink(MECCANO_TMP_DIR."/core_plugins_lock");
            return FALSE;
        }
        // remove log events
        if (!$this->logMan->delLogEvents($shortName)) {
            $this->setError($this->errId(), "delInstalled -> ".$this->errExp());
            unlink(MECCANO_TMP_DIR."/core_plugins_lock");
            return FALSE;
        }
        // remove texts and titles
        if (!$this->langMan->delPlugin($shortName)) {
            $this->setError($this->errId(), "delInstalled -> ".$this->errExp());
            unlink(MECCANO_TMP_DIR."/core_plugins_lock");
            return FALSE;
        }
        // run postremovement
        if (!$rmObject->postrm()) {
            $this->setError($rmObject->errId(), "delInstalled -> ".$rmObject->errExp());
            unlink(MECCANO_TMP_DIR."/core_plugins_lock");
            return FALSE;
        }
        // remove files and directories of the plugin
        $beingRemoved = array(
            "php" => MECCANO_PHP_DIR."/$shortName",
            "js" => MECCANO_JS_DIR."/$shortName",
            "rm.php" => MECCANO_UNINSTALL."/$shortName.php"
        );
        if (!$keepData) {
            $beingRemoved["documents"] = MECCANO_DOCUMENTS_DIR."/$shortName";
        }
        foreach ($beingRemoved as $source) {
            if (!Files::remove($source)) {
                $this->setError(Files::errId(), "delInstalled -> ".Files::errExp());
                unlink(MECCANO_TMP_DIR."/core_plugins_lock");
                return FALSE;
            }
        }
        // delete information about plugin
        $sql = array(
            "DELETE FROM `".MECCANO_TPREF."_core_plugins_installed_about` "
            . "WHERE `id`=$id",
            "DELETE FROM `".MECCANO_TPREF."_core_plugins_installed` "
            . "WHERE `id`=$id",
        );
        foreach ($sql as $value) {
            $this->dbLink->query($value);
            if ($this->dbLink->errno) {
                $this->setError(ERROR_NOT_EXECUTED, "delInstalled: ".$this->dbLink->error);
                unlink(MECCANO_TMP_DIR."/core_plugins_lock");
                return FALSE;
            }
        }
        //
        if ($log && !$this->newLogRecord('core', 'plugins_del_installed', "$shortName; v$version; ID: $id")) {
            $this->setError(ERROR_NOT_CRITICAL, "install -> ".$this->errExp());
        }
        unlink(MECCANO_TMP_DIR."/core_plugins_lock");
        return TRUE;
    }
    
    public function listInstalled() {
        $this->zeroizeError();
        if ($this->usePolicy && !$this->checkFuncAccess('core', 'plugins_installed')) {
            $this->setError(ERROR_RESTRICTED_ACCESS, "listInstalled: restricted by the policy");
            return FALSE;
        }
        $qInstalled = $this->dbLink->query("SELECT `i`.`name`, `a`.`full`, `i`.`version`, `i`.`time` "
                . "FROM `".MECCANO_TPREF."_core_plugins_installed` `i` "
                . "JOIN `".MECCANO_TPREF."_core_plugins_installed_about` `a` "
                . "ON `a`.`id`=`i`.`id` ;");
        if ($this->dbLink->errno) {
            $this->setError(ERROR_NOT_EXECUTED, "listInstalled: ".$this->dbLink->error);
            return FALSE;
        }
        if ($this->outputType == 'xml') {
            $xml = new \DOMDocument('1.0', 'utf-8');
            $installedNode = $xml->createElement("installed");
            $xml->appendChild($installedNode);
            while ($row = $qInstalled->fetch_row()) {
                $pluginNode = $xml->createElement("plugin");
                $installedNode->appendChild($pluginNode);
                $pluginNode->appendChild($xml->createElement("short", $row[0]));
                $pluginNode->appendChild($xml->createElement("full", $row[1]));
                $pluginNode->appendChild($xml->createElement("version", $row[2]));
                $pluginNode->appendChild($xml->createElement("time", $row[3]));
            }
            return $xml;
        }
        else {
            $installed = array();
            while ($row = $qInstalled->fetch_row()) {
                $installed[] = array(
                    "short" => $row[0],
                    "full" => $row[1],
                    "version" => $row[2],
                    "time" => $row[3]
                );
            }
            return json_encode($installed);
        }
    }
    
    public function aboutInstalled($plugin) {
        $this->zeroizeError();
        if ($this->usePolicy && !$this->checkFuncAccess('core', 'plugins_installed')) {
            $this->setError(ERROR_RESTRICTED_ACCESS, "aboutInstalled: restricted by the policy");
            return FALSE;
        }
        if (!pregPlugin($plugin)) {
            $this->setError(ERROR_INCORRECT_DATA, "aboutInstalled: incorrect plugin name");
            return FALSE;
        }
        $qPlugin = $this->dbLink->query("SELECT `i`.`name`, `a`.`full`, `i`.`version`, `i`.`time`, `a`.`about`, `a`.`credits`, `a`.`url`, `a`.`email`, `a`.`license` "
                . "FROM `".MECCANO_TPREF."_core_plugins_installed` `i` "
                . "JOIN `".MECCANO_TPREF."_core_plugins_installed_about` `a` "
                . "ON `a`.`id`=`i`.`id` "
                . "WHERE `i`.`name`='$plugin' ;");
        if ($this->dbLink->errno) {
            $this->setError(ERROR_NOT_EXECUTED, "aboutInstalled: ".$this->dbLink->error);
            return FALSE;
        }
        if (!$this->dbLink->affected_rows) {
            $this->setError(ERROR_NOT_FOUND, "aboutInstalled: plugin not found");
            return FALSE;
        }
        list($shortName, $fullName, $version, $instTime, $about, $credits, $url, $email, $license) = $qPlugin->fetch_row();
        if ($this->outputType == 'xml') {
            $xml = new \DOMDocument('1.0', 'utf-8');
            $installedNode = $xml->createElement("installed");
            $xml->appendChild($installedNode);
            $installedNode->appendChild($xml->createElement("short", $shortName));
            $installedNode->appendChild($xml->createElement("full", $fullName));
            $installedNode->appendChild($xml->createElement("version", $version));
            $installedNode->appendChild($xml->createElement("time", $instTime));
            $installedNode->appendChild($xml->createElement("about", $about));
            $installedNode->appendChild($xml->createElement("credits", $credits));
            $installedNode->appendChild($xml->createElement("url", $url));
            $installedNode->appendChild($xml->createElement("email", $email));
            $installedNode->appendChild($xml->createElement("license", $license));
            return $xml;
        }
        else {
            $installed = array(
                "short" => $shortName,
                "full" => $fullName,
                "version" => $version,
                "time" => $instTime,
                "about" =>$about,
                "credits" => $credits,
                "url" => $url,
                "email" => $email,
                "license" => $license
            );
            return json_encode($installed);
        }
    }
}
