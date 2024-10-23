<?php

namespace Favicon;

class Favicon
{
    protected static $TYPE_CACHE_URL = 'url';
    protected static $TYPE_CACHE_IMG = 'img';
    protected $url = '';
    protected $cacheDir;
    protected $cacheTimeout;
    protected $dataAccess;

    public function __construct($args = [])
    {
        if (isset($args['url'])) {
            $this->url = $args['url'];
        }

        $this->cacheDir = __DIR__ . '/../../resources/cache';
        $this->cacheTimeout = 604800;
        $this->dataAccess = new DataAccess();
    }

    /**
     * Set cache settings:
     *   - dir: cache directory
     *   - timeout: in seconds
     *
     * @param array $args
     */
    public function cache($args = [])
    {
        if (isset($args['dir'])) {
            $this->cacheDir = $args['dir'];
        }

        if (!empty($args['timeout'])) {
            $this->cacheTimeout = $args['timeout'];
        }
    }

    public static function baseUrl($url, $path = false)
    {
        $return = '';

        if (!$url = parse_url($url)) {
            return false;
        }

        // Scheme
        $scheme = isset($url['scheme']) ? strtolower($url['scheme']) : null;
        if ($scheme != 'http' && $scheme != 'https') {
            return false;
        }
        $return .= "{$scheme}://";

        // Username and password
        if (isset($url['user'])) {
            $return .= $url['user'];
            if (isset($url['pass'])) {
                $return .= ":{$url['pass']}";
            }
            $return .= '@';
        }

        // Hostname
        if (!isset($url['host'])) {
            return false;
        }

        $return .= $url['host'];

        // Port
        if (isset($url['port'])) {
            $return .= ":{$url['port']}";
        }

        // Path
        if ($path && isset($url['path'])) {
            $return .= $url['path'];
        }
        $return .= '/';

        return $return;
    }

    public function info($url)
    {
        if (empty($url) || $url === false) {
            return false;
        }

        $max_loop = 5;

        // Discover real status by following redirects.
        $loop = true;
        while ($loop && $max_loop-- > 0) {
            $headers = $this->dataAccess->retrieveHeader($url);
            if (empty($headers) || !is_array($headers) || !array_key_exists(0, $headers)) {
                return false;
            }
            $exploded = explode(' ', $headers[0]);

            if (!isset($exploded[1])) {
                return false;
            }
            list(,$status) = $exploded;

            switch ($status) {
                case '301':
                case '302':
                    if (isset($headers['location'])) {
                        $url = $headers['location'];
                        if (is_array($url)) {
                            $url = end($url);
                        }
                    } else {
                        return false;
                    }
                    break;
                default:
                    $loop = false;
                    break;
            }
        }

        return ['status' => $status, 'url' => $url];
    }

    public function endRedirect($url)
    {
        $out = $this->info($url);
        return !empty($out['url']) ? $out['url'] : false;
    }

    /**
     * Find remote (or cached) favicon
     *
     * @param string $url  to look for a favicon
     * @param int    $type type of retrieval (FaviconDLType):
     *                       - HOTLINK_URL: returns remote URL
     *                       - DL_FILE_PATH: returns file path of the favicon downloaded locally
     *                       - RAW_IMAGE: returns the favicon image binary string
     *
     * @return string|bool favicon URL, false if nothing was found
     */
    public function get($url = '', $type = FaviconDLType::HOTLINK_URL)
    {
        // URLs passed to this method take precedence.
        if (!empty($url)) {
            $this->url = $url;
        }

        $url = rtrim(self::baseUrl($this->url, true), '/');
        $original = $url;

        if (
            ($favicon = $this->checkCache($original, self::$TYPE_CACHE_URL)) === false
            && ! $favicon = $this->getFavicon($original, false)
        ) {
            $url = rtrim($this->endRedirect(self::baseUrl($this->url, false)), '/');
            if (
                ($favicon = $this->checkCache($url, self::$TYPE_CACHE_URL)) === false
                && ! $favicon = $this->getFavicon($url)
            ) {
                $url = $original;
            }
        }

        $this->saveCache($url, $favicon, self::$TYPE_CACHE_URL);

        switch ($type) {
            case FaviconDLType::DL_FILE_PATH:
                return $this->getImage($url, $favicon, false);
            case FaviconDLType::RAW_IMAGE:
                return $this->getImage($url, $favicon, true);
            case FaviconDLType::HOTLINK_URL:
            default:
                return empty($favicon) ? false : $favicon;
        }
    }

