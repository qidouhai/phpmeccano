<?php

/*
 *     phpMeccano v0.2.0. Web-framework written with php programming language. Core module [__loader__.php].
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

// a function to load PHP libraries of the core or any installed plugin
function loadPHP($lib, $plugin = "core") {
    if (!is_string($lib) || preg_match('/.*\.\.\/*./', $lib) || !is_string($plugin) || preg_match('/.*\.\.\/*./', $plugin)) {
        return null;
    }
    if ($plugin == "core") {
        $fullPath = realpath(MECCANO_CORE_DIR."/$lib.php");
        if ($fullPath && is_file($fullPath) && is_readable($fullPath)) {
            require_once $fullPath;
            return true;
        }
    }
    else {
        $fullPath = realpath(MECCANO_PHP_DIR."/$plugin/$lib.php");
        if ($fullPath && is_file($fullPath) && is_readable($fullPath)) {
            require_once $fullPath;
            return true;
        }
    }
    return false;
}

// a function to load JavaScript libraries of the core or any installed plugin
function loadJS($lib, $plugin = "core") {
    if (!is_string($lib) || preg_match('/.*\.\.\/*./', $lib) || !is_string($plugin) || preg_match('/.*\.\.\/*./', $plugin)) {
        return null;
    }
    $fullPath = realpath(MECCANO_JS_DIR."/$plugin/$lib.js");
    if ($fullPath && is_file($fullPath) && is_readable($fullPath)) {
        return file_get_contents($fullPath);
    }
    return false;
}

// a function to load CSS libraries of the core or any installed plugin
function loadCSS($lib, $plugin = "core") {
    if (!is_string($lib) || preg_match('/.*\.\.\/*./', $lib) || !is_string($plugin) || preg_match('/.*\.\.\/*./', $plugin)) {
        return null;
    }
    $fullPath = realpath(MECCANO_CSS_DIR."/$plugin/$lib.css");
    if ($fullPath && is_file($fullPath) && is_readable($fullPath)) {
        return file_get_contents($fullPath);
    }
    return false;
}

// a function to load documents and files of the core or any installed plugin
function loadDOC($doc, $plugin = "core", $disp = "inline", $nocache = false) {
    if (!isset($_SERVER['SERVER_SOFTWARE'])) {
        return false; // The function must be executed on a web server
    }
    if (!is_string($doc) || preg_match('/.*\.\.\/*./', $doc) || !is_string($plugin) || preg_match('/.*\.\.\/*./', $plugin) || !in_array($disp, ['inline', 'attachment'])) {
        include MECCANO_SERVICE_PAGES.'/400.php'; // Bad Request
        exit();
    }
    $fullPath = realpath(MECCANO_DOCUMENTS_DIR."/$plugin/$doc");
    if (!$fullPath || !is_file($fullPath)) {
        include MECCANO_SERVICE_PAGES.'/404.php'; // Not Found
        exit();
    }
    if (!is_readable($fullPath)) {
        include MECCANO_SERVICE_PAGES.'/403.php'; // Forbidden
        exit();
    }
    if (preg_match('/.*Apache.*/', $_SERVER['SERVER_SOFTWARE'])) {
        // https://tn123.org/mod_xsendfile/
        header("X-SendFile: $fullPath");
    }
    elseif (preg_match('/.*nginx.*/', $_SERVER['SERVER_SOFTWARE'])) {
        // https://www.nginx.com/resources/wiki/start/topics/examples/xsendfile/
        header("X-Accel-Redirect: /".basename(MECCANO_DOCUMENTS_DIR)."/$plugin/$doc");
    }
    elseif (preg_match('/.*lighttpd.*/', $_SERVER['SERVER_SOFTWARE'])) {
        // https://redmine.lighttpd.net/projects/lighttpd/wiki/X-LIGHTTPD-send-file
        header("X-LIGHTTPD-send-file: $fullPath");
    }
    else {
        include MECCANO_SERVICE_PAGES.'/501.php'; // Not Implemented
        exit();
    }
    if ($nocache) { // if the file should't be cached
        header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
        header("Pragma: no-cache");
    }
    $mimeType = mime_content_type($fullPath);
    $fileSize = filesize($fullPath);
    $fileName = basename($fullPath);
    header("Content-Type: $mimeType");
    header("Content-Length: $fileSize");
    header("Content-Disposition: $disp; filename=$fileName");
    exit();
}

function mntc() {
    $conf = json_decode(file_get_contents(MECCANO_SERVICE_PAGES.'/maintenance.json'));
    if (is_object($conf) && isset($conf->enabled) && isset($conf->startpoint) && $conf->enabled && $conf->startpoint<=time() && !in_array($_SERVER['REMOTE_ADDR'], preg_split('/\s*,\s*/', preg_replace('/\s\s+/', ' ', MECCANO_MNTC_IP)), true)) {
        include MECCANO_SERVICE_PAGES.'/maintenance.php'; // Maintenance mode is enabled
        exit();
    }
    return false; // Maintenance mode is disabled
}

function dbLink() {
    $db = new \mysqli(MECCANO_DBHOST, MECCANO_DBANAME, MECCANO_DBAPASS, MECCANO_DBNAME, MECCANO_DBPORT);
    $db->set_charset("utf8mb4");
    return $db;
}
