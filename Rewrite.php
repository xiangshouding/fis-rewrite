<?php

class Rewrite{

    private static $root = null;
    private static $rewriteRules = array();
    private static $MIME = array(
        'bmp' => 'image/bmp',
        'css' => 'text/css',
        'doc' => 'application/msword',
        'dtd' => 'text/xml',
        'gif' => 'image/gif',
        'hta' => 'application/hta',
        'htc' => 'text/x-component',
        'htm' => 'text/html',
        'html' => 'text/html',
        'xhtml' => 'text/html',
        'ico' => 'image/x-icon',
        'jpe' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'jpg' => 'image/jpeg',
        'js' => 'text/javascript',
        'json' => 'application/json',
        'mocha' => 'text/javascript',
        'mp3' => 'audio/mp3',
        'mp4' => 'video/mpeg4',
        'mpeg' => 'video/mpg',
        'mpg' => 'video/mpg',
        'manifest' => 'text/cache-manifest',
        'pdf' => 'application/pdf',
        'png' => 'image/png',
        'ppt' => 'application/vnd.ms-powerpoint',
        'rmvb' => 'application/vnd.rn-realmedia-vbr',
        'rm' => 'application/vnd.rn-realmedia',
        'rtf' => 'application/msword',
        'svg' => 'image/svg+xml',
        'swf' => 'application/x-shockwave-flash',
        'tif' => 'image/tiff',
        'tiff' => 'image/tiff',
        'txt' => 'text/plain',
        'vml' => 'text/xml',
        'vxml' => 'text/xml',
        'wav' => 'audio/wav',
        'wma' => 'audio/x-ms-wma',
        'wmv' => 'video/x-ms-wmv',
        'woff' => 'image/woff',
        'xml' => 'text/xml',
        'xls' => 'application/vnd.ms-excel',
        'xq' => 'text/xml',
        'xql' => 'text/xml',
        'xquery' => 'text/xml',
        'xsd' => 'text/xml',
        'xsl' => 'text/xml',
        'xslt' => 'text/xml'
    );
    /**
     * 添加用户自定义的url处理规则
     * @param $reg 正则，需要加定界符
     * @param callable $callback  匹配$reg后，用户处理callback函数，callback参数为匹配的$matches数组
     */
    public static function addRewriteRule($reg, callable $callback){
        self::$rewriteRules[] = array(
            'rule' => $reg,
            'callback' => $callback
        );
    }

    public static function setRoot($root){
        self::$root = $root;
    }

    public static function getRoot(){
        return (self::$root) ? (self::$root) : (dirname(dirname(__FILE__)) . DIRECTORY_SEPARATOR);
    }

    private static function padString($str, array $replaceArr) {
        foreach ($replaceArr as $k => $v) {
            $replaceArr['$'.$k] = $v;
            unset($replaceArr[$k]);
        }
        return str_replace(array_keys($replaceArr), array_values($replaceArr), $str);
    }

    /**
     *  $url : 需要匹配的url
     *  $matches : 正则匹配的引用
     *  $statusCode : 匹配的状态码
     *      statuCode ： 200表示命中并执行
     *      statuCode ： 304表示在exts中，转发给用户自己处理
     *      statuCode :  404表示没有找到rewrite的文件
     *  $exts : 匹配exts里面的格式时会交给用户处理，返回状态码304
     *  返回值 ：
     *    true ： 表示命中正则
     *    false ： 表示没有命中
     */
    public static function match($url, &$matches = null, &$statusCode = null, $exts = null){
        $root = self::getRoot();
        //命中用户自添加的规则，执行用户自定义的callback处理url
        if(self::$rewriteRules) {
            foreach(self::$rewriteRules as $rule){
                if(preg_match($rule['rule'], $url, $matches)){
                    return call_user_func_array($rule['callback'], array($matches, $root));
                }
            }
        }
        //命中server.conf文件中定义的rewrite，redirect规则
        $configFile = $root . 'server.conf';
        if(file_exists($configFile) && ($handle = fopen($configFile, 'r'))){
            while (($buffer = fgets($handle)) !== false) {
                $ruleTokens = preg_split('/\s+/', $buffer);
                if($ruleTokens[0] == 'rewrite' || $ruleTokens[0] == 'redirect'){
                    $rule = array(
                        'rule' => $ruleTokens[1],
                        'rewrite' => $ruleTokens[2],
                        'type' => $ruleTokens[0]
                    );
                    $ret = self::_match($rule, $root, $url, $matches = null, $statusCode = null, $exts = null);
                    if($ret) {
                        fclose($handle);
                        return $ret;
                    }
                }
            }
            if (!feof($handle)) {
                echo "Error: unexpected fgets() fail\n";
            }
            fclose($handle);
        }
        return false;
    }

    private static function _match($rule, $root, $url, &$matches = null, &$statusCode = null, $exts = null){
        $statusCode = false;
        if(preg_match('/' . $rule['rule'] . '/', $url, $matches)){
            $m = $matches;
            unset($m[0]);
            $rewrite = self::padString($rule['rewrite'], $m);
            if($rule['type'] == 'rewrite'){
                if(file_exists($file = $root . $rewrite)){
                    $pos = strrpos($rewrite, '.');
                    if(false !== $pos){
                        $ext = substr($rewrite, $pos + 1);
                        if(in_array($ext, $exts)){
                            $statusCode = 304;
                        }else if($ext == 'php'){
                            $statusCode = 200;
                            self::includePhp($root . $rewrite, $matches);
                        }else if(self::$MIME[$ext]){
                            $content_type = 'Content-Type: ' . self::$MIME[$ext];
                            header($content_type);
                            $statusCode = 200;
                            echo file_get_contents($root . $rewrite);
                        }else{
                            $statusCode = 200;
                            $content_type = 'Content-Type: application/x-' . $ext;
                            header($content_type);
                            echo file_get_contents($file);
                        }
                    }
                } else {
                    $statusCode = 404;
                }
            } else if($rule['type'] == 'redirect'){
                $statusCode = 302;
                header('Location: ' . $rewrite);
                exit();
            }
            return $statusCode;
        }
        return false;
    }

    private static function includePhp($file, $matches){
        try{
            $fis_matches = $matches;
            include($file);
        }catch(Exception $e){
            throw new Exception("include php file " . $file . "failed");
        }
    }
}
