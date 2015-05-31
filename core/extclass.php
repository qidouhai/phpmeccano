<?php

/*
 *     phpMeccano v0.0.1. Web-framework written with php programming language. Core module [extclass.php].
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
 */

namespace core;

interface intServiceMethods {
    public function errId();
    public function errExp();
    public function applyPolicy($flag);
}

class ServiceMethods {
    protected $errid = 0; // error's id
    protected $errexp = ''; // error's explanation
    protected $usePolicy = TRUE; // flag of the policy application
    
    protected function setError($id, $exp) {
        $this->errid = $id;
        $this->errexp = $exp;
    }
    
    protected function zeroizeError() {
        $this->errid = 0;        $this->errexp = '';
    }
    
    public function errId() {
        return $this->errid;
    }
    
    public function errExp() {
        return $this->errexp;
    }
    
    public function applyPolicy($flag) {
        if ($flag) {
            $this->usePolicy = TRUE;
        }
        else {
            $this->usePolicy = FALSE;
        }
    }
    
}