    private function getFavicon($url, $checkDefault = true)
    {
        $favicon = false;

        if (empty($url)) {
            return false;
        }

        // Try /favicon.ico first.
        if ($checkDefault) {
            $info = $this->info("{$url}/favicon.ico");
            if ($info !== false && $info['status'] == '200') {
                $favicon = $info['url'];
            }
        }

        // See if it's specified in a link tag in domain url.
        if (!$favicon) {
            $favicon = trim($this->getInPage($url));
        }
        // Case of protocol-relative URLs
        if (substr($favicon, 0, 2) === '//') {
            if (preg_match('%^(https?:)//%i', $url, $matches)) {
                $favicon = $matches[1] . $favicon;
            } else {
                $favicon = 'https:' . $favicon;
            }
        }

        // Make sure the favicon is an absolute URL.
        if ($favicon && filter_var($favicon, FILTER_VALIDATE_URL) === false) {
            // Make sure that favicons starting with "/" get concatenated with host instead of full URL
            if ($favicon[0] === '/') {
                $favicon = self::baseUrl($url) . ltrim($favicon, '/');
            } else {
                $favicon = rtrim($url, '/') . '/' . ltrim($favicon, '/');
            }
        }

        // Sometimes people lie, so check the status.
        // And sometimes, it's not even an image. Sneaky bastards!
        // If cacheDir isn't writable, that's not our problem
        if (
            $favicon
            && is_writable($this->cacheDir)
            && extension_loaded('fileinfo')
            && !$this->checkImageMType($favicon)
        ) {
            $favicon = false;
        }

        return $favicon;
    }

    /**
     * Find remote favicon and return it as an image
     */
    private function getImage($url, $faviconUrl = '', $image = false)
    {
        if (empty($faviconUrl)) {
            return false;
        }

        $favicon = $this->checkCache($url, self::$TYPE_CACHE_IMG);
        // Favicon not found in the cache
        if ($favicon === false) {
            $favicon = $this->dataAccess->retrieveUrl($faviconUrl);
            // Definitely not found
            if (!$this->checkImageMTypeContent($favicon)) {
                return false;
            } else {
                $this->saveCache($url, $favicon, self::$TYPE_CACHE_IMG);
            }
        }

        if ($image) {
            return $favicon;
        } else {
            return self::$TYPE_CACHE_IMG . md5($url);
        }
    }

    private function getInPage($url)
    {
        $html = $this->dataAccess->retrieveUrl("{$url}/");
        preg_match('!<head.*?>.*</head>!ims', $html, $match);

        if (empty($match) || count($match) == 0) {
            return false;
        }

        $head = $match[0];

        $dom = new \DOMDocument();
        // Use error suppression, because the HTML might be too malformed.
        if (@$dom->loadHTML($head)) {
            $links = $dom->getElementsByTagName('link');
            /** @var \DOMNode $link */
            foreach ($links as $link) {
                if ($link->hasAttribute('rel') && strtolower($link->getAttribute('rel')) == 'shortcut icon') {
                    return $link->getAttribute('href');
                }
            }
            foreach ($links as $link) {
                if ($link->hasAttribute('rel') && strtolower($link->getAttribute('rel')) == 'icon') {
                    return $link->getAttribute('href');
                }
            }
            foreach ($links as $link) {
                if ($link->hasAttribute('href') && strpos($link->getAttribute('href'), 'favicon') !== false) {
                    return $link->getAttribute('href');
                }
            }
        }
        return false;
    }

    private function checkCache($url, $type)
    {
        if ($this->cacheTimeout) {
            $cache = $this->cacheDir . '/' . $type . md5($url);
            if (
                file_exists($cache) && is_readable($cache)
                && ($this->cacheTimeout === -1 || time() - filemtime($cache) < $this->cacheTimeout)
            ) {
                return $this->dataAccess->readCache($cache);
            }
        }
        return false;
    }

    /**
     * Will save data in cacheDir if the directory writable and any previous cache is expired (cacheTimeout)
     * @param $url
     * @param $data
     * @param $type
     * @return string cache file path
     */
    private function saveCache($url, $data, $type)
    {
        // Save cache if necessary
        $cache = $this->cacheDir . '/' . $type . md5($url);
        if (
            $this->cacheTimeout && !file_exists($cache)
            || (is_writable($cache) && $this->cacheTimeout !== -1 && time() - filemtime($cache) > $this->cacheTimeout)
        ) {
            $this->dataAccess->saveCache($cache, $data);
        }
        return $cache;
    }

    private function checkImageMType($url)
    {

        $fileContent = $this->dataAccess->retrieveUrl($url);

        return $this->checkImageMTypeContent($fileContent);
    }

    private function checkImageMTypeContent($content)
    {
        if (empty($content)) {
            return false;
        }

        $isImage = true;
        try {
            $fInfo = finfo_open(FILEINFO_MIME_TYPE);
            $isImage = strpos(finfo_buffer($fInfo, $content), 'image') !== false;
            finfo_close($fInfo);
        } catch (\Exception $e) {
            error_log('Favicon checkImageMTypeContent error: ' . $e->getMessage());
        }

        return $isImage;
    }

    /**
     * @return mixed
     */
    public function getCacheDir()
    {
        return $this->cacheDir;
    }

    /**
     * @param mixed $cacheDir
     */
    public function setCacheDir($cacheDir)
    {
        $this->cacheDir = $cacheDir;
    }

    /**
     * @return mixed
     */
    public function getCacheTimeout()
    {
        return $this->cacheTimeout;
    }

    /**
     * @param mixed $cacheTimeout
     */
    public function setCacheTimeout($cacheTimeout)
    {
        $this->cacheTimeout = $cacheTimeout;
    }

    /**
     * @return string
     */
    public function getUrl()
    {
        return $this->url;
    }

    /**
     * @param string $url
     */
    public function setUrl($url)
    {
        $this->url = $url;
    }

    /**
     * @param DataAccess $dataAccess
     */
    public function setDataAccess($dataAccess)
    {
        $this->dataAccess = $dataAccess;
    }
}
