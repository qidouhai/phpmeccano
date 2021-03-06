<?php

/*
 *     phpMeccano v0.2.0. Web-framework written with php programming language. Core module [unifunctions.php].
 *     Copyright (C) 2015-2019  Alexei Muzarov
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

require_once MECCANO_CORE_DIR.'/swconst.php';

// it generates and returns unique password salt
function makeSalt($prefix = '') {
    return substr(base64_encode(sha1(uniqid().microtime(true).mt_rand(1, PHP_INT_MAX).uniqid($prefix, true))), 0, 22);
}

// it generates and returns unique identifier for user, session etc.
function makeIdent($prefix = '') {
    return sha1(uniqid().microtime(true).mt_rand(1, PHP_INT_MAX).uniqid($prefix, true));
}

// it calculates password hash
function passwHash($password, $salt) {
    if (is_string($password) && strlen($password)<51 && strlen($password)>7 && is_string($salt) && strlen($salt)==22) {
        return sha1(crypt($password, '$2y$10$'.$salt));
    }
    else {
        return false;
    }
}

// it checks database name
function pregDbName($dbname) {
    if (is_string($dbname) && preg_match('/^[a-zA-Z\d_]{1,64}$/', $dbname)) {
        return true;
    }
    return false;
}

// it checks database prefix
function pregPref($prefix) {
    if (is_string($prefix) && preg_match('/^[a-zA-Z\d]{1,10}$/', $prefix)) {
        return true;
    }
    return false;
}

// it checks user name
function pregUName($username) {
    if (is_string($username) && preg_match('/^[a-zA-Z\d_]{3,20}$/', $username)) {
        return true;
    }
    return false;
}

// it checks group name
function pregGName($groupname) {
    if (is_string($groupname) && preg_match('/^.{1,50}$/', $groupname) && preg_replace("/[\s\n\r\t]+/", "", $groupname)) {
        return true;
    }
    return false;
}

// it checks entered password
function pregPassw($password) {
    if (is_string($password) && preg_match('/^[-+=_a-zA-Z\d@.,?!;:"\'~`|#*$%&^\][(){}<>\/\\\]{8,50}$/', $password)) {
        return true;
    }
    return false;
}

// it checks entered identifier
function pregIdent($ident) {
    if (is_string($ident) && preg_match('/^[a-zA-Z\d]{40}$/', $ident)) {
        return true;
    }
    return false;
}

// it checks entered guid
function pregGuid($guid) {
    if (is_string($guid) && preg_match('/^[a-z\d]{8}-[a-z\d]{4}-4[a-z\d]{3}-[a-z\d]{4}-[a-z\d]{12}$/', $guid)) {
        return true;
    }
    return false;
}

// guid generator
// used modified code from http://php.net/manual/en/function.com-create-guid.php#117893
function guid() {
    $data = openssl_random_pseudo_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // set version to 0100
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // set bits 6-7 to 10
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

// it checks entered language code
function pregLang($code) {
    if (is_string($code) && preg_match('/^[a-z]{2}-[A-Z]{2}$/', $code)) {
        return true;
    }
    return false;
}

// it checks entered plugin name
function pregPlugin($name) {
    if (is_string($name) && preg_match('/^[a-zA-Z\d_]{3,30}$/', $name)) {
        return true;
    }
    return false;
}

function pregMail($email) {
    // regex from Blink engine
    // https://chromium.googlesource.com/chromium/blink/+/master/Source/core/html/forms/EmailInputType.cpp
    // line 48
    if (is_string($email) && preg_match("/^[a-z0-9!#$%&'*+\/=?^_`{|}~.-]+@[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/", $email)) {
        return true;
    }
    return false;
}

// it checks name identifiers with length from 3 to 40 characters
function pregName40($name) {
    if (is_string($name) && preg_match('/^[a-zA-Z\d_]{3,40}$/', $name)) {
        return true;
    }
    return false;
}

// it reads and returns content of defined file
function openRead($path) {
    if (is_file($path) && is_readable($path)) {
        $handle = fopen($path, 'r');
        $fileSize = filesize($path);
        if (!$fileSize) {
            fclose($handle);
            return false;
        }
        $data = fread($handle, $fileSize);
        fclose($handle);
        return $data;
    }
    return false;
}

// transform XML with XSLT
function xmlTransform($xml, $xsl) {
    $xmlDOM = new \DOMDocument();
    $xslDOM = new \DOMDocument();
    if ($xmlDOM->loadXML($xml) && $xslDOM->loadXML($xsl)) {
        $xslt = new \XSLTProcessor();
        $xslt->importStylesheet($xslDOM);
        if ($transformed = $xslt->transformToDoc($xmlDOM)) {
            return $transformed;
        }
    }
    return false;
}

// general information about session
function authUserId() {
    if (isset($_SESSION[AUTH_USER_ID])) {
        return $_SESSION[AUTH_USER_ID];
    }
    return false;
}

// returns username if there is active session
function authUName() {
    if (isset($_SESSION[AUTH_USERNAME])) {
        return $_SESSION[AUTH_USERNAME];
    }
    return false;
}

// returns session type if there is active session
function authLimited() {
    if (isset($_SESSION[AUTH_LIMITED])) {
        return $_SESSION[AUTH_LIMITED];
    }
    return false;
}

// returns language code if there is active session
function authLang() {
    if (isset($_SESSION[AUTH_LANGUAGE])) {
        return $_SESSION[AUTH_LANGUAGE];
    }
    return false;
}

// returns language code if there is active session
function authLangDir() {
    if (isset($_SESSION[AUTH_LANGUAGE_DIR])) {
        return $_SESSION[AUTH_LANGUAGE_DIR];
    }
    return false;
}

// returns session token if there is active session
function authToken() {
    if (isset($_SESSION[AUTH_TOKEN])) {
        return $_SESSION[AUTH_TOKEN];
    }
    return false;
}

// compares two versions
function compareVersions($version1, $version2, $operator = ">=") {
    if (!is_string($version1) || !preg_match('/^[0-9]{1,2}\.[0-9]{1,2}\.[0-9]{1,2}$/', $version1) || !is_string($version2) || !preg_match('/^[0-9]{1,2}\.[0-9]{1,2}\.[0-9]{1,2}$/', $version2) || !in_array($operator, [">=", "<=", "==", ">", "<", "!="])) {
        return false;
    }
    // calculate first summary version
    list($uv1, $mv1, $lv1) = explode(".", $version1);
    $sumVersion1 = 10000*$uv1 + 100*$mv1 + $lv1;
    // calculate second summary version
    list($uv2, $mv2, $lv2) = explode(".", $version2);
    $sumVersion2 = 10000*$uv2 + 100*$mv2 + $lv2;
    // compare versions
    if ($operator == ">=" && $version1 >= $version2) {
        return true;
    }
    elseif ($operator == "<=" && $version1 <= $version2) {
        return true;
    }
    elseif ($operator == "==" && $version1 == $version2) {
        return true;
    }
    elseif ($operator == ">" && $version1 > $version2) {
        return true;
    }
    elseif ($operator == "<" && $version1 < $version2) {
        return true;
    }
    elseif ($operator == "!=" && $version1 != $version2) {
        return true;
    }
    return false;
}

// calculates summary version
function calcSumVersion($version) {
    if (!is_string($version) || !preg_match('/^[0-9]{1,2}\.[0-9]{1,2}\.[0-9]{1,2}$/', $version)) {
        return false;
    }
    list($uv, $mv, $lv) = explode(".", $version);
    $sumVersion = 10000*$uv + 100*$mv + $lv;
    return (int) $sumVersion;
}

// password generator
function genPassword($length = 8, $lower = true, $upper = true, $numbers = true, $underline = true, $minus = true, $special = true) {
    if (!is_integer($length) || !($length >= 8 && $length <= 50)) {
        return false;
    }
    // charachter groups
    $allGroups = [
        'lower' => 'abcdefghijklmnopqrstuwxyz',
        'upper' => 'ABCDEFGHIJKLMNOPQRSTUWXYZ',
        'numbers' => '0123456789',
        'underline' => '_',
        'minus' => '-',
        'special' => '+=@.,?!;:"\'~`|#*$%&^][(){}<>/\\'
    ];
    $parameters = [
        "lower" => $lower,
        "upper" => $upper,
        "numbers" => $numbers,
        "underline" => $underline,
        "minus" => $minus,
        "special" => $special
    ];
    $groupsInUse = [];
    $groupLimits = [];
    foreach ($parameters as $key => $value) {
        if ($value) {
            $groupLimits[$key] = strlen($allGroups[$key]) - 1;
            $groupsInUse[] = $key;
        }
    }
    // check whether at least one group is used
    if (!count($groupsInUse)) {
        return false;
    }
    // temporary list of groups
    $tmpGroups = [];
    $password = '';
    for ($i = 0; $i < $length; $i++) {
        if (!count($tmpGroups)) {
            $tmpGroups = $groupsInUse;
            shuffle($tmpGroups);
        }
        $curGroup = array_pop($tmpGroups);
        $password = $password.$allGroups[$curGroup][mt_rand(0, $groupLimits[$curGroup])];
    }
    return $password;
}
