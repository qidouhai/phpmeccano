<?php

/*
 *     phpMeccano v0.0.2. Web-framework written with php programming language. Core module [discuss.php].
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

require_once MECCANO_CORE_DIR.'/logman.php';

interface intDiscuss {
    public function __construct(LogMan $logObject);
    public function createTopic($topic = '');
    public function createComment($comment, $userId, $topicId, $parentId = '');
    public function getComments($topicId, $rpp = 20);
    public function appendComments($topicId, $minMark, $rpp = 20);
    public function updateComments($topicId, $maxMark);
}

class Discuss extends ServiceMethods implements intDiscuss {
    private $dbLink; // database link
    private $logObject; // log object
    private $policyObject; // policy object
    
    public function __construct(LogMan $logObject) {
        $this->dbLink = $logObject->dbLink;
        $this->logObject = $logObject;
        $this->policyObject = $logObject->policyObject;
    }
    
    public function createTopic($topic = '') {
        $this->zeroizeError();
        if (!is_string($topic)) {
            $this->setError(ERROR_INCORRECT_DATA, 'createTopic: incorrect parameter');
            return FALSE;
        }
        $topicId = guid();
        $topicText = $this->dbLink->escape_string($topic);
        $this->dbLink->query(
                "INSERT INTO `".MECCANO_TPREF."_core_discuss_topics` "
                . "(`id`, `topic`) "
                . "VALUES('$topicId', '$topicText') ;"
                );
        if ($this->dbLink->errno) {
            $this->setError(ERROR_NOT_EXECUTED, 'createTopic: unable to create topic -> '.$this->dbLink->error);
            return FALSE;
        }
        return $topicId;
    }
    
    // in case of using MyISAM storage engine you should check correctness of userid argument before you call this method
    public function createComment($comment, $userId, $topicId, $parentId = '') {
        $this->zeroizeError();
        if (!is_string($comment) || !strlen($comment) || !pregGuid($topicId) || (!pregGuid($parentId) && $parentId != '')) {
            $this->setError(ERROR_INCORRECT_DATA, 'createComment: incorrect parameters');
            return FALSE;
        }
        if (MECCANO_DBSTORAGE_ENGINE == 'MyISAM') {
            // check whether topic exists
            $this->dbLink->query(
                    "SELECT `id` "
                    . "FROM `".MECCANO_TPREF."_core_discuss_topics` "
                    . "WHERE `id`='$topicId' ;"
                    );
            if ($this->dbLink->errno) {
                $this->setError(ERROR_NOT_EXECUTED, 'createComment: unable to check whether topic exists -> '.$this->dbLink->error);
                return FALSE;
            }
            if (!$this->dbLink->affected_rows) {
                $this->setError(ERROR_NOT_FOUND, 'createComment: topic not found');
                return FALSE;
            }
            if ($parentId) {
                // check whether parent comment exists
                $this->dbLink->query(
                        "SELECT `id` "
                        . "FROM `".MECCANO_TPREF."_core_discuss_comments` "
                        . "WHERE `id`='$parentId' ;"
                        );
                if ($this->dbLink->errno) {
                    $this->setError(ERROR_NOT_EXECUTED, 'createComment: unable to check whether parent comment exists -> '.$this->dbLink->error);
                    return FALSE;
                }
                if (!$this->dbLink->affected_rows) {
                    $this->setError(ERROR_NOT_FOUND, 'createComment: parent comment not found');
                    return FALSE;
                }
            }
        }
        $commentId = guid();
        $commentText = $this->dbLink->escape_string($comment);
        $mtMark = microtime(TRUE);
        if ($parentId) {
            $query = "INSERT INTO `".MECCANO_TPREF."_core_discuss_comments` "
                . "(`id`, `tid`, `pcid`, `userid`, `comment`, `microtime`) "
                . "VALUES('$commentId', '$topicId', '$parentId', $userId, '$commentText', $mtMark) ;";
        }
        else {
            $query = "INSERT INTO `".MECCANO_TPREF."_core_discuss_comments` "
                . "(`id`, `tid`, `userid`, `comment`, `microtime`) "
                . "VALUES('$commentId', '$topicId', $userId, '$commentText', $mtMark) ;";
        }
        $this->dbLink->query($query);
        if ($this->dbLink->errno) {
            $this->setError(ERROR_NOT_EXECUTED, 'createComment: unable to create comment -> '.$this->dbLink->error);
            return FALSE;
        }
        return $commentId;
    }
    
    public function getComments($topicId, $rpp = 20) {
        $this->zeroizeError();
        if (!pregGuid($topicId) || !is_integer($rpp)) {
            $this->setError(ERROR_INCORRECT_DATA, 'getComments: incorrect parameter');
            return FALSE;
        }
        if ($rpp < 1) {
            $rpp = 1;
        }
        // check whether topic exists
        $qTopic = $this->dbLink->query(
                "SELECT `topic` "
                . "FROM `".MECCANO_TPREF."_core_discuss_topics` "
                . "WHERE `id`='$topicId' ;"
                );
        if ($this->dbLink->errno) {
            $this->setError(ERROR_NOT_EXECUTED, 'getComments: unable to check whether topic exists -> '.$this->dbLink->error);
            return FALSE;
        }
        if (!$this->dbLink->affected_rows) {
            $this->setError(ERROR_NOT_FOUND, 'getComments: topic not found -> '.$this->dbLink->error);
            return FALSE;
        }
        $topicRow = $qTopic->fetch_row();
        $topic = $topicRow[0];
        // get comments of topic
        $qComments = $this->dbLink->query(
                "SELECT `u`.`username`, `i`.`fullname`, `c`.`id`, `c`.`pcid`, `c`.`comment`, `c`.`time`, `c`.`microtime` "
                . "FROM `".MECCANO_TPREF."_core_discuss_comments` `c` "
                . "JOIN `".MECCANO_TPREF."_core_userman_users` `u` "
                . "ON `u`.`id`=`c`.`userid` "
                . "JOIN `".MECCANO_TPREF."_core_userman_userinfo` `i` "
                . "ON `i`.`id`=`c`.`userid` "
                . "WHERE `c`.`tid`='$topicId' "
                . "ORDER BY `c`.`microtime` DESC LIMIT $rpp ;"
                );
        if ($this->dbLink->errno) {
            $this->setError(ERROR_NOT_EXECUTED, 'getComments: unable to get comments -> '.$this->dbLink->error);
            return FALSE;
        }
        if ($this->outputType == 'xml') {
            $xml = new \DOMDocument('1.0', 'utf-8');
            $comsNode = $xml->createElement('comments');
            $xml->appendChild($comsNode);
        }
        else {
            $comsNode['comments'] = array();
        }
        // defaul values of min and max microtime marks
        $minMark = 0;
        $maxMark = 0;
        //
        while ($comData= $qComments->fetch_row()) {
            list($userName, $fullName, $comId, $parId, $text, $comTime, $mtMark) = $comData;
            if (!$maxMark) {
                $maxMark = $mtMark;
            }
            if ($this->outputType == 'xml') {
                // create nodes
                $comNode = $xml->createElement('comment');
                $userNode = $xml->createElement('username', $userName);
                $nameNode = $xml->createElement('fullname', $fullName);
                $cidNode = $xml->createElement('cid', $comId);
                $pcidNode = $xml->createElement('pcid', $parId);
                $textNode = $xml->createElement('text', $text);
                $timeNode = $xml->createElement('time', $comTime);
                // insert nodes
                $comsNode->appendChild($comNode);
                $comNode->appendChild($userNode);
                $comNode->appendChild($nameNode);
                $comNode->appendChild($cidNode);
                $comNode->appendChild($pcidNode);
                $comNode->appendChild($textNode);
                $comNode->appendChild($timeNode);
            }
            else {
                $comsNode['comments'][] = array(
                    'username' => $userName,
                    'fullname' => $fullName,
                    'cid' => $comId,
                    'pcid' => $parId,
                    'text' => $text,
                    'time' => $comTime
                );
            }
        }
        if ($maxMark && !$minMark) {
            $minMark = $mtMark;
        }
        if ($this->outputType == 'xml') {
            $topicNode = $xml->createAttribute('topic');
            $topicNode->value = $topic;
            $tidNode = $xml->createAttribute('tid');
            $tidNode->value = $topicId;
            $minNode = $xml->createAttribute('minmark');
            $minNode->value = $minMark;
            $maxNode = $xml->createAttribute('maxmark');
            $maxNode->value = $maxMark;
            $comsNode->appendChild($topicNode);
            $comsNode->appendChild($tidNode);
            $comsNode->appendChild($minNode);
            $comsNode->appendChild($maxNode);
            return $xml;
        }
        else {
            $comsNode['topic'] = $topic;
            $comsNode['tid'] = $topicId;
            $comsNode['minmark'] = (double) $minMark;
            $comsNode['maxmark'] = (double) $maxMark;
            return json_encode($comsNode);
        }
    }
    
    public function appendComments($topicId, $minMark, $rpp = 20) {
        $this->zeroizeError();
        if (!pregGuid($topicId) || !is_double($minMark) || !is_integer($rpp)) {
            $this->setError(ERROR_INCORRECT_DATA, 'appendComments: incorrect parameter');
            return FALSE;
        }
        if ($rpp < 1) {
            $rpp = 1;
        }
        // get comments of topic
        $qComments = $this->dbLink->query(
                "SELECT `u`.`username`, `i`.`fullname`, `c`.`id`, `c`.`pcid`, `c`.`comment`, `c`.`time`, `c`.`microtime` "
                . "FROM `".MECCANO_TPREF."_core_discuss_comments` `c` "
                . "JOIN `".MECCANO_TPREF."_core_userman_users` `u` "
                . "ON `u`.`id`=`c`.`userid` "
                . "JOIN `".MECCANO_TPREF."_core_userman_userinfo` `i` "
                . "ON `i`.`id`=`c`.`userid` "
                . "WHERE `c`.`tid`='$topicId' "
                . "AND `c`.`microtime`<$minMark "
                . "ORDER BY `c`.`microtime` DESC LIMIT $rpp ;"
                );
        if ($this->dbLink->errno) {
            $this->setError(ERROR_NOT_EXECUTED, 'appendComments: unable to get comments -> '.$this->dbLink->error);
            return FALSE;
        }
        if ($this->outputType == 'xml') {
            $xml = new \DOMDocument('1.0', 'utf-8');
            $comsNode = $xml->createElement('comments');
            $xml->appendChild($comsNode);
        }
        else {
            $comsNode['comments'] = array();
        }
        // defaul values of max microtime mark
        $maxMark = 0;
        //
        while ($comData= $qComments->fetch_row()) {
            list($userName, $fullName, $comId, $parId, $text, $comTime, $mtMark) = $comData;
            if (!$maxMark) {
                $maxMark = $mtMark;
            }
            if ($this->outputType == 'xml') {
                // create nodes
                $comNode = $xml->createElement('comment');
                $userNode = $xml->createElement('username', $userName);
                $nameNode = $xml->createElement('fullname', $fullName);
                $cidNode = $xml->createElement('cid', $comId);
                $pcidNode = $xml->createElement('pcid', $parId);
                $textNode = $xml->createElement('text', $text);
                $timeNode = $xml->createElement('time', $comTime);
                // insert nodes
                $comsNode->appendChild($comNode);
                $comNode->appendChild($userNode);
                $comNode->appendChild($nameNode);
                $comNode->appendChild($cidNode);
                $comNode->appendChild($pcidNode);
                $comNode->appendChild($textNode);
                $comNode->appendChild($timeNode);
            }
            else {
                $comsNode['comments'][] = array(
                    'username' => $userName,
                    'fullname' => $fullName,
                    'cid' => $comId,
                    'pcid' => $parId,
                    'text' => $text,
                    'time' => $comTime
                );
            }
        }
        if ($maxMark) {
            $minMark = $mtMark;
        }
        if ($this->outputType == 'xml') {
            $minNode = $xml->createAttribute('minmark');
            $minNode->value = $minMark;
            $comsNode->appendChild($minNode);
            return $xml;
        }
        else {
            $comsNode['minmark'] = (double) $minMark;
            return json_encode($comsNode);
        }
    }
    
    public function updateComments($topicId, $maxMark) {
        $this->zeroizeError();
        if (!pregGuid($topicId) || !is_double($maxMark)) {
            $this->setError(ERROR_INCORRECT_DATA, 'updateComments: incorrect parameter');
            return FALSE;
        }
        // get comments of topic
        $qComments = $this->dbLink->query(
                "SELECT `u`.`username`, `i`.`fullname`, `c`.`id`, `c`.`pcid`, `c`.`comment`, `c`.`time`, `c`.`microtime` "
                . "FROM `".MECCANO_TPREF."_core_discuss_comments` `c` "
                . "JOIN `".MECCANO_TPREF."_core_userman_users` `u` "
                . "ON `u`.`id`=`c`.`userid` "
                . "JOIN `".MECCANO_TPREF."_core_userman_userinfo` `i` "
                . "ON `i`.`id`=`c`.`userid` "
                . "WHERE `c`.`tid`='$topicId' "
                . "AND `c`.`microtime`>$maxMark "
                . "ORDER BY `c`.`microtime` DESC ;"
                );
        if ($this->dbLink->errno) {
            $this->setError(ERROR_NOT_EXECUTED, 'updateComments: unable to get comments -> '.$this->dbLink->error);
            return FALSE;
        }
        if ($this->outputType == 'xml') {
            $xml = new \DOMDocument('1.0', 'utf-8');
            $comsNode = $xml->createElement('comments');
            $xml->appendChild($comsNode);
        }
        else {
            $comsNode['comments'] = array();
        }
        // defaul values of min and max microtime marks
        $maxMark = 0;
        //
        while ($comData= $qComments->fetch_row()) {
            list($userName, $fullName, $comId, $parId, $text, $comTime, $mtMark) = $comData;
            if (!$maxMark) {
                $maxMark = $mtMark;
            }
            if ($this->outputType == 'xml') {
                // create nodes
                $comNode = $xml->createElement('comment');
                $userNode = $xml->createElement('username', $userName);
                $nameNode = $xml->createElement('fullname', $fullName);
                $cidNode = $xml->createElement('cid', $comId);
                $pcidNode = $xml->createElement('pcid', $parId);
                $textNode = $xml->createElement('text', $text);
                $timeNode = $xml->createElement('time', $comTime);
                // insert nodes
                $comsNode->appendChild($comNode);
                $comNode->appendChild($userNode);
                $comNode->appendChild($nameNode);
                $comNode->appendChild($cidNode);
                $comNode->appendChild($pcidNode);
                $comNode->appendChild($textNode);
                $comNode->appendChild($timeNode);
            }
            else {
                $comsNode['comments'][] = array(
                    'username' => $userName,
                    'fullname' => $fullName,
                    'cid' => $comId,
                    'pcid' => $parId,
                    'text' => $text,
                    'time' => $comTime
                );
            }
        }
        if ($this->outputType == 'xml') {
            $maxNode = $xml->createAttribute('maxmark');
            $maxNode->value = $maxMark;
            $comsNode->appendChild($maxNode);
            return $xml;
        }
        else {
            $comsNode['maxmark'] = (double) $maxMark;
            return json_encode($comsNode);
        }
    }
}
