<?php

namespace ProtoneMedia\LaravelFFMpeg;

use DOMDocument;
use DOMElement;
use DOMXPath;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;

/** @internal The deliberately narrow, finite FFmpeg SegmentTimeline dialect we own. */
class DashManifest
{
    public const string NS = 'urn:mpeg:dash:schema:mpd:2011';

    public const int LIMIT = 100000;

    public static function read(string $path, string $type): array
    {
        $document = self::load($path);
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('d', self::NS);

        self::ensureVocabularyIsAllowed($document);
        [$representation, $template] = self::locateRepresentation($document, $xpath, $type);
        $durations = self::durations($xpath, $template);

        $files = ['init.mp4'];
        foreach ($durations as $index => $duration) {
            $files[] = sprintf('segment-%08d.m4s', $index + 1);
        }

        self::ensureInventoryMatches($path, $files);

        return ['document' => $document, 'representation' => $representation, 'template' => $template, 'durations' => $durations, 'files' => $files];
    }

    /**
     * Load the manifest without entities, doctypes or network access.
     */
    protected static function load(string $path): DOMDocument
    {
        $bytes = @file_get_contents($path, length: 16777217);
        if ($bytes === false || strlen($bytes) > 16777216 || str_contains($bytes, "\0")
            || preg_match('/<!|<\?(?!xml\s)/i', $bytes) || ! class_exists(DOMDocument::class)) {
            self::fail();
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            if (! $document->loadXML($bytes, LIBXML_NONET) || $document->doctype instanceof \DOMDocumentType) {
                self::fail();
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $document;
    }

    /**
     * Ensure the manifest only uses the elements and attributes FFmpeg writes.
     */
    protected static function ensureVocabularyIsAllowed(DOMDocument $document): void
    {
        $allowed = [
            'MPD' => ['schemaLocation', 'profiles', 'type', 'mediaPresentationDuration', 'maxSegmentDuration', 'minBufferTime'],
            'ProgramInformation' => [], 'ServiceDescription' => ['id'], 'Period' => ['id', 'start'],
            'AdaptationSet' => ['id', 'contentType', 'startWithSAP', 'segmentAlignment', 'bitstreamSwitching', 'frameRate', 'maxWidth', 'maxHeight', 'par', 'lang'],
            'Representation' => ['id', 'mimeType', 'codecs', 'bandwidth', 'width', 'height', 'sar', 'audioSamplingRate', 'frameRate'],
            'AudioChannelConfiguration' => ['schemeIdUri', 'value'],
            'SegmentTemplate' => ['timescale', 'initialization', 'media', 'startNumber'],
            'SegmentTimeline' => [], 'S' => ['t', 'd', 'r'],
        ];
        foreach ($document->getElementsByTagName('*') as $element) {
            if ($element->namespaceURI !== self::NS || ! isset($allowed[$element->localName])) {
                self::fail();
            }

            foreach ($element->attributes as $attribute) {
                if (! in_array($attribute->localName, $allowed[$element->localName], true)
                    || ($attribute->namespaceURI !== null && ! ($element->localName === 'MPD'
                        && $attribute->localName === 'schemaLocation' && $attribute->namespaceURI === 'http://www.w3.org/2001/XMLSchema-instance'))) {
                    self::fail();
                }
            }
        }
    }

    /**
     * Locate the single representation and its segment template in the static manifest.
     *
     * @return array{0: DOMElement, 1: DOMElement}
     */
    protected static function locateRepresentation(DOMDocument $document, DOMXPath $xpath, string $type): array
    {
        $root = $document->documentElement;
        $sets = $xpath->query('/d:MPD/d:Period/d:AdaptationSet');
        $representations = $xpath->query('//d:Representation');
        $templates = $xpath->query('//d:SegmentTemplate');
        $set = $sets->item(0);
        $representation = $representations->item(0);
        $template = $templates->item(0);
        if (! $root instanceof DOMElement || ! $set instanceof DOMElement || ! $representation instanceof DOMElement || ! $template instanceof DOMElement
            || $root->localName !== 'MPD' || $root->getAttribute('type') !== 'static'
            || ! preg_match('/\APT[0-9]+(?:\.[0-9]+)?S\z/', $root->getAttribute('mediaPresentationDuration'))
            || $xpath->query('/d:MPD/d:Period')->length !== 1 || $sets->length !== 1
            || $set->getAttribute('contentType') !== $type || $representations->length !== 1 || $templates->length !== 1) {
            self::fail();
        }

        if ($representation->parentNode !== $set || $template->parentNode !== $representation
            || $representation->getAttribute('id') !== '0' || $representation->getAttribute('mimeType') !== $type.'/mp4'
            || ! preg_match('/\A[a-zA-Z0-9.,]+\z/', $representation->getAttribute('codecs'))
            || self::integer($representation, 'bandwidth', 1) <= 0
            || $template->getAttribute('initialization') !== 'init.mp4'
            || $template->getAttribute('media') !== 'segment-$Number%08d$.m4s'
            || $template->getAttribute('startNumber') !== '1'
            || $xpath->query('//d:SegmentTimeline')->length !== 1
            || $xpath->query('d:SegmentTimeline', $template)->length !== 1) {
            self::fail();
        }

        return [$representation, $template];
    }

    /**
     * Expand the segment timeline into the duration of each segment in seconds.
     *
     * @return list<float|int>
     */
    protected static function durations(DOMXPath $xpath, DOMElement $template): array
    {
        $scale = self::integer($template, 'timescale', 1);
        $durations = [];
        $time = 0;
        foreach ($xpath->query('d:SegmentTimeline/d:S', $template) as $segment) {
            if (! $segment instanceof DOMElement) {
                self::fail();
            }

            $duration = self::integer($segment, 'd', 1);
            $repeat = $segment->hasAttribute('r') ? self::integer($segment, 'r', 0) : 0;
            if (($segment->hasAttribute('t') && self::integer($segment, 't', 0) !== $time)
                || $repeat >= self::LIMIT || count($durations) + $repeat + 1 > self::LIMIT) {
                self::fail();
            }

            for ($i = 0; $i <= $repeat; $i++) {
                $durations[] = $duration / $scale;
                $time += $duration;
                if ($time > 1000000000000000) {
                    self::fail();
                }
            }
        }

        if ($durations === []) {
            self::fail();
        }

        return $durations;
    }

    /**
     * Ensure the manifest's directory holds exactly the manifest and the files it references.
     *
     * @param  list<string>  $files
     */
    protected static function ensureInventoryMatches(string $path, array $files): void
    {
        $expected = ['index.mpd', ...$files];
        sort($expected);
        $actual = [];
        foreach (new \DirectoryIterator(dirname($path)) as $file) {
            if ($file->isDot()) {
                continue;
            }

            if ($file->isLink() || ! $file->isFile() || $file->getSize() <= 0 || count($actual) >= self::LIMIT + 2) {
                self::fail();
            }

            $actual[] = $file->getFilename();
        }

        sort($actual);
        if ($actual !== $expected) {
            self::fail();
        }
    }

    protected static function integer(DOMElement $element, string $name, int $minimum): int
    {
        $value = $element->getAttribute($name);
        if (! preg_match('/\A[0-9]{1,13}\z/', $value) || (int) $value < $minimum || (int) $value > 1000000000000) {
            self::fail();
        }

        return (int) $value;
    }

    public static function fail(): never
    {
        throw MediaException::validation('Invalid or incomplete finite DASH manifest/inventory.', 'artifact');
    }
}
