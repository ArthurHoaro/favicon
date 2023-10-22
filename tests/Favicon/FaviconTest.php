<?php
namespace Favicon;

use PHPUnit\Framework\TestCase;

class FaviconTest extends TestCase
{
    const DEFAULT_FAV_CHECK = 'favicon.ico';
    const TEST_LOGO_NAME = 'default.ico';
    private $RESOURCE_FAV_ICO;
    private $CACHE_TEST_DIR;
    const SANDBOX = 'tests/sandbox';
    const RESOURCES = 'tests/resources';

    public function setUp(): void
    {
        directory_create(self::SANDBOX, 0775);
        $this->RESOURCE_FAV_ICO = self::RESOURCES . '/' . self::TEST_LOGO_NAME;
        $this->CACHE_TEST_DIR = self::SANDBOX;
    }

    public function tearDown(): void
    {
        directory_delete(self::SANDBOX);
    }

    /**
    * @covers Favicon::__construct
    * @uses Favicon
    */
    public function testUrlIsDefinedByConstructor()
    {
        $url = 'http://foo.bar';
        $args = [ 'url' => $url ];
        $fav = new Favicon($args);
        $this->assertEquals($url, $fav->getUrl());
    }

    /**
    * @covers Favicon::__construct
    * @covers Favicon::cache
    * @uses Favicon
    */
    public function testInitEmptyCache()
    {
        $fav = new Favicon();
        $fav->cache();

        $this->assertTrue(is_writable($fav->getCacheDir()));
        $this->assertEquals(604800, $fav->getCacheTimeout());
    }

    /**
    * @covers Favicon::__construct
    * @covers Favicon::cache
    * @uses Favicon
    */
    public function testInitNotWritableCache()
    {
        $dir = '/f0o/b@r';

        $fav = new Favicon();
        $params = [
            'dir' => $dir,
            ];
        $fav->cache($params);

        $this->assertEquals($dir, $fav->getCacheDir());
        $this->assertFalse(is_writable($fav->getCacheDir()));
        $this->assertEquals(604800, $fav->getCacheTimeout());
    }

    /**
    * @covers Favicon::__construct
    * @covers Favicon::cache
    * @uses Favicon
    */
    public function testInitWritableCacheAndTimeout()
    {
        $timeout = 1000;

        $fav = new Favicon();
        $params = [
            'dir' => $this->CACHE_TEST_DIR,
            'timeout' => $timeout,
            ];
        $fav->cache($params);

        $this->assertEquals($this->CACHE_TEST_DIR, $fav->getCacheDir());
        $this->assertTrue(is_writable($fav->getCacheDir()));
        $this->assertEquals($timeout, $fav->getCacheTimeout());
    }

    /**
    * @covers Favicon::baseUrl
    * @uses Favicon
    */
    public function testBaseFalseUrl()
    {
        $fav = new Favicon();

        $notAnUrl = 'fgkljkdf';
        $notPrefixedUrl = 'domain.tld';
        $noHostUrl = 'http://';
        $invalidPrefixUrl = 'ftp://domain.tld';
        $emptyUrl = '';

        $this->assertEquals(false, $fav->baseUrl($notAnUrl));
        $this->assertEquals(false, $fav->baseUrl($notPrefixedUrl));
        $this->assertEquals(false, $fav->baseUrl($noHostUrl));
        $this->assertEquals(false, $fav->baseUrl($invalidPrefixUrl));
        $this->assertEquals(false, $fav->baseUrl($emptyUrl));
    }

