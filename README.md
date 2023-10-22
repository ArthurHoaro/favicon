A library to determine a site's favicon
=======================================

This library is based on [Chris Shiflett work](https://github.com/shiflett/favicon). 

Here are the changes you can see in this version:

  * Cover more use case to find favicons
  * Composer support
  * Various technical changes and improvements
  * Unit tests

Requirements
------------

- [PHP 8.0+](http://php.net/) (you can use v1.x for PHP 5.6+)
- [php-xml](http://php.net/manual/fr/refs.xml.php) extension: parse HTML content
- [php-fileinfo](http://php.net/manual/fr/book.fileinfo.php) extension: check image type

Composer
-----

Use [Composer](https://getcomposer.org) by adding the following lines in your `composer.json`:

    "require": {
        "arthurhoaro/favicon": "~2.0"
    }

Basic Usage
-----

```php
require_once('vendor/autoload.php');

$favicon = new \Favicon\Favicon();

echo $favicon->get('http://hoa.ro');
// Displays: http://hoa.ro/themes/hoaro/img/favicon.png
var_dump($favicon->get('http://nofavicon.tld', FaviconDLType::HOTLINK_URL));
// Returns false
```

You can avoid hotlinking by downloading the favicons:

```php
$favicon = new \Favicon\Favicon();

// return the generated filename inside the cache folder
$favicon->get('http://hoa.ro', FaviconDLType::DL_FILE_PATH);
// return false
$favicon->get('http://nofavicon.tld');
```
    
Or directly get the raw image as a binary string:

```php
$favicon = new \Favicon\Favicon();

// return the binary string of the downloaded favicon
$favicon->get('http://hoa.ro', FaviconDLType::RAW_IMAGE);
// return false
$favicon->get('http://nofavicon.tld');
```

> Note: `DL_FILE_PATH` and `RAW_IMAGE` require the cache to be enabled.

Configure
-----

You can setup cache settings:

```php
$favicon = new Favicon();
$settings = array(
    // Cache directory
    'dir' => '/tmp/',
    // Cache timeout in seconds
    'timeout' => 86400,
    // Default image when no favicon is found
    'defaultico' => 'img/fav.ico'
);
$favicon->cache($settings);
```
