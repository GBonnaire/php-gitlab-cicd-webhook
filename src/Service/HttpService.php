<?php

namespace Gbonnaire\PhpGitlabCicdWebhook\Service;

use Exception;

class HttpService
{
    const HTTP_RESPONSE_OK = 200;
    const HTTP_RESPONSE_BAD_REQUEST = 400;
    const HTTP_RESPONSE_UNAUTHORIZED = 401;
    const HTTP_RESPONSE_FORBIDDEN = 403;
    const HTTP_RESPONSE_NOT_FOUND = 404;
    const HTTP_RESPONSE_METHOD_NOT_ALLOWED = 405;
    const HTTP_RESPONSE_INTERNAL_SERVER_ERROR = 500;
    const HTTP_RESPONSE_NOT_IMPLEMENTED = 501;
    const HTTP_RESPONSE_SERVICE_UNAVAILABLE = 503;
    /**
     * @return string
     */
    public function getUserAgent():string
    {
        $browser = (isset($_SERVER['HTTP_USER_AGENT']) && !empty($_SERVER['HTTP_USER_AGENT'])) ? $_SERVER['HTTP_USER_AGENT'] : (isset($_ENV['HTTP_USER_AGENT']) ? $_ENV['HTTP_USER_AGENT'] : "CLIENT");
        $browser = str_replace(["||", "'", "`", '"'], "", $browser); // Sanitize
        if(strlen($browser) > 250) {
            $browser = substr($browser, 0, 250);
        }
        return $browser;
    }

    /**
     * @return string
     */
    public function getUserIp(): string
    {
        return (isset($_SERVER['REMOTE_ADDR']) && !empty($_SERVER['REMOTE_ADDR'])) ? $_SERVER['REMOTE_ADDR'] : getenv('REMOTE_ADDR');
    }

    /**
     * @return string
     */
    public function getCurrentDomain()
    {
        return (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
    }


    /**
     * Response generique
     * @param string $content
     * @param string $type
     * @return string
     */
    public function response(string $content, string $type="text/plain", int $code=self::HTTP_RESPONSE_OK) {
        http_response_code($code);
        header('Content-Type: ' . $type);
        print($content);
    }

    /**
     * @param string $pathTemplate
     * @param array $var
     * @return void
     * @throws Exception
     */
    public function responseTemplateHTML(string $pathTemplate, array $var = [], int $code=self::HTTP_RESPONSE_OK) {
        if(strtolower(substr($pathTemplate, -6)) !== ".phtml") {
            $pathTemplate .= ".phtml";
        }
        if(file_exists(ROOT_FOLDER . "template/" .  $pathTemplate)) {
            ob_start();
            foreach ($var as $key => $value) {
                ${$key} = $value;
            }
            include ROOT_FOLDER. "template/" .  $pathTemplate;
            $this->response(ob_get_clean(), "text/html", $code);
        } else {
            error_log(ROOT_FOLDER. "template/" .  $pathTemplate);
            throw new Exception("No file template found");
        }
    }

    /**
     * Response JSON
     * @param array $content
     * @return string
     */
    public function responseJSON(array $content, int $code=self::HTTP_RESPONSE_OK) {
        return $this->response(json_encode($content, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE, $code), "application/json");
    }

    /**
     * Response CSS
     * @param string $content
     * @return string
     */
    public function responseCSS(string $content, int $code=self::HTTP_RESPONSE_OK) {
        return $this->response($content, "text/css", $code);
    }

    /**
     * Response JS
     * @param string $content
     * @return string
     */
    public function responseJS(string $content, int $code=self::HTTP_RESPONSE_OK) {
        return $this->response($content, "application/javascript", $code);
    }

    /**
     * Response Download
     * @param string $filename
     * @param string $type
     * @param string $size
     * @param string $blob
     * @return string
     */
    public function responseDownload(string $filename, string $type, string $size, string $blob, int $code=self::HTTP_RESPONSE_OK) {
        http_response_code($code);
        header('Content-Length: ' . $size);
        header("Content-Type: " . $type);
        header('Content-Disposition: attachment; filename=' . $filename);
        ob_clean();
        flush();
        return $blob;
    }

    /**
     * @param string $url
     * @return void
     */
    public function responseRedirect(string $url) {
        header("Location: " . $url);
        exit();
    }

    private function noCacheHeader() {
        header('Cache-Control: no-store, max-age=0, no-cache, must-revalidate'); // HTTP 1.1.
        header('Cache-Control: post-check=0, pre-check=0', FALSE);
        header('Pragma: no-cache'); // HTTP 1.0.
        header("Expires: 0"); // Proxies.
    }
}