    /**
    * @covers Favicon::baseUrl
    * @uses Favicon
    */
    public function testBaseUrlValid()
    {
        $fav = new Favicon();

        $simpleUrl = 'http://domain.tld';
        $simpleHttpsUrl = 'https://domain.tld';
        $simpleUrlWithTraillingSlash = 'http://domain.tld/';
        $simpleWithPort = 'http://domain.tld:8080';
        $userWithoutPasswordUrl = 'http://user@domain.tld';
        $userPasswordUrl = 'http://user:password@domain.tld';
        $urlWithUnusedInfo = 'http://domain.tld/index.php?foo=bar&bar=foo#foobar';
        $urlWithPath = 'http://domain.tld/my/super/path';

        $this->assertEquals(self::slash($simpleUrl), $fav->baseUrl($simpleUrl));
        $this->assertEquals(self::slash($simpleHttpsUrl), $fav->baseUrl($simpleHttpsUrl));
        $this->assertEquals(self::slash($simpleUrlWithTraillingSlash), $fav->baseUrl($simpleUrlWithTraillingSlash));
        $this->assertEquals(self::slash($simpleWithPort), $fav->baseUrl($simpleWithPort));
        $this->assertEquals(self::slash($userWithoutPasswordUrl), $fav->baseUrl($userWithoutPasswordUrl));
        $this->assertEquals(self::slash($userPasswordUrl), $fav->baseUrl($userPasswordUrl));
        $this->assertEquals(self::slash($simpleUrl), $fav->baseUrl($urlWithUnusedInfo));
        $this->assertEquals(self::slash($simpleUrl), $fav->baseUrl($urlWithPath, false));
        $this->assertEquals(self::slash($urlWithPath), $fav->baseUrl($urlWithPath, true));
    }

    /**
    * @covers Favicon::info
    * @uses Favicon
    */
    public function testBlankInfo()
    {
        $fav = new Favicon();
        $this->assertFalse($fav->info(''));
    }

    /**
    * @covers Favicon::info
    * @uses Favicon
    */
    public function testInfoOk()
    {
        $fav = new Favicon();
        $dataAccess = $this->createMock('Favicon\DataAccess');
        $header = [
            0 => 'HTTP/1.1 200 OK',
        ];
        $dataAccess->expects($this->once())->method('retrieveHeader')->will($this->returnValue($header));
        $fav->setDataAccess($dataAccess);

        $url = 'http://domain.tld';

        $res = $fav->info($url);
        $this->assertEquals($url, $res['url']);
        $this->assertEquals('200', $res['status']);
    }

    /**
    * @covers Favicon::info
    * @uses Favicon
    */
    public function testInfoRedirect()
    {
        $dataAccess = $this->createMock('Favicon\DataAccess');
        $fav = new Favicon();
        $fav->setDataAccess($dataAccess);

        // Data
        $urlRedirect = 'http://redirected.domain.tld';
        $url = 'http://domain.tld';
        $headerOk = [0 => 'HTTP/1.1 200 OK'];
        $headerRedirect = [
            0 => 'HTTP/1.0 302 Found',
            'location' => $urlRedirect,
        ];

        // Simple redirect
        $dataAccess->expects($this->any())->method('retrieveHeader')->willReturnOnConsecutiveCalls(
            $headerRedirect,
            $headerOk,
        );
        $res = $fav->info($url);
        $this->assertEquals($urlRedirect, $res['url']);
        $this->assertEquals('200', $res['status']);
    }
    
