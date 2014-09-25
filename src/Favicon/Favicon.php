<?php

namespace Favicon;

class Favicon
{
    protected $url = '';
    protected $default = '';
    protected $cacheDir;
    protected $cacheTimeout;

    public function __construct($args = array())
    {
        if (isset($args['url'])) {
            $this->url = $args['url'];
        }

        if (isset($args['default'])) {
            $this->default = $args['default'];
        }
    }

    public function cache($args) {
        if (isset($args['dir'])) {
            $this->cacheDir = $args['dir'];
        } else {
            $this->cacheDir = '/tmp';
        }

        if (isset($args['timeout'])) {
            if ($args['timeout']) {
                $this->cacheTimeout = $args['timeout'];
            } else {
                $this->cacheTimeout = 0;
            }
        }
    }

    public static function baseUrl($url)
    {
        $return = '';

        if (!$url = parse_url($url)) {
            return FALSE;
        }

        // Scheme
        $scheme = strtolower($url['scheme']);
        if ($scheme != 'http' && $scheme != 'https') {

            return FALSE;
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
        $return .= $url['host'];

        // Port
        if (isset($url['port'])) {
            $return .= ":{$url['port']}";
        }

        // Path
        $return .= '/';

        return $return;    
    }

    public static function info($url)
    {
        // Discover real status by following redirects. 
        $loop = TRUE;
        while ($loop) {
            $headers = get_headers($url, TRUE);
            list(,$status) = explode(' ', $headers[0]);
            switch ($status) {
                case '301':
                case '302':
                    $url = $headers['Location'];
                    break;
                default:
                    $loop = FALSE;
                    break;
            }
        }

        return array('status' => $status, 'url' => $url);
    }

    public function get($url = '')
    {
        // URLs passed to this method take precedence.
        if (!empty($url)) {
            $this->url = $url;
        }

        // Get the base URL without the path for clearer concatenations.
        $url = rtrim($this->baseUrl($this->url), '/');

        $found = FALSE;

        // Check the cache first.
        if ($this->cacheTimeout) {
            $cache = $this->cacheDir . '/' . md5($url);
            if (file_exists($cache) && is_readable($cache) && (time() - filemtime($cache) < $this->cacheTimeout)) {
                $favicon = file_get_contents($cache);
                $found = TRUE;
            }
        } else {
            $cache = FALSE;
        }

        if (!$found) {
            // Try /favicon.ico first.
            $info = $this->info("{$url}/favicon.ico");
            if ($info['status'] == '200') {
                $favicon = $info['url'];
                $found = TRUE;
            }
        }

        // See if it's specified in a link tag.
        if (!$found) {
            $html = file_get_contents("{$url}/");
            preg_match('!<head.*?>.*</head>!ims', $html, $match);
            $head = $match[0];

            $dom = new DOMDocument();
            // Use error supression, because the HTML might be too malformed.
            if (@$dom->loadHTML($head)) {
                $links = $dom->getElementsByTagName('link');
                // TODO: Improve this to adhere to a determined precedence.
                foreach ($links as $link) {
                    if ($link->hasAttribute('rel') && strtolower($link->getAttribute('rel')) == 'shortcut icon') {
                        $favicon = $link->getAttribute('href');
                        $found = TRUE;
                    } elseif ($link->hasAttribute('rel') && strtolower($link->getAttribute('rel')) == 'icon') {
                        $favicon = $link->getAttribute('href');
                        $found = TRUE;
                    } elseif ($link->hasAttribute('href') && strpos($link->getAttribute('href'), 'favicon') !== FALSE) {
                        $favicon = $link->getAttribute('href');
                        $found = TRUE;
                    }
                }
            }
        }

        // Make sure the favicon is an absolute URL.
        $parsed = parse_url($favicon);
        if (!isset($parsed['scheme'])) {
            $favicon = $url . $parsed['path'];
        }

        // Sometimes people lie, so check the status.
        $info = $this->info($favicon);
        if ($info['status'] != '200') {
            $found = FALSE;
        }

        if ($found) {
            // Check to see if result should be cached.
            if ($cache) {
                if (!file_exists($cache) || (is_writable($cache) && time() - filemtime($cache) > $this->cacheTimeout)) {
                    file_put_contents($cache, $favicon);
                }
            }

            return $favicon;
        } else {
            return $this->default;
        }
    }
}

?>