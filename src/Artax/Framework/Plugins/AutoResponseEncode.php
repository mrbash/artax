<?php
/**
 * AutoResponseEncode Class File
 * 
 * @category    Artax
 * @package     Framework
 * @subpackage  Plugins
 * @author      Daniel Lowrey <rdlowrey@gmail.com>
 * @license     All code subject to the terms of the LICENSE file in the project root
 * @version     ${project.version}
 */
namespace Artax\Framework\Plugins;

use InvalidArgumentException,
    Artax\Http\Request,
    Artax\Http\Response,
    Artax\MediaRangeFactory,
    Artax\MimeTypeFactory,
    Artax\Encoding\CodecFactory,
    Artax\Framework\Config\Config;

/**
 * Automatically encodes response body for supported Content-Encodings and Content-Types
 * 
 * @category    Artax
 * @package     Framework
 * @subpackage  Plugins
 * @author      Daniel Lowrey <rdlowrey@gmail.com>
 */
class AutoResponseEncode {
    
    /**
     * The request instance is needed to maintain compatibility with very old user-agents
     * @var Request
     */
    private $request;
    
    /**
     * @var MediaRangeFactory
     */
    private $mediaRangeFactory;
    
    /**
     * @var MimeTypeFactory
     */
    private $mimeTypeFactory;
    
    /**
     * @var CodecFactory
     */
    private $codecFactory;
    
    /**
     * @var array
     */
    private $encodableMediaRanges;
    
    /**
     * @var array
     */
    private $availableCodecs = array('gzip', 'deflate');
    
    /**
     * @param Request $request
     * @param MediaRangeFactory $mediaRangeFactory
     * @param MimeTypeFactory $mimeTypeFactory
     * @param CodecFactory $codecFactory
     * @param Config $config
     * @return void
     */
    public function __construct(
        Request $request,
        MediaRangeFactory $mediaRangeFactory,
        MimeTypeFactory $mimeTypeFactory,
        CodecFactory $codecFactory,
        Config $config
    ) {
        $this->request = $request;
        $this->mediaRangeFactory = $mediaRangeFactory;
        $this->mimeTypeFactory = $mimeTypeFactory;
        $this->codecFactory = $codecFactory;
        
        $this->setEncodableMediaRanges($config);
    }
    
    /**
     * @param string $arrayOfMediaRangeStrings
     * @return void
     */
    private function setEncodableMediaRanges(Config $config) {
        $defaults = array('text/*', 'application/json', 'application/xml');
        $userSpecified = null;
        
        if ($cfg->has('autoResponseEncodeTypes')) {
            $userSpecified = array_map('trim', explode(',', $cfg->get('autoResponseEncodeTypes')));
        }
        
        $arrayOfMediaRangeStrings = $userSpecified ?: $defaults;
        
        $this->encodableMediaRanges = array_map(function($term) {
            return $this->mediaRangeFactory->make($term);
        }, $arrayOfMediaRangeStrings);
    }
    
    /**
     * @param Response $response
     * @return void
     */
    public function __invoke(Response $respone) {
        $this->encodeResponseBody($response);
    }
    
    /**
     * @param Response $response
     * @return void
     */
    public function encodeResponseBody(Response $response) {
        if (!$response->hasHeader('Content-Type')
            || !$response->hasHeader('Content-Encoding')
        ) {
            return;
        }
        
        $encoding = strtolower($response->getHeader('Content-Encoding'));
        if (!in_array($encoding, $this->availableCodecs)) {
            return;
        }
        
        if (!$contentType = $this->getEncodableContentType($response)) {
            return;
        }
        
        // Account for browsers that lie about encodings they can handle
        if (!$this->accountForBrowserQuirks($contentType, $encoding)) {
            $response->removeHeader('Content-Encoding');
            return;
        }
        
        $codec = $this->codecFactory->make($encoding);
        $encoded = $codec->encode($response->getBody());
        $response->setHeader('Content-Length', strlen($encoded));
        $response->setBody($encoded);
        
        // rfc-rfc2616-sec14.44:
        // "An HTTP/1.1 server SHOULD include a Vary header field with any cacheable 
        // response that is subject to server-driven negotiation."
        $this->setVaryHeader($response);
    }
    
    /**
     * @param Response $response
     * @return bool
     */
    private function getEncodableContentType(Response $response) {
        $rawHeader = $response->getHeader('Content-Type');
        if (strpos($rawHeader, ';')) {
            $parts = explode(';', $rawHeader);
            $contentType = trim($parts[0]);
        } else {
            $contentType = $rawHeader;
        }
        
        try {
            $mimeType = $this->mimeTypeFactory->make($contentType);
        } catch (InvalidArgumentException $e) {
            return false;
        }
        
        foreach ($this->encodableMediaRanges as $mediaRange) {
            if ($mediaRange->matches($mimeType)) {
                return $mimeType;
            }
        }
        
        return false;
    }
    
    /**
     * @param string $mimeContentType
     * @param string $encoding
     * @return bool
     */
    private function accountForBrowserQuirks($contentType, $encoding) {
        if (!$this->request->hasHeader('User-Agent')) {
            return true;
        }
        
        $userAgent = $this->request->getHeader('User-Agent');
        
        // Workaround for IE bug: http://support.microsoft.com/kb/323308
        if (preg_match('/MSIE\s*([5678])?/', $userAgent, $match)) {
            if (isset($match[1])
                && $this->request->getUriComponent('scheme') == 'https'
                && $this->request->hasHeader('Cache-Control')
            ) {
                $cacheControl = strtolower($this->request->getHeader('Cache-Control'));
                return (!($cacheControl == 'no-store' || $cacheControl == 'no-cache'));
            }
            return true;
        }
        
        // Netscape 4.x has problems ... especially 4.06-4.08
        if (preg_match('{Mozilla/4(?:\.0([678]))?}', $userAgent, $match)) {
            if ($contentType != 'text/html' || $encoding !== 'gzip' || isset($match[1])) {
                return false;
            }
        }
    }
    
    /**
     * @param Response $response
     */
    private function setVaryHeader(Response $response) {
        $varyHeader = $response->hasHeader('Vary')
            ? $response->getHeader('Vary') . ',User-Agent'
            : 'Accept-Encoding,User-Agent';
        
        $response->setHeader('Vary', $varyHeader);
    }
}