    /**
    * @covers Favicon::info
    * @uses Favicon
    */
    public function testInfoRedirectAsArray()
    {
        $dataAccess = $this->createMock('Favicon\DataAccess');
        $fav = new Favicon();
        $fav->setDataAccess($dataAccess);

        // Data
        $urlRedirect = 'http://redirected.domain.tld';
        $urlRedirect2 = 'http://redirected.domain.tld2';
        $url = 'http://domain.tld';
        $headerOk = [0 => 'HTTP/1.1 200 OK'];
        $headerRedirect = [
            0 => 'HTTP/1.0 302 Found',
            'location' => $urlRedirect,
        ];
        $headerDoubleRedirect = array_merge($headerRedirect, ['location' => [$urlRedirect, $urlRedirect2]]);

        // Redirect array
        $dataAccess->expects($this->any())->method('retrieveHeader')->willReturnOnConsecutiveCalls(
            $headerDoubleRedirect,
            $headerOk,
        );
        $res = $fav->info($url);
        $this->assertEquals($urlRedirect2, $res['url']);
        $this->assertEquals('200', $res['status']);
    }
    
    
    /**
    * @covers Favicon::info
    * @uses Favicon
    */
    public function testInfoRedirectLoop()
    {
        $dataAccess = $this->createMock('Favicon\DataAccess');
        $fav = new Favicon();
        $fav->setDataAccess($dataAccess);

        // Data
        $urlRedirect = 'http://redirected.domain.tld';
        $urlRedirect2 = 'http://redirected.domain.tld2';
        $url = 'http://domain.tld';
        $headerRedirect = [
            0 => 'HTTP/1.0 302 Found',
            'location' => [$urlRedirect, $urlRedirect2],
        ];

        // Redirect loop
        $dataAccess->expects($this->any())->method('retrieveHeader')->willReturn(
            $headerRedirect,
        );
        $res = $fav->info($url);
        $this->assertEquals($urlRedirect2, $res['url']);
        $this->assertEquals('302', $res['status']);
    }
    
    /**
    * @covers Favicon::info
    * @uses Favicon
    */
    public function testInfoRedirectMissingLocation()
    {
        $dataAccess = $this->createMock('Favicon\DataAccess');
        $fav = new Favicon();
        $fav->setDataAccess($dataAccess);

        // Data
        $url = 'http://domain.tld';
        $headerRedirect = [
            0 => 'HTTP/1.0 302 Found',
        ];

        // Redirect loop
        $dataAccess->expects($this->any())->method('retrieveHeader')->willReturn(
            $headerRedirect,
        );
        $res = $fav->info($url);
        $this->assertFalse($res);
    }

    /**
     * @covers Favicon::info
     * @uses Favicon
     */
    public function testInfoFalse()
    {
        $dataAccess = $this->createMock('Favicon\DataAccess');
        $fav = new Favicon();
        $fav->setDataAccess($dataAccess);
        $url = 'http://domain.tld';

        $dataAccess->expects($this->once())->method('retrieveHeader')->will($this->returnValue(null));
        $this->assertFalse($fav->info($url));
    }

    /**
    * @covers Favicon::get
    * @uses Favicon
    */
    public function testGetExistingFavicon()
    {
        $url = 'http://domain.tld/';
        $path = 'sub/';

        $fav = new Favicon(['url' => $url . $path]);

        // No cache
        $fav->cache(['dir' => $this->CACHE_TEST_DIR]);

        $dataAccess = $this->createMock('Favicon\DataAccess');
        $fav->setDataAccess($dataAccess);

        // Header MOCK
        $dataAccess
            ->expects($this->any())
            ->method('retrieveHeader')
            ->will($this->returnCallback([$this, 'headerExistingFav']));

        // Get from URL MOCK
        $dataAccess
            ->expects($this->any())
            ->method('retrieveUrl')
            ->will($this->returnCallback([$this, 'contentExistingFav']));
        $this->assertEquals(self::slash($url . $path) . self::TEST_LOGO_NAME, $fav->get());
    }
   
    /**
    * @covers Favicon::get
    * @uses Favicon
    */
    public function testGetExistingAbsoluteFavicon()
    {
        $url = 'http://domain.tld/';
        $path = 'sub/';

        $fav = new Favicon(['url' => $url . $path]);

        // No cache
        $fav->cache(['dir' => $this->CACHE_TEST_DIR]);

        $dataAccess = $this->createMock('Favicon\DataAccess');
        $fav->setDataAccess($dataAccess);

        // Header MOCK
        $dataAccess
            ->expects($this->any())
            ->method('retrieveHeader')
            ->will($this->returnCallback([$this, 'headerExistingFav']));

        // Get from URL MOCK
        $dataAccess
            ->expects($this->any())
            ->method('retrieveUrl')
            ->will($this->returnCallback([$this, 'contentExistingAbsoluteFav']));
        $this->assertEquals(self::slash($url) . self::TEST_LOGO_NAME, $fav->get());
    }

