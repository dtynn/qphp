<?php
/**
 * Created by JetBrains PhpStorm.
 * User: dtynn
 * Date: 1/15/14
 * Time: 5:14 PM
 */
define('VERSION', '0.1');
define('AGENT', 'qphp-' . VERSION);
define('UP_HOST' , 'http://up.qiniu.com');
define('RS_HOST', 'http://rs.qbox.me');
define('RSF_HOST', 'http://rsf.qbox.me');


class QiniuError {
    public $Err;
    public $Reqid;
    public $Details;
    public $Code;

    public function __construct($code, $err, $reqid=null, $details=null) {
        $this->Code = $code;
        $this->Err = $err;
        $this->Reqid = $reqid;
        $this->Details = $details;
    }
}


class QiniuResponse {
    public $Code;
    public $Body;
    public $Reqid;

    public function __construct($code, $reqid, $body) {
        $this->Code = $code;
        $this->Reqid = $reqid;
        $this->Body = $body;
    }

    public function getResponseArray() {
        $res = array();
        $res['Code'] = $this->Code;
        $res['Body'] = $this->Body;
        $res['Reqid'] = $this->Reqid;

        $data = json_decode($this->Body, true);
        if ($data !== null) {
            $res = array_merge($res, $data);
        }
        return $res;
    }
}


class QiniuPutPolicy {
    public $scope;                  //必填
    public $expires;                //默认为3600s
    public $callbackUrl;
    public $callbackBody;
    public $returnUrl;
    public $returnBody;
    public $asyncOps;
    public $endUser;
    public $insertOnly;             //若非0，则任何情况下无法覆盖上传
    public $detectMime;             //若非0，则服务端根据内容自动确定MimeType
    public $fsizeLimit;
    public $saveKey;
    public $persistentOps;
    public $persistentNotifyUrl;

    public function __construct($scope) {
        $this->scope = $scope;
        $this->expires = 3600;
    }
}


class QiniuPutExtra {
    public $params = null;
    public $mimeType = null;
    public $crc32 = 0;
    public $checkCrc = 0;
}


class QiniuBase {
    const UserAgent = AGENT;
    const UpHost = UP_HOST;
    const RsHost = RS_HOST;
    const RsfHost = RSF_HOST;

    private $_accessKey;
    private $_secretKey;

    public function __construct($accessKey, $secretKey) {
        $this->_accessKey = $accessKey;
        $this->_secretKey = $secretKey;
    }

    public function sign($data) {
        return $this->urlsafeEncode(hash_hmac('sha1', $data, $this->_secretKey, true));
    }

    public function signWithKey($data) {
        return $this->_accessKey . ':' . $this->sign($data);
    }

    public function signWithData($data) {
        $encoded = $this->urlsafeEncode($data);
        return $this->signWithKey($encoded) . ':' . $encoded;
    }

    public function apiCall($url, $body, $contentType = 'application/x-www-form-urlencoded', $header=null) {
        if ($contentType === 'application/x-www-form-urlencoded') {
            if (is_array($body)) {
                $body = http_build_query($body);
            }
        }
        if (empty($header)) {
            $header = array('Content-Type'=> $contentType);
        } else {
            $header['Content-Type'] = $contentType;
        }
        return $this->clientDo($url, $header, $body);
    }

    public function clientDo($url, $header, $body, $method='POST') {
        $ch = curl_init();
        $options = array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HEADER => true,
            CURLOPT_NOBODY => false,
            CURLOPT_URL => $url
        );
        $httpHeader = array("User-Agent: " . self::UserAgent);
        if (!empty($header)) {
            foreach ($header as $key => $parsedValue) {
                $httpHeader[] = "$key: $parsedValue";
            }
        }
        $options[CURLOPT_HTTPHEADER] = $httpHeader;
        if (!empty($body)) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);
        $ret = curl_errno($ch);
        if ($ret !== 0) {
            $err = new QiniuError($ret, curl_error($ch));
            curl_close($ch);
            return array(null, $err);
        }
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        #$contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        $responseArray = explode("\r\n\r\n", $response);
        $responseArraySize = sizeof($responseArray);
        $respHeader = $responseArray[$responseArraySize-2];
        $respBody = $responseArray[$responseArraySize-1];
        list($reqid, $xLog) = $this->getReqInfo($respHeader);
        if (intval($statusCode/100) === 2) {
            $resp = new QiniuResponse($statusCode, $reqid, $respBody);
            return array($resp->getResponseArray(), null);
        } else {
            $decodedBody = json_decode($respBody, true);
            $err = new QiniuError($statusCode, $decodedBody['error'], $reqid, $xLog);
            return array(null, $err);
        }
    }

    public function urlsafeEncode($text) {
        $find = array('+', '/');
        $replace = array('-', '_');
        return str_replace($find, $replace, base64_encode($text));
    }

    public function urlsafeDecode($text) {
        $find = array('-', '_');
        $replace = array('+', '/');
        return base64_decode(str_replace($find, $replace, $text));
    }

    public function escapeQuotes($text) {
        $find = array("\\", "\"");
        $replace = array("\\\\", "\\\"");
        return str_replace($find, $replace, $text);
    }

    public function getReqInfo($header) {
        $headers = explode("\r\n", $header);
        $reqid = null;
        $xLog = null;
        foreach($headers as $header) {
            $header = trim($header);
            if(strpos($header, 'X-Reqid') !== false) {
                list($k, $v) = explode(':', $header);
                $reqid = trim($v);
            } elseif(strpos($header, 'X-Log') !== false) {
                list($k, $v) = explode(':', $header);
                $xLog = trim($v);
            }
        }
        return array($reqid, $xLog);
    }

    public function makeMultipartForm($fields, $file) {
        $data = array();
        $boundary = md5(microtime());

        foreach ($fields as $name => $val) {
            array_push($data, '--' . $boundary);
            array_push($data, "Content-Disposition: form-data; name=\"$name\"");
            array_push($data, '');
            array_push($data, $val);
        }
        if (!empty($file)) {
            list($fileName, $fileBody, $mimeType) = $file;
            $fileName = $fileName === null ? '?': $this->escapeQuotes($fileName);
            $mimeType = empty($mimeType) ? 'application/octet-stream': $mimeType;
            array_push($data, '--' . $boundary);
            array_push($data, "Content-Disposition: form-data; name=\"file\"; filename=\"$fileName\"");
            array_push($data, "Content-Type: $mimeType");
            array_push($data, '');
            array_push($data, $fileBody);
        }
        array_push($data, '--' . $boundary . '--');
        array_push($data, '');

        $body = implode("\r\n", $data);
        $contentType = 'multipart/form-data; boundary=' . $boundary;
        return array($contentType, $body);
    }
}


