<?php
/**
 * Copyright 2010 Yuri Timofeev tim4dev@gmail.com
 *
 * This is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This software is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this software.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @desc       Gets information about bugs (with depends on) via XML-RPC Bugzilla::WebService
 * @author     Yuri Timofeev <tim4dev@gmail.com>
 * @package    bugzillaxmlrpc
 * @license    http://www.gnu.org/licenses/gpl-3.0.html GNU Public License
 * @syntax     bugz#12345
 *
 */

// must be run within DokuWiki
if(!defined('DOKU_INC')) die();
if(!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/');
require_once(DOKU_PLUGIN.'syntax.php');

class syntax_plugin_bugzillaxmlrpc extends DokuWiki_Syntax_Plugin {

    protected $curl_opt = array(
        CURLOPT_POST    => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER  => array( 'Content-Type: text/xml', 'charset=utf-8' )
    );

    protected $xml_data = array(
        'remember' => 1
    );

    protected $cookie_file;


    function getInfo(){
        return array(
            'author' => 'Yuri Timofeev',
            'email'  => 'tim4dev@gmail.com',
            'date'   => '2010-09-09',
            'name'   => 'bugzilla xml-rpc plugin',
            'desc'   => 'Gets information about bugs via XML-RPC Bugzilla::WebService',
            'url'    => 'http://www.dokuwiki.org/plugin:bugzillaxmlrpc',
        );
    }

    function getType()  { return 'substition'; }
    function getPType() { return 'normal'; }
    function getSort()  { return 777; }

    function connectTo($mode) { 
        $this->Lexer->addSpecialPattern('bugz#[0-9]+', $mode, 'plugin_bugzillaxmlrpc'); 
    }


    function myLogin() {
        // Login and get cookie
        // http://www.bugzilla.org/docs/3.2/en/html/api/Bugzilla/WebService/User.html
        $this->xml_data['login'] = $this->getConf('login');
        $this->xml_data['password'] = $this->getConf('password');
        $ch = curl_init();
        curl_setopt_array($ch, $this->curl_opt);
        $request = xmlrpc_encode_request('User.login', $this->xml_data);
        curl_setopt($ch, CURLOPT_URL, $this->getConf('xmlrpc.cgi'));
        curl_setopt($ch, CURLOPT_POSTFIELDS, $request); 
        curl_setopt($ch, CURLOPT_COOKIEJAR, $this->cookie_file );
        $server_output = curl_exec($ch);
        curl_close($ch);
        $response = xmlrpc_decode($server_output);
        if ( empty($response) ) {
            @unlink($this->cookie_file);
            return array(1, 'NULL', 'NULL');
        }
        return array(0, $response['faultString'], $response['faultCode'], $response['id']);
    }


    /**
     * Return:
     * bug_id, summary, dependson, product, creation_ts, bug_status, deadline
     */
    function myGetBugInfo($id) {
        // Login and get bug info
        // http://www.bugzilla.org/docs/3.2/en/html/api/Bugzilla/WebService/Bug.html
        $this->xml_data['login'] = $this->getConf('login');
        $this->xml_data['password'] = $this->getConf('password');
        $ch = curl_init();
        curl_setopt_array($ch, $this->curl_opt);
        $this->xml_data['ids'] = array($id);
        $request = xmlrpc_encode_request("Bug.get", $this->xml_data);
        curl_setopt($ch, CURLOPT_URL, $this->getConf('xmlrpc.cgi'));
        curl_setopt($ch, CURLOPT_POSTFIELDS, $request); 
        curl_setopt($ch, CURLOPT_COOKIEFILE, $this->cookie_file );
        $server_output = curl_exec($ch);
        curl_close($ch);

        $response = xmlrpc_decode($server_output);
        if (xmlrpc_is_fault($response))  
            return array(1, $response[faultString], $response[faultCode], $id);
        $v = $response['bugs'][0];
        // Login and get Product info
        list($error, $faultString, $faultCode, $product) = $this->myGetProductInfo($v['internals']['product_id']);
        if ($error)  
            return array(1, $response[faultString], $response[faultCode], $id);
        // return all data
        return array(0, $response[faultString], $response[faultCode], 
            $id, 
            $v['summary'], 
            $v['dependson'],
            $product, 
            $v['internals']['creation_ts'],
            $v['internals']['bug_status'],
            $v['internals']['deadline']
            );
    }


    function myGetProductInfo($id) {
        // Login and get Product info
        $this->xml_data['login'] = $this->getConf('login');
        $this->xml_data['password'] = $this->getConf('password');
        $ch = curl_init();
        curl_setopt_array($ch, $this->curl_opt);
        $this->xml_data['ids'] = array($id);
        $request = xmlrpc_encode_request("Product.get", $this->xml_data);
        curl_setopt($ch, CURLOPT_URL, $this->getConf('xmlrpc.cgi'));
        curl_setopt($ch, CURLOPT_POSTFIELDS, $request); 
        curl_setopt($ch, CURLOPT_COOKIEFILE, $this->cookie_file );
        $server_output = curl_exec($ch);
        curl_close($ch);

        $response = xmlrpc_decode($server_output);
        if (xmlrpc_is_fault($response))  
            return array(1, $response[faultString], $response[faultCode]);
        return array(0, $response[faultString], $response[faultCode], $response['products'][0]['name']);
    }

    function myLogout() {
        unlink($this->cookie_file);
    }


    function handle($match, $state, $pos, Doku_Handler $handler) {
        preg_match('/^bugz#([0-9]+)/', $match, $submatch);
        $ids = $submatch[1];
        $data_bugs = array(); // two-dimensional array
        if (empty($ids)) {
            $data_bugs[] = array('error' => 1, 'faultString' => 'Empty Id', 'faultCode' => 999, 'id' => 0);
            $this->myLogout;
            return array($data_bugs);
        }
        // Login and get cookie
        $this->cookie_file = tempnam('', 'bugzillaxmlrpc');
        list($error, $faultString, $faultCode, $id) = $this->myLogin();
        if ($error) {
            $data_bugs[] = array('error' => 1, 'faultString' => $faultString, 'faultCode' => $faultCode, 'id' => $id);
            $this->myLogout;
            return array($data_bugs);
        }
        // Login and get bug info
        // return: bug_id, summary, dependson, product, creation_ts, bug_status, deadline
        list($error, $faultString, $faultCode, $id, $summary, $dependson, $product, $creation_ts, $bug_status, $deadline) = $this->myGetBugInfo($ids);
        if ($error) {
            $data_bugs[] = array('error' => 1, 'faultString' => $faultString, 'faultCode' => $faultCode, 'id' => $id);
            $this->myLogout;
            return array($data_bugs);
        }
        $data_bugs[0] = array('error' => 0, 'faultString' => $faultString, 'faultCode' => $faultCode,
            'id' => $id, 'summary' => $summary, 'dependson' => $dependson, 'product' => $product,
            'creation_ts' => $creation_ts, 'bug_status' => $bug_status, 'deadline' => $deadline);
        // Get info about dependencies bugs
        foreach ($dependson as $dep) {
            list($error, $faultString, $faultCode, $id, $summary, $dependson, $product, $creation_ts, $bug_status, $deadline) = $this->myGetBugInfo($dep);
            $data_bugs[] = array('error' => 0, 'faultString' => $faultString, 'faultCode' => $faultCode,
                'id' => $id, 'summary' => $summary, 'dependson' => $dependson, 'product' => $product,
                'creation_ts' => $creation_ts, 'bug_status' => $bug_status, 'deadline' => $deadline);
        }
        // return all data
        $this->myLogout;
        return array($data_bugs);
    }


    function render($mode, Doku_Renderer $renderer, $data) {
        if($mode == 'xhtml'){
            $data0 = $data[0];
            $html_bug_all = '<ul>' . "\n";
            $i = 1;
            foreach ($data0 as $v) {
                $html_bug = '';
                if ( ($i == 2) )    
                    $html_bug_all .= '<ul>';
                $id = $v['id'];
                if ($v['error']) {
                    $html_bug .= '<div class="li"><a href="' .
                        $this->getConf('url').$id. '" target="_blank" title="'.
                        $this->getLang('title').$id. '" class="interwiki iw_bug">'.
                        $this->getLang('bug').' #' .$id. '</a> <i>'.
                        $this->getLang('error_prefix').' : ' . $v['faultCode'] . ' - ' . $v['faultString'] .
                        '</i></div>';
                } else {
                    if ( ($v['bug_status'] == 'CLOSED') || ($v['bug_status'] == 'RESOLVED') ) {
                        $s1 = '<s>';
                        $s2 = '</s>';
                    } else {
                        $s1 = '';
                        $s2 = '';
                    }

                    $html_bug .= '<div class="li"><a href="' .
                        $this->getConf('url').$id. '" target="_blank" title="'.
                        $this->getLang('title').$id. '" class="interwiki iw_bug">'.
                        $this->getLang('bug').' #' .$id. '</a>'.$s1.'<i> '. 
                        $v['product']. '</i> -> <b>'.$v['summary']. '</b>'.
                        ' ('. $this->getLang('opened').': '. $v['creation_ts'] .', '. 
                        $this->getLang('bug_status').': '. $v['bug_status'] .', '.
                        $this->getLang('deadline').': '. $v['deadline'].
                        ') '.$s2.
                        '</div>';
                } 
                $html_bug_all .= '<li>'.$html_bug.'</li>' . "\n";
                $i++;
            } // foreach
            if ( $i > 1 )
                $html_bug_all .= '</ul>';
            $renderer->doc .= $html_bug_all . '</ul>';
            return true;
        }
        return false;
    }


}