    /**
    * @covers Favicon::get
    * @uses Favicon
    */
    public function testGetOriginalFavicon()
    {
        $url = 'http://domain.tld/original';
        $logo = 'default.ico';
        $fav = new Favicon(['url' => $url]);

        // No cache
        $fav->cache(['dir' => $this->CACHE_TEST_DIR]);

        $dataAccess = $this->createMock('Favicon\DataAccess');
        $fav->setDataAccess($dataAccess);

        // Header MOCK
        $dataAccess
            ->expects($this->any())
            ->method('retrieveHeader')
            ->will($this->returnCallback([$this, 'headerOriginalFav']));

        // Get from URL MOCK
        $dataAccess
            ->expects($this->any())
            ->method('retrieveUrl')
            ->will($this->returnCallback([$this, 'contentOriginalFav']));
        $this->assertEquals(self::slash($url) . $logo, $fav->get());
    }

    /**
    * @covers Favicon::get
    * @uses Favicon
    */
    public function testGetDefaultFavicon()
    {
        $url = 'http://domain.tld/';
        $fav = new Favicon(['url' => $url]);

        // No cache
        $fav->cache(['dir' => $this->CACHE_TEST_DIR]);

        $dataAccess = $this->createMock('Favicon\DataAccess');
        $fav->setDataAccess($dataAccess);

        // Header MOCK
        $dataAccess
            ->expects($this->any())
            ->method('retrieveHeader')
            ->will($this->returnValue([0 => 'HTTP/1.1 200 KO']));
        $dataAccess
            ->expects($this->any())
            ->method('retrieveUrl')
            ->will($this->returnValue(file_get_contents($this->RESOURCE_FAV_ICO)));

        $this->assertEquals(self::slash($url) . self::DEFAULT_FAV_CHECK, $fav->get());
    }

    /**
    * @covers Favicon::get
    * @uses Favicon
    */
    public function testGetCachedFavicon()
    {
        $url = 'http://domaincache.tld/';
        $fav = new Favicon(['url' => $url]);

        // 30s
        $fav->cache(['timeout' => 30, 'dir' => $this->CACHE_TEST_DIR]);

        $dataAccess = $this->createPartialMock('Favicon\DataAccess', ['retrieveHeader', 'retrieveUrl']);
        $fav->setDataAccess($dataAccess);

        // Header MOCK
        $dataAccess
            ->expects($this->any())
            ->method('retrieveHeader')
            ->will($this->returnValue([0 => 'HTTP/1.1 200 OK']));
        $dataAccess
            ->expects($this->any())
            ->method('retrieveUrl')
            ->will($this->returnValue(file_get_contents($this->RESOURCE_FAV_ICO)));

        // Save default favicon in cache
        $fav->get();

        $fav = new Favicon(['url' => $url]);
        $fav->cache(['timeout' => 30, 'dir' => $this->CACHE_TEST_DIR]);
        $dataAccess = $this->createPartialMock('Favicon\DataAccess', ['retrieveHeader']);
        $fav->setDataAccess($dataAccess);
        $dataAccess
            ->expects($this->any())
            ->method('retrieveHeader')
            ->willReturnOnConsecutiveCalls(
                [0 => 'HTTP/1.1 404 KO'],
                '<head><crap></crap></head>'
            );

        $this->assertEquals(self::slash($url) . self::DEFAULT_FAV_CHECK, $fav->get());
    }

    /**
    * @covers Favicon::get
    * @uses Favicon
    */
    public function testGetFaviconEmptyUrl()
    {
        $fav = new Favicon();
        $this->assertFalse($fav->get());
    }