class QiniuBucketController extends QiniuBase {
    public  $_bucket;
    public  $_putPolicy;
    public  $_putExtra;
    public  $_getPolicy;

    public function __construct($accessKey, $secretKey, $bucket) {
        $this->_bucket = $bucket;
        parent :: __construct($accessKey, $secretKey);
    }

    public function setPutPolicy($putPolicy) {
        $this->_putPolicy = $putPolicy;
    }

    public function setPutExtra($putExtra) {
        $this->_putExtra = $putExtra;
    }

    public function setGetPolicy($getPolicy) {
        $this->_getPolicy = $getPolicy;
    }

    public function makeUploadToken() {
        if (empty($this->_putPolicy)) {
            $this->_putPolicy = new QiniuPutPolicy($this->_bucket);
        }
        $policy = $this->_putPolicy;
        $deadline = time() + $policy->expires;

        $policyArray = array('scope' => $policy->scope, 'deadline' => $deadline);

        if (!empty($policy->callbackUrl)) {
            $policyArray['callbackUrl'] = $policy->callbackUrl;
        }
        if (!empty($policy->callbackBody)) {
            $policyArray['callbackBody'] = $policy->callbackBody;
        }
        if (!empty($policy->returnUrl)) {
            $policyArray['returnUrl'] = $policy->returnUrl;
        }
        if (!empty($policy->returnBody)) {
            $policyArray['returnBody'] = $policy->returnBody;
        }
        if (!empty($policy->asyncOps)) {
            $policyArray['asyncOps'] = $policy->asyncOps;
        }
        if (!empty($policy->endUser)) {
            $policyArray['endUser'] = $policy->endUser;
        }
        if (!empty($policy->insertOnly)) {
            $policyArray['exclusive'] = $policy->insertOnly;
        }
        if (!empty($policy->detectMime)) {
            $policyArray['detectMime'] = $policy->detectMime;
        }
        if (!empty($policy->fsizeLimit)) {
            $policyArray['fsizeLimit'] = $policy->fsizeLimit;
        }
        if (!empty($policy->saveKey)) {
            $policyArray['saveKey'] = $policy->saveKey;
        }
        if (!empty($policy->persistentOps)) {
            $policyArray['persistentOps'] = $policy->persistentOps;
        }
        if (!empty($policy->persistentNotifyUrl)) {
            $policyArray['persistentNotifyUrl'] = $policy->persistentNotifyUrl;
        }

        $b = json_encode($policyArray);
        return $this->signWithData($b);
    }
}


class QiniuBucketSimpleUploader extends QiniuBucketController {
    public  function _put($upToken, $key, $data, $putExtra=null, $filename=null) {
        if ($upToken === null) {
            $upToken = $this->makeUploadToken();
        }

        if ($putExtra === null) {
            $putExtra = new QiniuPutExtra();
        }

        $fields = array('token' => $upToken);
        if ($key !== null) {
            $fields['key'] = $key;
        }
        if ($putExtra->checkCrc) {
            $fields['crc32'] = $putExtra->crc32;
        }
        $file = array($filename, $data, $putExtra->mimeType);
        list($contentType, $body) = $this->makeMultipartForm($fields, $file);
        return $this->apiCall(self::UpHost, $body, $contentType);
    }

    public function put($key, $data, $filename=null) {
        return $this->_put($this->makeUploadToken(), $key, $data, $this->_putExtra, $filename);
    }

    public function putFile($key, $localFile) {
        $data = file_get_contents($localFile);
        $filename = basename($localFile);
        return $this->put($key, $data, $filename);
    }
}


class QiniuBucketResumableUploader extends QiniuBucketController {

}
