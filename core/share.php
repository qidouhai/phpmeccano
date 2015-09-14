<?php

/*
 *     phpMeccano v0.0.2. Web-framework written with php programming language. Core module [share.php].
 *     Copyright (C) 2015  Alexei Muzarov
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

require_once 'logman.php';

interface intShare {
    public function __construct(LogMan $logObject);
    public function createCircle($userId, $name);
}

class Share extends ServiceMethods implements intShare {
    private $dbLink; // database link
    private $logObject; // log object
    private $policyObject; // policy object
    
    public function __construct(LogMan $logObject) {
        $this->dbLink = $logObject->dbLink;
        $this->logObject = $logObject;
        $this->policyObject = $logObject->policyObject;
    }
    
    function createCircle($userId, $name) {
        $this->zeroizeError();
        if (!is_integer($userId) || !is_string($name) || !strlen($name)) {
            $this->setError(ERROR_INCORRECT_DATA, 'createCircle: incorrect parameters');
            return FALSE;
        }
        $qUser = $this->dbLink->query(
                "SELECT `username` "
                . "FROM `".MECCANO_TPREF."_core_userman_users` "
                . "WHERE `id`=$userId ;"
                );
        if ($this->dbLink->errno) {
            $this->setError(ERROR_NOT_EXECUTED, 'createCircle: '.$this->dbLink->error);
            return FALSE;
        }
        if (!$this->dbLink->affected_rows) {
            $this->setError(ERROR_NOT_FOUND, 'createCircle: user not found');
            return FALSE;
        }
        $name = $this->dbLink->real_escape_string(htmlspecialchars($name));
        $id = guid();
        $this->dbLink->query(
                "INSERT INTO `".MECCANO_TPREF."_core_share_circles` "
                . "(`id`, `userid`, `cname`) "
                . "VALUES('$id', $userId, '$name') ;"
                );
        if ($this->dbLink->errno) {
            $this->setError(ERROR_NOT_EXECUTED, 'createCircle: unable to create circle -> '.$this->dbLink->error);
            return FALSE;
        }
        return $id;
    }
}