    /**
    * @covers Favicon::get
    * @uses Favicon
    */
    public function testGetNotFoundFavicon()
    {
        $url = 'http://domain.tld';
        $fav = new Favicon(['url' => $url]);
        // No cache
        $fav->cache(['dir' => $this->CACHE_TEST_DIR]);

        $dataAccess = $this->createMock('Favicon\DataAccess');
        $fav->setDataAccess($dataAccess);
        $dataAccess
            ->expects($this->any())
            ->method('retrieveHeader')
            ->will($this->returnValue([0 => 'HTTP/1.1 404 KO']));
        $dataAccess
            ->expects($this->any())
            ->method('retrieveUrl')
            ->will($this->returnValue('<head><crap></crap></head>'));

        $this->assertFalse($fav->get());
    }

    /**
    * @covers Favicon::get
    * @uses Favicon
    */
    public function testGetFalsePositive()
    {
        $url = 'http://domain.tld';
        $fav = new Favicon(['url' => $url]);
        // No cache
        $fav->cache(['dir' => $this->CACHE_TEST_DIR]);

        $dataAccess = $this->createMock('Favicon\DataAccess');
        $fav->setDataAccess($dataAccess);
        $dataAccess
            ->expects($this->any())
            ->method('retrieveHeader')
            ->will($this->returnValue([0 => 'HTTP/1.1 200 OK']));
        $dataAccess
            ->expects($this->any())
            ->method('retrieveUrl')
            ->will($this->returnValue('<head><crap></crap></head>'));

        $this->assertFalse($fav->get());
    }

    /**
    * @covers Favicon::get
    * @uses Favicon
    */
    public function testGetNoHtmlHeader()
    {
        $url = 'http://domain.tld/original';
        $fav = new Favicon(['url' => $url]);

        // No cache
        $fav->cache(['dir' => $this->CACHE_TEST_DIR]);

        $dataAccess = $this->createMock('Favicon\DataAccess');
        $fav->setDataAccess($dataAccess);

        // MOCK
        $dataAccess
            ->expects($this->any())
            ->method('retrieveHeader')
            ->will($this->returnValue([0 => 'HTTP/1.1 404 KO']));
        $dataAccess
            ->expects($this->any())
            ->method('retrieveUrl')
            ->will($this->returnValue('<crap></crap>'));

        $this->assertFalse($fav->get());
    }

    /**
    * @covers Favicon::get
    * @uses Favicon
    */
    public function testGetValidFavNoCacheSetup()
    {
        $url = 'http://domain.tld';
        $fav = new Favicon(['url' => $url]);

        $dataAccess = $this->createMock('Favicon\DataAccess');
        $fav->setDataAccess($dataAccess);

        // MOCK
        $dataAccess
            ->expects($this->any())
            ->method('retrieveHeader')
            ->will($this->returnValue([0 => 'HTTP/1.1 200 OK']));
        $dataAccess
            ->expects($this->any())
            ->method('retrieveUrl')
            ->will($this->returnValue(file_get_contents($this->RESOURCE_FAV_ICO)));

        $this->assertEquals(self::slash($url) . self::DEFAULT_FAV_CHECK, $fav->get());
        directory_clear(__DIR__ . '/../../resources/cache');
        touch(__DIR__ . '/../../resources/cache/.gitkeep');
    }

    public function testGetDownloadedFavPath()
    {
        $url = 'http://domain.tld';
        $fav = new Favicon(['url' => $url]);
        $fav->cache([
            'dir' => $this->CACHE_TEST_DIR
        ]);

        $dataAccess = $this->createMock('Favicon\DataAccess');
        $fav->setDataAccess($dataAccess);

        // MOCK
        $dataAccess
            ->expects($this->any())
            ->method('retrieveHeader')
            ->will($this->returnValue([0 => 'HTTP/1.1 200 OK']));
        $dataAccess
            ->expects($this->any())
            ->method('retrieveUrl')
            ->will($this->returnValue(file_get_contents($this->RESOURCE_FAV_ICO)));

        $expected = 'img' . md5('http://domain.tld');
        $this->assertEquals($expected, $fav->get('', FaviconDLType::DL_FILE_PATH));
    }

