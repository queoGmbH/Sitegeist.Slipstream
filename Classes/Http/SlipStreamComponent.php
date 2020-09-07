<?php
namespace Sitegeist\Slipstream\Http;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Http\Component\ComponentContext;
use Neos\Flow\Http\Component\ComponentInterface;
use function GuzzleHttp\Psr7\stream_for;

class SlipStreamComponent implements ComponentInterface
{

    /**
     * @var bool
     * @Flow\InjectConfiguration(path="debugMode")
     */
    protected $debugMode;

    /**
     * Just call makeStandardsCompliant on the Response for now
     *
     * @param ComponentContext $componentContext
     * @return void
     */
    public function handle(ComponentContext $componentContext)
    {
        $response = $componentContext->getHttpResponse();

        if ($response->getHeaderLine('X-Slipstream') !== 'enabled') {
            return;
        }

        $html = $response->getBody()->getContents();

        // detect xml declaration
        $hasXmlDeclaration = (substr($html, 0, 5) === '<?xml');

        // ignore xml parsing errors
        $useInternalErrorsBackup = libxml_use_internal_errors(true);

        $domDocument = new \DOMDocument('1.0', 'UTF-8');
        $success = $domDocument->loadHTML($hasXmlDeclaration ? $html : '<?xml encoding="UTF-8"?>' . $html);

        // in case of parsing errors return original body
        if (!$success) {
            $response->getBody()->rewind();
            return;
        }

        $xPath = new \DOMXPath($domDocument);

        $sourceNodes = $xPath->query("//*[@data-slipstream]");
        $nodesByTargetAndContentHash = [];
        foreach ($sourceNodes as $node) {
            /**
             * @var \DOMNode $node
             */
            $content = $domDocument->saveHTML($node);
            $target = $node->getAttribute('data-slipstream');
            if (empty($target)) {
                $target = '//head';
            }
            $contentHash = md5($content);
            $nodesByTargetAndContentHash[$target][$contentHash] = $node->cloneNode(true);

            // in debug mode leave a comment behind
            if ($this->debugMode) {
                $comment = $domDocument->createComment(' ' . $content . ' ') ;
                $node->parentNode->insertBefore($comment, $node);
            }

            $node->parentNode->removeChild($node);
        }

        foreach ($nodesByTargetAndContentHash as $targetPath => $nodes){
            $query =$xPath->query($targetPath);
            if ($query && $query->count()) {
                $targetNode = $query->item(0);
                if ($this->debugMode) {
                    $targetNode->appendChild($domDocument->createComment(' slipstream-for: ' . $targetPath . ' begin '));
                }
                foreach ($nodes as $anchorNode) {
                    $targetNode->appendChild($anchorNode);
                }
                if ($this->debugMode) {
                    $targetNode->appendChild($domDocument->createComment(' slipstream-for: ' . $targetPath . ' end '));
                }
            }
        }

        if ($hasXmlDeclaration) {
            $alteredBody = $domDocument->saveHTML();
        } else {
            $alteredBody = $domDocument->saveHTML($domDocument->documentElement);
        }

        $response = $response->withBody(stream_for($alteredBody));
        if (!$this->debugMode) {
            $response = $response->withoutHeader('X-Slipstream-Enabled');
        }

        $componentContext->replaceHttpResponse($response);

        // restore previous parsing behavior
        if ($useInternalErrorsBackup !== true) {
            libxml_use_internal_errors($useInternalErrorsBackup);
        }
    }
}