    public function testGetRawImageFav()
    {
        $url = 'http://domain.tld';
        $fav = new Favicon(['url' => $url]);
        $fav->cache([
            'dir' => $this->CACHE_TEST_DIR
        ]);

        $dataAccess = $this->createMock('Favicon\DataAccess');
        $fav->setDataAccess($dataAccess);

        // MOCK
        $dataAccess
            ->expects($this->any())
            ->method('retrieveHeader')
            ->will($this->returnValue([0 => 'HTTP/1.1 200 OK']));
        $dataAccess
            ->expects($this->any())
            ->method('retrieveUrl')
            ->will($this->returnValue(file_get_contents($this->RESOURCE_FAV_ICO)));

        $expected = file_get_contents(self::RESOURCES . '/' . self::TEST_LOGO_NAME);
        $this->assertEquals($expected, $fav->get('', FaviconDLType::RAW_IMAGE));
    }

    /**
     * Callback function for retrieveHeader in testGetExistingRootFavicon
     * If it checks default fav (favicon.ico), return 404
     * Return 200 while checking existing favicon
     **/
    public function headerExistingFav()
    {
        $headerOk = [0 => 'HTTP/1.1 200 OK'];
        $headerKo = [0 => 'HTTP/1.1 404 KO'];
        $args = func_get_args();

        if (strpos($args[0], self::DEFAULT_FAV_CHECK) !== false) {
            return $headerKo;
        }
        return $headerOk;
    }

    /**
     * Callback function for contentExistingFav in testGetExistingRootFavicon
     * return valid header, or icon file content if url contain '.ico'.
     * Return 200 while checking existing favicon
     **/
    public function contentExistingFav()
    {
        $xml = '<head><link rel="icon" href="' . self::TEST_LOGO_NAME . '" /></head>';
        $ico = file_get_contents($this->RESOURCE_FAV_ICO);
        $args = func_get_args();

        if (strpos($args[0], '.ico') !== false) {
            return $ico;
        }
        return $xml;
    }
    
    /**
     * Callback function for contentExistingFav in testGetExistingRootFavicon
     * return valid header, or icon file content if url contain '.ico'.
     * Return 200 while checking existing favicon
     **/
    public function contentExistingAbsoluteFav()
    {
        $xml = '<head><link rel="icon" href="/' . self::TEST_LOGO_NAME . '" /></head>';
        $ico = file_get_contents($this->RESOURCE_FAV_ICO);
        $args = func_get_args();

        if (strpos($args[0], '.ico') !== false) {
            return $ico;
        }
        return $xml;
    }

    /**
     * Callback function for retrieveHeader in testGetOriginalFavicon
     * If it checks default fav (favicon.ico), return 404
     * Also return 404 if not testing original webdir (original/)
     * Return 200 while checking existing favicon in web subdir
     **/
    public function headerOriginalFav()
    {
        $headerOk = [0 => 'HTTP/1.1 200 OK'];
        $headerKo = [0 => 'HTTP/1.1 404 KO'];
        $args = func_get_args();

        if (strpos($args[0], 'original') === false || strpos($args[0], self::DEFAULT_FAV_CHECK) !== false) {
            return $headerKo;
        }

        return $headerOk;
    }

    /**
     * Callback function for retrieveUrl in testGetOriginalFavicon
     * Return crap if it we're not in web sub directory
     * Return proper <head> otherwise
     * Return img for final check
     **/
    public function contentOriginalFav()
    {
        $logo = 'default.ico';
        $xmlOk = '<head><link rel="icon" href="' . $logo . '" /></head>';
        $xmlKo = '<head><crap></crap></head>';
        $ico = file_get_contents($this->RESOURCE_FAV_ICO);
        $args = func_get_args();

        if (strpos($args[0], '.ico') !== false) {
            return $ico;
        }
        if (strpos($args[0], 'original') === false) {
            return $xmlKo;
        }

        return $xmlOk;
    }

    public static function slash($url)
    {
        return $url . ($url[strlen($url) - 1] == '/' ? '' : '/');
    }
}
